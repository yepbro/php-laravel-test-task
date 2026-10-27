<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\SendOrderNotification;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Догоночная постановка pending-уведомлений (FR-MAIL-06): sweeper
 * переставляет в очередь только зависшие pending-записи; свежие
 * pending и любые другие статусы не трогаются (зависший sending —
 * ручная диагностика, FR-MAIL-05/Q25).
 */
class SweepPendingNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_redispatches_only_stale_pending(): void
    {
        Queue::fake();

        $stale = $this->createPendingUpdatedAt(minutesAgo: 5);
        $fresh = $this->createPendingUpdatedAt(minutesAgo: 0);

        $this->artisan('notifications:sweep')
            ->expectsOutputToContain("Переставлено в очередь pending-уведомлений: 1")
            ->assertSuccessful();

        Queue::assertPushedOnce(
            SendOrderNotification::class,
            static fn(SendOrderNotification $job): bool => $job->notificationId === $stale->id,
        );
        Queue::assertNotPushed(
            SendOrderNotification::class,
            static fn(SendOrderNotification $job): bool => $job->notificationId === $fresh->id,
        );
    }

    /**
     * Порог берётся из config: граница «ровно sweep_after_seconds»
     * входит в выборку (<=), секундой раньше — нет. Время заморожено
     * travelTo, чтобы возраст записей не «утёк» между подготовкой
     * и запуском команды.
     */
    public function test_threshold_boundary_follows_config(): void
    {
        Queue::fake();
        config()->set('marketplace.notifications.sweep_after_seconds', 120);
        $this->travelTo(new CarbonImmutable('2026-10-27 12:00:00'));

        $exactlyOnThreshold = $this->createPendingUpdated(
            CarbonImmutable::now()->subSeconds(120),
        );
        $oneSecondYounger = $this->createPendingUpdated(
            CarbonImmutable::now()->subSeconds(119),
        );

        $this->artisan('notifications:sweep')->assertSuccessful();

        Queue::assertPushed(
            SendOrderNotification::class,
            static fn(SendOrderNotification $job): bool => $job->notificationId === $exactlyOnThreshold->id,
        );
        Queue::assertNotPushed(
            SendOrderNotification::class,
            static fn(SendOrderNotification $job): bool => $job->notificationId === $oneSecondYounger->id,
        );
    }

    public function test_never_touches_non_pending_statuses(): void
    {
        Queue::fake();

        $sending = $this->createNotificationWithStatus('sending', minutesAgo: 30);
        $sent = $this->createNotificationWithStatus('sent', minutesAgo: 30);
        $skipped = $this->createNotificationWithStatus('skipped', minutesAgo: 30);
        $failed = $this->createNotificationWithStatus('failed', minutesAgo: 30);

        $this->artisan('notifications:sweep')
            ->expectsOutputToContain('Зависших pending-уведомлений нет')
            ->assertSuccessful();

        foreach ([$sending, $sent, $skipped, $failed] as $notification) {
            Queue::assertNotPushed(
                SendOrderNotification::class,
                static fn(SendOrderNotification $job): bool => $job->notificationId === $notification->id,
            );
        }
    }

    public function test_reports_each_redispatched_notification(): void
    {
        Queue::fake();

        $stale = $this->createPendingUpdatedAt(minutesAgo: 10);

        // Одна строка вывода = одно ожидание: каждая подстрока
        // expectsOutputToContain потребляется отдельным чанком вывода.
        $this->artisan('notifications:sweep')
            ->expectsOutputToContain("уведомление {$stale->id} (заказ {$stale->order_id}, тип {$stale->type->value}")
            ->assertSuccessful();
    }

    /**
     * Прогон батчами chunkById обрабатывает весь backlog даже когда
     * записей больше размера батча (аудит P3.8: без неограниченного
     * get(), но и без потери «хвостов» за пределами первого батча).
     */
    public function test_sweeps_all_pending_across_batches(): void
    {
        Queue::fake();
        config()->set('marketplace.notifications.sweep_batch_size', 2);

        $stale = collect([
            $this->createPendingUpdatedAt(minutesAgo: 5),
            $this->createPendingUpdatedAt(minutesAgo: 5),
            $this->createPendingUpdatedAt(minutesAgo: 5),
        ]);

        $this->artisan('notifications:sweep')
            ->expectsOutputToContain('Переставлено в очередь pending-уведомлений: 3')
            ->assertSuccessful();

        foreach ($stale as $notification) {
            Queue::assertPushed(
                SendOrderNotification::class,
                static fn(SendOrderNotification $job): bool => $job->notificationId === $notification->id,
            );
        }
    }

    /**
     * Потолок прогона ограничивает разовую работу (правило scheduling
     * «Bound Work Inside the Task»): лишние записи остаются следующим
     * запускам, итоговая строка сообщает об остановке на лимите.
     */
    public function test_run_is_capped_at_max_per_run(): void
    {
        Queue::fake();
        config()->set('marketplace.notifications.sweep_batch_size', 1);
        config()->set('marketplace.notifications.sweep_max_per_run', 2);

        $this->createPendingUpdatedAt(minutesAgo: 5);
        $this->createPendingUpdatedAt(minutesAgo: 5);
        $this->createPendingUpdatedAt(minutesAgo: 5);

        $this->artisan('notifications:sweep')
            ->expectsOutputToContain('Переставлено в очередь pending-уведомлений: 2 (прогон остановлен на лимите 2')
            ->assertSuccessful();

        Queue::assertPushed(SendOrderNotification::class, 2);
    }

    /**
     * Критерий FR-MAIL-06 целиком: переставленное sweeper'ом задание
     * доезжает до реальной отправки. Набор T-012 фиксировал только
     * постановку (`Queue::fake`, задание тогда было скелетом) — здесь
     * sync-драйвер тестовой очереди исполняет задание в момент dispatch:
     * письмо уходит получателю, запись фиксирует `sent`. Непустой прогон
     * пишет info-токен notifications_swept (NFR-06).
     */
    public function test_swept_notification_is_delivered_by_the_redispatched_job(): void
    {
        Mail::fake();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertStringContainsString('notifications_swept', $message);

                return $context['reason'] === 'notifications_swept' && $context['swept'] === 1;
            });

        $order = Order::factory()->paid()->create();
        $notification = OrderNotification::factory()->forOrder($order)->create([
            'type' => NotificationType::PAID,
        ]);
        OrderNotification::query()
            ->whereKey($notification->id)
            ->update(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('notifications:sweep')->assertSuccessful();

        $notification->refresh();

        $this->assertSame(NotificationStatus::SENT, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame($order->customer_email, $notification->recipient);
        Mail::assertSent(
            OrderPaidMail::class,
            static fn(OrderPaidMail $mail): bool => $mail->hasTo($order->customer_email),
        );
    }

    /**
     * Контракт `updated_at` для sweeper (решение T-034): каждая попытка
     * отправки обновляет метку, поэтому запись, вернувшаяся в `pending`
     * после неудачной попытки (attempts ≥ 1, метка свежее порога), ждёт
     * собственного повтора по backoff — sweeper её не дёргает: фильтра
     * по attempts нет и не нужно, отступ обеспечивает метка.
     */
    public function test_notification_returned_to_pending_by_retry_is_not_redispatched_early(): void
    {
        Queue::fake();

        $notification = OrderNotification::factory()->pending()->create();

        OrderNotification::query()
            ->whereKey($notification->id)
            ->update([
                'attempts' => 1,
                'updated_at' => now()->subMinute(),
            ]);

        $this->artisan('notifications:sweep')
            ->expectsOutputToContain('Зависших pending-уведомлений нет')
            ->assertSuccessful();

        Queue::assertNotPushed(SendOrderNotification::class);
    }

    private function createPendingUpdatedAt(int $minutesAgo): OrderNotification
    {
        return $this->createPendingUpdated(now()->subMinutes($minutesAgo)->startOfSecond());
    }

    private function createPendingUpdated(\DateTimeInterface $moment): OrderNotification
    {
        $notification = OrderNotification::factory()->pending()->create();

        OrderNotification::query()
            ->whereKey($notification->id)
            ->update(['updated_at' => $moment]);

        return $notification->fresh();
    }

    private function createNotificationWithStatus(string $status, int $minutesAgo): OrderNotification
    {
        $notification = OrderNotification::factory()->create();

        OrderNotification::query()
            ->whereKey($notification->id)
            ->update([
                'status' => $status,
                // Builder-update не трогает timestamps — возраст задаём явно.
                'updated_at' => now()->subMinutes($minutesAgo),
            ]);

        return $notification->fresh();
    }
}
