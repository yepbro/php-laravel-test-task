<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\NotificationStatus;
use App\Jobs\SendOrderNotification;
use App\Models\OrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Скелет выполнения задания отправки (T-012): шаг 1 §7 (загрузка и
 * проверка статуса) честный и идемпотентный — повторная постановка
 * безопасна; механика отправки (шаги 2–5) — заглушки T-034, поэтому
 * на живой pending-запись выполнение обязано упасть LogicException'ом,
 * а не изобразить отправку (правило этапа 2).
 */
class SendOrderNotificationSkeletonTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_notification_exits_silently(): void
    {
        $job = new SendOrderNotification(PHP_INT_MAX);

        $job->handle();

        $this->assertTrue(true, 'Тихий выход для отсутствующей записи (cascade-удаление с заказом)');
    }

    public function test_non_pending_notification_exits_silently(): void
    {
        $statuses = [
            NotificationStatus::SENDING,
            NotificationStatus::SENT,
            NotificationStatus::SKIPPED,
            NotificationStatus::FAILED,
        ];

        foreach ($statuses as $status) {
            $notification = $this->createNotificationWithStatus($status);

            (new SendOrderNotification($notification->id))->handle();

            $this->assertSame(
                $status,
                $notification->fresh()->status,
                "Статус {$status->value} не должен меняться скелетом",
            );
        }
    }

    public function test_pending_notification_hits_t034_stub(): void
    {
        $notification = $this->createNotificationWithStatus(NotificationStatus::PENDING);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-034');

        (new SendOrderNotification($notification->id))->handle();
    }

    /**
     * Очередь обнаруживает класс: dispatch через Bus/Queue ставит
     * задание с идентификатором записи (Queue::fake — без выполнения).
     */
    public function test_job_is_dispatchable_to_queue(): void
    {
        Queue::fake();

        SendOrderNotification::dispatch(777);

        Queue::assertPushed(
            SendOrderNotification::class,
            static fn(SendOrderNotification $job): bool => $job->notificationId === 777,
        );
    }

    private function createNotificationWithStatus(NotificationStatus $status): OrderNotification
    {
        $notification = OrderNotification::factory()->create();

        OrderNotification::query()
            ->whereKey($notification->id)
            ->update(['status' => $status->value]);

        return $notification->fresh();
    }
}
