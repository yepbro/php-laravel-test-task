<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventOutcome;
use App\Enums\NotificationStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoOrdersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Демо-сидер (T-007): заявленный объём во всех статусах с историей и краями,
 * предсказуемая пересборка повторным запуском, управление объёмом через
 * конфиг и production-защита. Тесты проекта от сидеров не зависят —
 * остальные наборы (Domain*) используют только фабрики.
 */
class DemoOrdersSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Объём, кратный всем паттернам генератора (10 статусов / 6 телефонов / 5 email-циклов). */
    private const FULL_VOLUME = 30;

    /** Замороженное время: placed_at = now() − 137·i минут должны быть предсказуемы. */
    private const FROZEN_NOW = '2026-10-27 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        config(['seeding.demo_orders' => self::FULL_VOLUME]);
    }

    public function test_seeds_full_volume_with_all_statuses_history_and_edges(): void
    {
        $this->seed(DemoOrdersSeeder::class);

        $this->assertSame(self::FULL_VOLUME, Order::query()->count());

        // Все пять статусов — фильтр status (FR-API-02) имеет значения.
        $seeded = Order::query()->pluck('status')
            ->map(static fn(OrderStatus $status): string => $status->value)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $expected = array_map(static fn(OrderStatus $status): string => $status->value, OrderStatus::cases());
        sort($expected);

        $this->assertSame($expected, $seeded);

        // История статусов есть у каждого заказа (§6.4), с корневым фактом created.
        $this->assertSame(0, Order::query()->whereDoesntHave('history')->count());
        $this->assertSame(
            0,
            Order::query()->whereDoesntHave('history', static fn($query) => $query->where('status', OrderStatus::CREATED))->count(),
        );

        // Позиции: заказы с 0, 1 и несколькими позициями.
        $itemCounts = Order::query()->withCount('items')->pluck('items_count')->unique()->all();
        $this->assertContains(0, $itemCounts);
        $this->assertContains(1, $itemCounts);
        $this->assertContains(5, $itemCounts);

        // Nullable-поля в обоих состояниях (§6.3): phone, shipment, cancel reason.
        $this->assertSame(5, Order::query()->whereNull('customer_phone')->count());
        $this->assertTrue(Order::query()->whereNull('shipment_carrier')->exists());
        $this->assertTrue(Order::query()->whereNotNull('shipment_carrier')->exists());
        $this->assertTrue(Order::query()->whereNotNull('cancel_reason')->exists());

        // Оплаченная отмена: paid()->cancelled() с сохранённым paid_at (S-08).
        $this->assertTrue(
            Order::query()
                ->where('status', OrderStatus::CANCELLED)
                ->whereNotNull('paid_at')
                ->whereNotNull('cancelled_at')
                ->whereNotNull('cancel_reason')
                ->exists(),
        );
    }

    public function test_seed_adds_webhook_events_and_notifications(): void
    {
        $this->seed(DemoOrdersSeeder::class);

        // Каждый пятый заказ (индексы 2, 7, 12, 17, 22, 27) + 2 «журнальных».
        $this->assertSame(8, WebhookEvent::query()->count());
        $this->assertSame(
            1,
            WebhookEvent::query()->whereNull('order_id')->where('outcome', EventOutcome::IGNORED)->count(),
        );

        // Событие, применившее версию заказа, — id = version_event_id (§7).
        $this->assertSame(
            6,
            WebhookEvent::query()
                ->whereIn('id', Order::query()->pluck('version_event_id'))
                ->count(),
        );

        // Уведомления: подходящие заказы с шагом 4 (2, 6, 14, 22, 26) — все
        // пять статусов outbox; только paid/shipped/cancelled (матрица §6.5).
        $this->assertSame(5, OrderNotification::query()->count());

        $statuses = OrderNotification::query()->pluck('status')
            ->map(static fn(NotificationStatus $status): string => $status->value)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $expected = array_map(static fn(NotificationStatus $status): string => $status->value, NotificationStatus::cases());
        sort($expected);

        $this->assertSame($expected, $statuses);
    }

    public function test_emails_repeat_for_exact_email_filter(): void
    {
        $this->seed(DemoOrdersSeeder::class);

        // Пять демо-адресов, каждый дважды (в исходном написании разный регистр).
        $repeated = Order::query()
            ->selectRaw('customer_email_normalized, COUNT(*) AS n')
            ->groupBy('customer_email_normalized')
            ->havingRaw('n > 1')
            ->pluck('n');

        $this->assertSame(5, $repeated->count());

        // Фильтр по нормализованному значению находит все вхождения (FR-API-02).
        $this->assertSame(2, Order::query()->where('customer_email_normalized', 'anna@shop.test')->count());
    }

    public function test_placed_at_values_are_unique_and_sorted_desc(): void
    {
        $this->seed(DemoOrdersSeeder::class);

        $placedAt = Order::query()->orderByDesc('placed_at')->pluck('placed_at');

        // Уникальные placed_at — устойчивая сортировка placed_at DESC (FR-API-01).
        $this->assertSame(self::FULL_VOLUME, $placedAt->unique()->count());

        // Размазанные даты: от «сейчас» (индекс 0) до now() − 137·29 минут.
        $frozen = CarbonImmutable::parse(self::FROZEN_NOW);
        $this->assertTrue($placedAt->first()->equalTo($frozen));
        $this->assertTrue($placedAt->last()->equalTo($frozen->subMinutes(137 * 29)));
    }

    public function test_rerun_rebuilds_identical_dataset(): void
    {
        $this->seed(DemoOrdersSeeder::class);
        $fingerprint = $this->datasetFingerprint();

        $this->seed(DemoOrdersSeeder::class);

        // Пересборка, а не накопление — и тот же состав набора.
        $this->assertSame(self::FULL_VOLUME, Order::query()->count());
        $this->assertSame($fingerprint, $this->datasetFingerprint());
    }

    public function test_volume_is_configurable(): void
    {
        config(['seeding.demo_orders' => 12]);
        $this->seed(DemoOrdersSeeder::class);
        $this->assertSame(12, Order::query()->count());

        // Повторный запуск с другим объёмом — пересборка под новый объём.
        config(['seeding.demo_orders' => 9]);
        $this->seed(DemoOrdersSeeder::class);
        $this->assertSame(9, Order::query()->count());
    }

    public function test_volume_below_minimum_is_rejected(): void
    {
        config(['seeding.demo_orders' => 4]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SEED_DEMO_ORDERS');

        $this->seed(DemoOrdersSeeder::class);
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');

        $this->app->make(DemoOrdersSeeder::class)->run();
    }

    public function test_database_seeder_calls_demo_seeder_outside_production(): void
    {
        config(['seeding.demo_orders' => 12]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(12, Order::query()->count());
    }

    public function test_database_seeder_skips_demo_seeder_in_production(): void
    {
        config(['seeding.demo_orders' => 12]);
        $this->app->instance('env', 'production');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_seeder_clears_only_domain_tables(): void
    {
        User::factory()->create();

        $this->seed(DemoOrdersSeeder::class);

        $this->assertSame(1, User::query()->count());
    }

    /**
     * Отпечаток набора: детерминированные фабрикой/паттернами значения без
     * случайных идентификаторов (id генерирует Str::random), упорядочен по
     * уникальному placed_at.
     *
     * @return list<string>
     */
    private function datasetFingerprint(): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()->withCount('items')->orderBy('placed_at')->get();

        return $orders
            ->map(static fn(Order $order): string => implode('|', [
                $order->number,
                $order->status->value,
                $order->customer_email_normalized,
                $order->customer_phone ?? '-',
                (string) $order->items_count,
                $order->placed_at->toIso8601String(),
            ]))
            ->all();
    }
}
