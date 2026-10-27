<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Очистка журнала сырых событий вебхуков (Q20, §5.3 ТЗ).
 *
 * Удаляет `webhook_events`, полученные раньше, чем
 * `MARKETPLACE_EVENTS_RETENTION_DAYS` дней назад (по умолчанию 90).
 * Сырые тела нужны только для разбора инцидентов ограниченное время;
 * возраст считается от `received_at` — времени первой приёмки: повторы
 * доставки не продлевают срок хранения (решение зафиксировано в
 * DECISIONS.md, задача 012).
 *
 * Удаление батчами (`chunkById`): строки уничтожаются в процессе
 * итерации по возрастанию ключа, поэтому пагинация не пропускает
 * события, а транзакции остаются короткими (NFR-10). Внутри батча —
 * один bulk-DELETE по коллекции (`toQuery()->delete()`): model-события
 * удаления не используются (обсерверов у модели нет), и чанк из 1000
 * строк закрывается одним запросом, а не тысячей построчных delete().
 */
final class PruneWebhookEventsCommand extends Command
{
    /**
     * Имя и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'webhooks:prune';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Удаление сырых событий вебхуков старше срока хранения (Q20)';

    /**
     * Выполнить консольную команду.
     */
    public function handle(): int
    {
        $retentionDays = max(1, (int) config('marketplace.events_retention_days', 90));
        $threshold = CarbonImmutable::now()->subDays($retentionDays);

        $deleted = 0;

        WebhookEvent::query()
            ->where('received_at', '<', $threshold)
            ->chunkById(1000, function (Collection $events) use (&$deleted): bool {
                // Bulk-DELETE батча: model-события не нужны (наблюдателей
                // нет), поэтому построчные delete() — лишние N запросов.
                // Счётчик — size батча: delete() фреймворк типизирует
                // как mixed (onDelete-callback), а не как int.
                $events->toQuery()->delete();
                $deleted += $events->count();

                return true;
            });

        // Структурированный info-токен (NFR-06): наблюдаемость плановых
        // прогонов — машинный токен в reason и счётчики в контексте лога
        // приложения; суточный нулевой прогон молчит (решение T-035).
        // Канал по умолчанию пока stack/single: stderr JSON (§5.3) —
        // остаток задачи 014, кандидат T-045 (DECISIONS.md).
        if ($deleted > 0) {
            Log::info('Очистка удалила устаревшие события вебхуков (webhook_events_pruned).', [
                'reason' => 'webhook_events_pruned',
                'deleted' => $deleted,
                'retention_days' => $retentionDays,
            ]);
        }

        $this->info("Удалено событий старше {$retentionDays} дн.: {$deleted}");

        return self::SUCCESS;
    }
}
