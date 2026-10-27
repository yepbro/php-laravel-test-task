<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Webhooks\ProcessMarketplaceEvent;
use App\Data\IncomingWebhook;
use App\Enums\EventOutcome;
use App\Enums\PayloadIssue;
use App\Enums\WebhookProcessingResult;
use App\Exceptions\Webhook\MalformedPayload;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MarketplaceExamples;
use Tests\Support\MutatedWebhookBody;
use Tests\TestCase;

/**
 * ProcessMarketplaceEvent (T-010/T-021/T-026): маршрутизация по данным
 * конверта и журналирование событий. Валидное событие известного типа
 * доходит до вставки журнала и падает заглушкой применения (T-027–T-032)
 * внутри той же транзакции — фиктивный `200 processed` невозможен
 * (правило этапа 2); неизвестный тип/статус журналируются как ignored
 * (шаги 8/10 §7, T-026); невалидное тело отбрасывается парсером до
 * журналирования (MalformedPayload, 400/422).
 *
 * С T-026 Action пишет в БД, поэтому набор использует RefreshDatabase
 * (правило «DB-тесты — только с DB-трейтами»); поля записи журнала
 * (payload байт в байт, хеш, signature timestamp, метки доставок) —
 * здесь, полный HTTP-контракт тех же веток — feature-наборы T-017
 * (IgnoredEvents/DuplicateDelivery, зелёные с T-026).
 */
class ProcessMarketplaceEventTest extends TestCase
{
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    private const string PAID_EVENT_ID = 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC';

    private const string UNKNOWN_TYPE_EVENT_ID = 'evt_01JBUNKNOWNTYPE000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    public function test_action_is_resolvable_from_container(): void
    {
        $this->assertInstanceOf(
            ProcessMarketplaceEvent::class,
            $this->app->make(ProcessMarketplaceEvent::class),
        );
    }

    /**
     * Валидное событие известного типа с корректным снимком проходит
     * разбор (T-021), вставляется в журнал (13a §7) и упирается в
     * заглушку применения (шаги 13b–13g, T-027–T-032). Вставка и
     * применение — одна транзакция (FR-ORD-01): откат заглушки уносит
     * и запись журнала.
     */
    public function test_known_type_valid_snapshot_reaches_application_stub(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        try {
            $action->handle($this->paidWebhook());

            $this->fail('Ожидалась LogicException заглушки применения (T-027).');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('T-027', $exception->getMessage());
        }

        // Вставка журнала и применение — одна транзакция (FR-ORD-01).
        $this->assertDatabaseCount('webhook_events', 0);
    }

    /**
     * Неизвестный тип (шаг 8 §7): 200 ignored, событие сохранено с
     * order_id = NULL и исходом ignored; payload — сырые байты тела
     * байт в байт, включая финальный перевод строки (FR-ORD-08, T1),
     * payload hash — от того же тела; deliveries=1, момент приёма
     * зафиксирован, last_received_at ещё нет.
     */
    public function test_unknown_type_is_journaled_as_ignored(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $rawBody = '{"id":"' . self::UNKNOWN_TYPE_EVENT_ID . '","type":"order.refunded",'
            . '"created_at":"2026-10-21T10:05:48+00:00","data":{}}' . "\n";

        $result = $action->handle(new IncomingWebhook(
            webhookId: self::UNKNOWN_TYPE_EVENT_ID,
            signatureTimestamp: 1792577149,
            rawBody: $rawBody,
        ));

        $this->assertSame(WebhookProcessingResult::IGNORED, $result);

        $event = WebhookEvent::query()->findOrFail(self::UNKNOWN_TYPE_EVENT_ID);
        $this->assertSame('order.refunded', $event->type);
        $this->assertNull($event->order_id, 'data.order для неизвестного типа не читается (шаг 8 §7).');
        $this->assertSame($rawBody, $event->payload, 'Сырое тело хранится байт в байт, включая финальный перевод строки.');
        $this->assertSame(hash('sha256', $rawBody), $event->payload_sha256);
        $this->assertSame(1792577149, $event->signature_timestamp);
        $this->assertSame(EventOutcome::IGNORED, $event->outcome);
        $this->assertSame(1, $event->deliveries);
        $this->assertSame(
            CarbonImmutable::parse(self::FROZEN_NOW)->format('Y-m-d H:i:s'),
            $event->received_at->format('Y-m-d H:i:s'),
        );
        $this->assertNull($event->last_received_at, 'last_received_at появляется только у повторной доставки.');
        $this->assertNotNull($event->processed_at, 'ignored — конечный исход, обработка завершена на вставке.');
    }

