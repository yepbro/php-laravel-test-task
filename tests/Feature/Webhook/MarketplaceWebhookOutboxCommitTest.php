<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Jobs\SendOrderNotification;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-019. «Отсутствие постановки до commit» (FR-MAIL-01, afterCommit)
 * на сквозном уровне: реальный queue-драйвер `database`, корневую
 * транзакцию открывает и коммитит сам тест.
 *
 * Единственный набор на DatabaseMigrations вместо RefreshDatabase —
 * осознанно: RefreshDatabase оборачивает тест в корневую транзакцию,
 * которая никогда не коммитится, поэтому отложенные afterCommit-колбэки
 * не исполнить внутри теста (а Queue::fake() пишет сразу, минуя
 * менеджер транзакций, — фейком «до commit» проверить нельзя;
 * DEV-обоснование — DECISIONS-log задачи 019). Здесь транзакция теста —
 * корневая: внутри неё обработки «коммитит» свою вложенную, но
 * afterCommit-постановка ждёт корня — и срабатывает сразу после него.
 *
 * Красная фаза: применение реализовано (T-028) — `processed`
 * честный; тест падает на отсутствии outbox-записи/постановки
 * (T-032) и зеленеет с реализацией без правки теста.
 */
class MarketplaceWebhookOutboxCommitTest extends TestCase
{
    use PostsSignedWebhooks;
    use DatabaseMigrations;

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
     * FR-MAIL-01: задание появляется в очереди строго после коммита
     * транзакции, создавшей outbox-запись, — до коммита очереди пусто;
     * после коммита стоит ровно одно задание SendOrderNotification.
     */
    public function test_notification_job_is_queued_only_after_commit(): void
    {
        config(['queue.default' => 'database']);

        DB::transaction(function (): void {
            $response = $this->postSignedWebhook(SignedWebhook::forExample('order-paid'));

            $this->assertSame(['status' => 'processed'], $response->json());

            // Запись уже создана (внутри этой же транзакции)…
            $notification = OrderNotification::query()
                ->where('order_id', self::ORDER_ID)
                ->sole();

            // …а постановки ещё нет: afterCommit корневой транзакции
            // не наступил. Немедленная постановка упала бы здесь.
            $this->assertSame(
                0,
                DB::table('jobs')->count(),
                'Задание не ставится до commit транзакции применения (FR-MAIL-01).',
            );
        });

        $jobs = DB::table('jobs')->get(['payload']);
        $this->assertCount(1, $jobs, 'После коммита задание отправки поставлено.');

        // Механическая правка T-032 (дефект латентен с T-019 — стал
        // достижим с реальной постановкой): payload очереди хранится
        // JSON-экранированным (обратные слэши FQCN удваиваются),
        // буквальный assertStringContainsString(SendOrderNotification::class)
        // несовместим с форматом хранения; класс задания сверяется по
        // декодированному payload — интент проверки неизменен.
        $payload = json_decode((string) $jobs[0]->payload, true);

        $this->assertSame(
            SendOrderNotification::class,
            is_array($payload) ? ($payload['data']['commandName'] ?? null) : null,
            'В очереди стоит именно задание отправки уведомления.',
        );
    }
}
