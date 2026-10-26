<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

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
 * события, а транзакции остаются короткими (NFR-10).
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
            ->chunkById(1000, function ($events) use (&$deleted): void {
                /** @var \Illuminate\Database\Eloquent\Collection<int, WebhookEvent> $events */
                foreach ($events as $event) {
                    if ($event->delete()) {
                        $deleted++;
                    }
                }
            });

        $this->info("Удалено событий старше {$retentionDays} дн.: {$deleted}");

        return self::SUCCESS;
    }
}
