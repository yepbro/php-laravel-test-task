<?php

declare(strict_types=1);

use App\Console\Commands\PruneWebhookEventsCommand;
use App\Console\Commands\SweepPendingNotificationsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-MAIL-06: раз в минуту — догоночная постановка зависших
// pending-уведомлений (потерянный после коммита dispatch).
Schedule::command(SweepPendingNotificationsCommand::class)
    ->everyMinute()
    ->withoutOverlapping();

// Q20/§5.3: удаление сырых событий старнее retention. Раз в сутки
// достаточно: событие можно удалить в любой момент после 90 дней,
// а редкий запуск не гоняет лишнюю нагрузку по БД (NFR-10).
Schedule::command(PruneWebhookEventsCommand::class)
    ->daily()
    ->withoutOverlapping();
