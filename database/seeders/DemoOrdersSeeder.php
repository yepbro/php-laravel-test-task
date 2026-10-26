<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Демо-набор заказов для локальной разработки (T-007): проверка фильтров
 * status/email, сортировки `placed_at DESC` и пагинации API/админки (§6.6, §6.7 ТЗ).
 *
 * Строится только на фабриках T-006. Перед созданием полностью очищает
 * доменные таблицы: повторный запуск предсказуемо пересоздаёт тот же набор —
 * детерминированное распределение через фиксированный seed Faker и индексные
 * паттерны; случайными остаются только генерируемые фабриками идентификаторы
 * (`ord_…`/`evt_…`/ULID), не влияющие на фильтры/сортировку/пагинацию.
 *
 * Только локальная/тестовая база: в production сидер отказывает с ошибкой
 * (guardAgainstProduction), независимо от способа вызова.
 */
class DemoOrdersSeeder extends Seeder
{
    /**
     * Сценарии заказов (цикл 10): все пять статусов, включая отмену
     * неоплаченного (`cancelled`) и оплаченного (`paid_cancelled` =
     * `paid()->cancelled()` с сохранённым `paid_at`, S-08).
     *
     * @var list<string>
     */
    private const STATUS_PATTERN = [
        'created', 'paid', 'shipped', 'delivered', 'cancelled',
        'created', 'paid_cancelled', 'shipped', 'delivered', 'paid',
    ];

    /**
     * Число позиций заказа (цикл 5): 0/1/несколько.
     *
     * @var list<int>
     */
    private const ITEMS_PATTERN = [0, 1, 2, 3, 5];

    /**
     * Повторяющиеся email демо-набора: исходный регистр намеренно разный —
     * нормализованные значения совпадают, фильтр email (FR-API-02, точное
     * совпадение без учёта регистра) проверяется на повторах.
     *
     * @var list<string>
     */
    private const DEMO_EMAILS = [
        'Anna@Shop.Test',
        'boris@shop.test',
        'VERA@shop.test',
        'gregor@Shop.test',
        'dasha@shop.test',
    ];

    /**
     * Доменные таблицы demo-набора: очищаются полностью перед пересборкой,
     * дети раньше родителей (порядок уважает FK из T-003).
     *
     * @var list<string>
     */
    private const DOMAIN_TABLES = [
        'order_notifications',
        'order_status_history',
        'order_items',
        'webhook_events',
        'orders',
    ];

    /** Шаг назад по времени между соседними заказами, минуты (уникальные placed_at). */
    private const PLACED_AT_STEP_MINUTES = 137;

    /** Seed Faker: одинаковый набор значений при каждом запуске. */
    private const FAKER_SEED = 20261027;

    /** Минимальный объём набора: все пять статусов должны присутствовать. */
    private const MIN_ORDERS = 5;

    /** Число сценариев уведомлений (sent/sending/skipped/failed/pending). */
    private const NOTIFICATION_SCENARIOS = 5;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $total = (int) config('seeding.demo_orders');

        if ($total < self::MIN_ORDERS) {
            throw new InvalidArgumentException(sprintf(
                'Ожидается не меньше %d демо-заказов (SEED_DEMO_ORDERS / seeding.demo_orders), получено: %d.',
                self::MIN_ORDERS,
                $total,
            ));
        }

        fake()->seed(self::FAKER_SEED);

        $this->clearDomainTables();

        $notifications = 0;

        foreach (range(0, $total - 1) as $index) {
            $order = $this->createOrder($index);

            if ($index % 5 === 2) {
                $this->createWebhookEvent($order);
            }

            $notificationType = $this->notificationTypeFor($order);

            if ($index % 4 === 2 && $notificationType !== null) {
                $this->createNotification($order, $notifications % self::NOTIFICATION_SCENARIOS, $notificationType);
                $notifications++;
            }
        }

        $this->createAmbientWebhookEvents(
            Order::query()->orderByDesc('placed_at')->firstOrFail(),
        );