    /**
     * Неизвестный строковый статус у известного типа (шаг 10 §7, S-36):
     * 200 ignored, событие сохранено с order_id из конверта — отдельная
     * от unknown type ветка маршрутизации (ревью r2, P3).
     */
    public function test_unknown_status_is_journaled_as_ignored_with_order_id(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $result = $action->handle(new IncomingWebhook(
            webhookId: 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            signatureTimestamp: 1792577149,
            rawBody: MutatedWebhookBody::fromExample('order-updated')
                ->withStatus('returned')
                ->body(),
        ));

        $this->assertSame(WebhookProcessingResult::IGNORED, $result);

        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            'type' => 'order.updated',
            'order_id' => 'ord_7Q2KX9M4',
            'outcome' => EventOutcome::IGNORED->value,
            'deliveries' => 1,
        ]);
    }

    /**
     * Повторная доставка события неизвестного типа (S-02, общая
     * duplicate-ветка шага 8 §7): 200 duplicate; deliveries=2,
     * last_received_at обновлён; исходные payload/payload_sha256/
     * outcome/signature_timestamp не перезаписаны — «duplicate» только
     * HTTP-ответ (FR-ORD-01/08).
     */
    public function test_unknown_type_redelivery_is_duplicate_and_keeps_original_row(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $originalBody = '{"id":"' . self::UNKNOWN_TYPE_EVENT_ID . '","type":"order.refunded",'
            . '"created_at":"2026-10-21T10:05:48+00:00","data":{}}';

        $this->assertSame(
            WebhookProcessingResult::IGNORED,
            $action->handle(new IncomingWebhook(
                webhookId: self::UNKNOWN_TYPE_EVENT_ID,
                signatureTimestamp: 1792577149,
                rawBody: $originalBody,
            )),
        );

        // Повтор: новый timestamp подписи и изменённое тело (S-02).
        $alteredBody = '{"id":"' . self::UNKNOWN_TYPE_EVENT_ID . '","type":"order.refunded",'
            . '"created_at":"2026-10-21T10:05:48+00:00","data":{"reason":"rerun"}}';

        $result = $action->handle(new IncomingWebhook(
            webhookId: self::UNKNOWN_TYPE_EVENT_ID,
            signatureTimestamp: 1792577156,
            rawBody: $alteredBody,
        ));

        $this->assertSame(WebhookProcessingResult::DUPLICATE, $result);

        $event = WebhookEvent::query()->findOrFail(self::UNKNOWN_TYPE_EVENT_ID);
        $this->assertSame($originalBody, $event->payload, 'Исходное тело дублем не перезаписывается.');
        $this->assertSame(hash('sha256', $originalBody), $event->payload_sha256);
        $this->assertSame(EventOutcome::IGNORED, $event->outcome);
        $this->assertSame(1792577149, $event->signature_timestamp, 'Timestamp первой доставки сохранён.');
        $this->assertSame(2, $event->deliveries);
        $this->assertNotNull($event->last_received_at);
        // Один event.id — одна строка журнала.
        $this->assertDatabaseCount('webhook_events', 1);
    }

    /**
     * Невалидное тело отбрасывается парсером до журналирования:
     * MalformedPayload с типизированной причиной (FR-WH-05/06/08).
     */
    public function test_invalid_body_is_rejected_by_parser_before_journal(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        try {
            $action->handle(new IncomingWebhook(
                webhookId: self::PAID_EVENT_ID,
                signatureTimestamp: 1792577149,
                rawBody: '{"id":"' . self::PAID_EVENT_ID . '","type":"order.paid"}',
            ));

            $this->fail('Ожидался MalformedPayload(InvalidEnvelope).');
        } catch (MalformedPayload $exception) {
            $this->assertSame(PayloadIssue::InvalidEnvelope, $exception->issue);
        }

        // Невалидные тела в журнал не попадают (FR-ORD-08).
        $this->assertDatabaseCount('webhook_events', 0);
    }

    /** Контекст доставки: пример order-paid, подписанный идентификатор события из тела. */
    private function paidWebhook(): IncomingWebhook
    {
        return new IncomingWebhook(
            webhookId: self::PAID_EVENT_ID,
            signatureTimestamp: 1792577149,
            rawBody: MarketplaceExamples::raw('order-paid'),
        );
    }
}
