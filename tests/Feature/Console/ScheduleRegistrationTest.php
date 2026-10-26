<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Регистрация плановых команд (FR-MAIL-06, Q20): sweeper обязан ходить
 * каждую минуту (дословно по ТЗ), очистка событий — раз в сутки
 * (достаточно для 90-дневного retention; обоснование — DECISIONS.md,
 * задача 012). Перекрытия запусков исключены.
 */
class ScheduleRegistrationTest extends TestCase
{
    public function test_sweeper_runs_every_minute_without_overlap(): void
    {
        $event = $this->findScheduledEvent('notifications:sweep');

        $this->assertNotNull($event, 'Команда notifications:sweep не зарегистрирована в планировщике');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'Запуски sweeper не должны перекрываться');
    }

    public function test_prune_runs_daily_without_overlap(): void
    {
        $event = $this->findScheduledEvent('webhooks:prune');

        $this->assertNotNull($event, 'Команда webhooks:prune не зарегистрирована в планировщике');
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'Запуски очистки не должны перекрываться');
    }

    private function findScheduledEvent(string $commandSubstring): ?\Illuminate\Console\Scheduling\Event
    {
        $this->artisan('schedule:list');

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if ($event->command !== null && str_contains($event->command, $commandSubstring)) {
                return $event;
            }
        }

        return null;
    }
}