        $this->command?->info(sprintf(
            'Демо-набор: %d заказов, %d событий вебхука, %d уведомлений.',
            $total,
            WebhookEvent::query()->count(),
            $notifications,
        ));
    }

    /**
     * Демо-сидер очищает доменные таблицы — в production запрещён (чек-лист T-007).
     * Отдельно от confirmToProceed самой команды `db:seed`: срабатывает и при
     * `--force`, и при программном вызове.
     */
    private function guardAgainstProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(sprintf(
                'DemoOrdersSeeder очищает доменные таблицы и не предназначен для production (APP_ENV=%s).',
                (string) app()->environment(),
            ));
        }
    }

    /**
     * Полная пересборка demo-набора: перед созданием очищаются только
     * доменные таблицы. DELETE, а не TRUNCATE: без манипуляций
     * FOREIGN_KEY_CHECKS и без DDL с implicit commit — работает и внутри
     * транзакции тестов (RefreshDatabase), и на живой базе; на демо-объёмах
     * разницы с TRUNCATE нет.
     */
    private function clearDomainTables(): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * Заказ по индексу: сценарий статуса, число позиций, nullable-телефон,
     * email из демо-пула и `placed_at` с уникальным шагом — всё детерминировано
     * индексом. Базовые атрибуты цепляются state()-ом ДО статусных states:
     * `paid()`/`shipped()`/… вычисляют свои даты от `placed_at`.
     */
    private function createOrder(int $index): Order
    {
        $attributes = [
            // Уникальные placed_at (шаг больше часа) — упорядоченная выборка
            // для сортировки placed_at DESC; 30 заказов ≈ 2,9 дня, 200 ≈ 19 дней.
            'placed_at' => CarbonImmutable::now()->subMinutes(self::PLACED_AT_STEP_MINUTES * $index),
        ];

        if ($index % 3 === 0) {
            // Каждый третий заказ — с повторяющимся демо-email (пул выше).
            $attributes['customer_email'] = self::DEMO_EMAILS[intdiv($index, 3) % count(self::DEMO_EMAILS)];
        }

        $factory = Order::factory()
            ->state($attributes)
            ->withItems(self::ITEMS_PATTERN[$index % count(self::ITEMS_PATTERN)])
            ->withHistory();

        if ($index % 6 === 5) {
            $factory = $factory->withoutPhone();
        }

        return (match (self::STATUS_PATTERN[$index % count(self::STATUS_PATTERN)]) {
            'created' => $factory->created(),
            'paid' => $factory->paid(),
            'shipped' => $factory->shipped(),
            'delivered' => $factory->delivered(),
            'cancelled' => $factory->cancelled(),
            'paid_cancelled' => $factory->paid()->cancelled(),
        })->create();
    }

    /**
     * Событие вебхука, применившее текущую версию заказа: id совпадает с
     * `version_event_id` заказа (как в реальном потоке §7), тип согласован
     * со статусом, время приёмки/подписи — момент события версии.
     */
    private function createWebhookEvent(Order $order): void
    {
        WebhookEvent::factory()
            ->forOrder($order)
            ->known($this->eventTypeForStatus($order->status))
            ->create([
                'id' => $order->version_event_id,
                'received_at' => $order->version_event_created_at,
                'signature_timestamp' => $order->version_event_created_at->getTimestamp(),
            ]);
    }

    /**
     * Два «журнальных» события вне заказов набора: неизвестный тип с
     * order_id NULL (S-18) и повторная доставка (S-02/S-03), привязанная
     * к самому свежему заказу набора (у известного типа order_id заполнен).
     */
    private function createAmbientWebhookEvents(Order $anchorOrder): void
    {
        WebhookEvent::factory()->unknown()->create();
        WebhookEvent::factory()->forOrder($anchorOrder)->known('order.updated')->redelivered()->create();
    }

    /**
     * Уведомление-запись outbox: тип по матрице §6.5 задаётся явно (не
     * случайный), сценарий статуса — циклический, все пять состояний outbox.
     */
    private function createNotification(Order $order, int $scenario, NotificationType $type): void
    {
        $factory = OrderNotification::factory()->forOrder($order);

        (match ($scenario) {
            0 => $factory->sent(),
            1 => $factory->sending(),
            2 => $factory->skipped(),
            3 => $factory->failed(),
            4 => $factory->pending(),
            default => $factory->pending(),
        })->create(['type' => $type]);
    }

    /**
     * Тип письма по матрице §6.5: только paid/shipped/cancelled — заказам
     * в created/delivered письма не положены.
     */
    private function notificationTypeFor(Order $order): ?NotificationType
    {
        return match ($order->status) {
            OrderStatus::PAID => NotificationType::PAID,
            OrderStatus::SHIPPED => NotificationType::SHIPPED,
            OrderStatus::CANCELLED => NotificationType::CANCELLED,
            default => null,
        };
    }

    /**
     * Тип события, которым заказ пришёл в статус (§6.1).
     */
    private function eventTypeForStatus(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::CREATED => 'order.created',
            OrderStatus::PAID => 'order.paid',
            OrderStatus::SHIPPED => 'order.shipped',
            OrderStatus::DELIVERED => 'order.delivered',
            OrderStatus::CANCELLED => 'order.cancelled',
        };
    }
}
