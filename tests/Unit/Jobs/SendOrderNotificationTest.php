<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SendOrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

/**
 * Контракт задания отправки (T-012): очередь обнаруживает класс
 * (ShouldQueue), политика попыток читается из config, постановка —
 * только после коммита (FR-MAIL-01). Выполнение handle() на реальной
 * БД — SendOrderNotificationSkeletonTest; логика отправки — T-034.
 */
class SendOrderNotificationTest extends TestCase
{
    public function test_job_is_queueable(): void
    {
        $job = new SendOrderNotification(42);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(42, $job->notificationId);
    }

    public function test_tries_come_from_config(): void
    {
        config()->set('marketplace.notifications.max_attempts', 5);

        $this->assertSame(5, (new SendOrderNotification(1))->tries);
    }

    public function test_tries_follow_config_override(): void
    {
        config()->set('marketplace.notifications.max_attempts', 3);

        $this->assertSame(3, (new SendOrderNotification(1))->tries);
    }

    public function test_tries_never_drop_below_one(): void
    {
        config()->set('marketplace.notifications.max_attempts', 0);

        $this->assertSame(1, (new SendOrderNotification(1))->tries);
    }

    public function test_backoff_follows_fr_mail_05(): void
    {
        $this->assertSame([10, 60, 300, 900], (new SendOrderNotification(1))->backoff());
    }

    public function test_backoff_follows_config_override(): void
    {
        config()->set('marketplace.notifications.backoff', [15, 90]);

        $this->assertSame([15, 90], (new SendOrderNotification(1))->backoff());
    }

    public function test_dispatch_is_deferred_until_commit(): void
    {
        $this->assertTrue((new SendOrderNotification(1))->afterCommit);
    }
}
