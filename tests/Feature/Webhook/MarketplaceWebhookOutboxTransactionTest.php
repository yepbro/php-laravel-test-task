<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Jobs\SendOrderNotification;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MutatedWebhookBody;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-019. Транзакционный outbox (FR-MAIL-01): запись order_notifications
 * создаётся в одной транзакции с изменением заказа (rollback уносит и
 * заказ, и outbox, и журнал), постановка SendOrderNotification
 * происходит по применённому событию, письмо не отправляется в
 * HTTP-запросе (NFR-01: никаких сетевых вызовов в запросе — только
 * очередь), при отказе до применения (422) постановки нет.
 *
 * «Отсутствие постановки ДО commit» пиннится отдельным набором
 * MarketplaceWebhookOutboxCommitTest (реальный queue-драйвер, коммит
 * корневой транзакции самим тестом — см. DECISIONS задачи 019:
 * Queue::fake() не транзакционно-осведомлён, а под RefreshDatabase
 * корневая транзакция никогда не коммитится).
 *
 * Красная фаза: транзакционное применение — заглушки T-026→T-032;
 * позитивные тесты падают на первом утверждении о будущем поведении
 * (200 processed / состояние БД) и зеленеют с реализацией без правки.
 * Негативный тест (422 без постановки) зелёный с T-024: подписанный
 * запрос проходит HMAC и получает честный 422 от парсера (T-021).
 */
class MarketplaceWebhookOutboxTransactionTest extends TestCase
{
    use PostsSignedWebhooks;
    use RefreshDatabase;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    private const string ORDER_ID = 'ord_7Q2KX9M4';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
            'app.debug' => false,
        ]);

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * FR-MAIL-01: заказ, outbox-запись и журнал событий живут в одной
     * транзакции — откат её не оставляет «осиротевшей» outbox-записи:
     * после rollBack пусты все три таблицы. Если бы уведомление
     * создавалось вне транзакции применения снимка, rollback оставил бы
     * запись — тест бы упал.
     */
    public function test_rollback_discards_order_and_outbox_together(): void
    {
        DB::beginTransaction();

        try {
            $response = $this->postSignedWebhook(SignedWebhook::forExample('order-paid'));

            $response->assertOk();
            $this->assertSame(['status' => 'processed'], $response->json());

            // Внутри транзакции видны и изменение заказа, и outbox-запись.
            $this->assertDatabaseHas('orders', ['id' => self::ORDER_ID]);
            $this->assertDatabaseHas('order_notifications', [
                'order_id' => self::ORDER_ID,
                'type' => 'paid',
                'status' => 'pending',
            ]);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('orders', 0, 'Откат транзакции уносит заказ.');
        $this->assertDatabaseCount('order_notifications', 0, '…и outbox-запись — без сирот.');
        $this->assertDatabaseCount('webhook_events', 0, '…и журнал событий.');
    }

    /**
     * Применённое событие ставит ровно одно задание отправки с
     * идентификатором созданной записи (шаг 14 §7), а само письмо в
     * запросе не отправляется — только через очередь (NFR-01).
     */
    public function test_applied_event_dispatches_single_job_and_no_letter_in_request(): void
    {
        // Фейк только задания отправки: неожиданная другая постановка
        // не спрячется в безадресном фейке, а пойдёт в реальный
        // очередь-менеджер и выполнится (sync-драйвер) — тест это
        // заметит по состоянию/ответу.
        Queue::fake([SendOrderNotification::class]);
        Mail::fake();

        $response = $this->postSignedWebhook(SignedWebhook::forExample('order-paid'));

        $this->assertSame(['status' => 'processed'], $response->json());

        $notification = OrderNotification::query()
            ->where('order_id', self::ORDER_ID)
            ->sole();

        Queue::assertPushedOnce(SendOrderNotification::class);
        Queue::assertPushed(
            SendOrderNotification::class,
            static fn(SendOrderNotification $job): bool => $job->notificationId === $notification->id,
        );
        // Письмо уходит заданием очереди, не HTTP-запросом (NFR-01);
        // у assertNothingSent() нет параметра сообщения — пояснение здесь.
        Mail::assertNothingSent();
    }

    /**
     * Событие, отклонённое до применения (422 — некорректный снимок),
     * не порождает постановки отправки.
     */
    public function test_invalid_payload_does_not_dispatch_notification_job(): void
    {
        Queue::fake();

        $body = MutatedWebhookBody::fromExample('order-paid')
            ->withPath(['data', 'order', 'status'], null)
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $response->assertUnprocessable();
        Queue::assertNotPushed(SendOrderNotification::class);
    }
}
