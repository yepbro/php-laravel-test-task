<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Jobs\SendOrderNotification;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Догоночная постановка зависших pending-уведомлений (FR-MAIL-06).
 *
 * Раз в минуту переставляет в очередь письма, оставшиеся `pending`
 * дольше `NOTIFICATIONS_SWEEP_AFTER_SECONDS` (по умолчанию 120 с) —
 * так восстанавливается потерянный первичный dispatch (Redis был
 * недоступен после коммита транзакции, NFR-05/R-9).
 *
 * Повторная постановка безопасна по построению: задание выходит на любом
 * не-pending статусе (шаг 1 §7), а захват отправки атомарен (FR-MAIL-04),
 * поэтому дубликаты постановки не приводят к дублям писем. Возраст
 * считается по `updated_at`: попытка отправки обновляет метку, и письмо,
 * уже перепланированное очередью с backoff, sweeper заново не трогает.
 * Зависший `sending` сознательно НЕ переотправляется (FR-MAIL-05, Q25:
 * приоритет «не дублировать»; ручная диагностика — docs/operations.md).
 */
final class SweepPendingNotificationsCommand extends Command
{
    /**
     * Имя и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'notifications:sweep';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Повторная постановка в очередь pending-уведомлений старше порога (FR-MAIL-06)';

    /**
     * Выполнить консольную команду.
     */
    public function handle(): int
    {
        $sweepAfterSeconds = max(0, (int) config('marketplace.notifications.sweep_after_seconds', 120));
        $threshold = CarbonImmutable::now()->subSeconds($sweepAfterSeconds);

        /** @var \Illuminate\Database\Eloquent\Collection<int, OrderNotification> $stale */
        $stale = OrderNotification::query()
            ->where('status', NotificationStatus::PENDING)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("Зависших pending-уведомлений нет (порог {$sweepAfterSeconds} с)");

            return self::SUCCESS;
        }

        foreach ($stale as $notification) {
            SendOrderNotification::dispatch($notification->id);

            $this->line(
                "Переставлено в очередь: уведомление {$notification->id}"
                . " (заказ {$notification->order_id}, тип {$notification->type->value},"
                . " попыток {$notification->attempts})",
            );
        }

        $this->info("Переставлено в очередь pending-уведомлений: {$stale->count()}");

        return self::SUCCESS;
    }
}
