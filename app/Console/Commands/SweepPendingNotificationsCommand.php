<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Jobs\SendOrderNotification;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

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
 *
 * Прогон ограничен (правило scheduling скилла laravel-best-practices —
 * «Bound Work Inside the Task»): выборка идёт батчами `chunkById`
 * (батч-сize и потолок прогона — config/marketplace.php), поэтому ни
 * память, ни длительность разовой команды не зависят от размера backlog.
 * Пагинация по id стабильна, даже когда переставленные записи покидают
 * выборку по ходу прогона (dispatch → sending меняет updated_at).
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

        $batchSize = max(1, (int) config('marketplace.notifications.sweep_batch_size', 500));
        $maxPerRun = max(1, (int) config('marketplace.notifications.sweep_max_per_run', 1000));

        $swept = 0;

        // Возвращаемое false останавливает chunkById — так потолок прогона
        // ограничивает работу без отдельного подсчёта «хвостов».
        OrderNotification::query()
            ->where('status', NotificationStatus::PENDING)
            ->where('updated_at', '<=', $threshold)
            ->chunkById($batchSize, function (Collection $notifications) use (&$swept, $maxPerRun): bool {
                foreach ($notifications as $notification) {
                    SendOrderNotification::dispatch($notification->id);

                    $this->line(
                        "Переставлено в очередь: уведомление {$notification->id}"
                        . " (заказ {$notification->order_id}, тип {$notification->type->value},"
                        . " попыток {$notification->attempts})",
                    );
                }

                $swept += $notifications->count();

                return $swept < $maxPerRun;
            });

        if ($swept === 0) {
            $this->info("Зависших pending-уведомлений нет (порог {$sweepAfterSeconds} с)");

            return self::SUCCESS;
        }

        // Структурированный info-токен (NFR-06): наблюдаемость плановых
        // прогонов — машинный токен в reason и счётчики в контексте лога
        // приложения. Логируются только непустые прогоны — ежеминутный
        // нулевой запуск не должен шуметь (решение T-035). Канал по
        // умолчанию пока stack/single: stderr JSON (§5.3) — остаток
        // задачи 014, кандидат T-045 (DECISIONS-log.md).
        Log::info('Sweeper переставил в очередь зависшие pending-уведомления (notifications_swept).', [
            'reason' => 'notifications_swept',
            'swept' => $swept,
            'sweep_after_seconds' => $sweepAfterSeconds,
            'capped' => $swept >= $maxPerRun,
        ]);

        $summary = "Переставлено в очередь pending-уведомлений: {$swept}";

        if ($swept >= $maxPerRun) {
            $summary .= " (прогон остановлен на лимите {$maxPerRun}; следующие запуски продолжат)";
        }

        $this->info($summary);

        return self::SUCCESS;
    }
}
