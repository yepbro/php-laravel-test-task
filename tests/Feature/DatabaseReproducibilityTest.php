<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNotification;
use App\Models\OrderStatusHistory;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Воспроизводимость БД на чистой MySQL (T-008): единый сценарий чек-листа —
 * миграции с нуля + сидер → фабричная генерация поверх → полный rollback →
 * повторный migrate с сидером. Составные шаги (схема, фабрики, сидер) покрыты
 * детально в DomainSchemaTest, DomainFactoriesTest и DemoOrdersSeederTest —
 * здесь проверяется их связка.
 *
 * Поглотил MigrationsRollbackTest (T-003): его сценарий fresh → rollback →
 * re-migrate — строгое подмножество этого цикла. Причина слияния: Paratest
 * даёт per-process базы (testing_test_N) только тестам с DB-трейтами; этот
 * класс работает без трейтов в базовой `testing` — и должен оставаться в ней
 * единственным, иначе два artisan-DDL-теста гоняются за одни таблицы
 * (решение — DECISIONS.md, задача 008). Работает без RefreshDatabase — сам
 * управляет схемой через artisan.
 */
class DatabaseReproducibilityTest extends TestCase
{
    private const DOMAIN_TABLES = [
        'order_items',
        'order_status_history',
        'order_notifications',
        'webhook_events',
        'orders',
    ];

    /** Объём сида: выше минимума сидера (5), быстрее дефолта (30). */
    private const SEED_VOLUME = 12;

    public function test_clean_database_full_cycle_migrations_factories_seeding_rollback(): void
    {
        config(['seeding.demo_orders' => self::SEED_VOLUME]);

        // 1. Миграции с нуля + демо-сидер (DatabaseSeeder → DemoOrdersSeeder).
        $this->artisan('migrate:fresh', ['--force' => true, '--seed' => true])->assertExitCode(0);

        $this->assertDomainTablesExist();
        $this->assertSame(self::SEED_VOLUME, Order::query()->count());

        // 2. Фабричная генерация поверх сида: согласованный заказ одной
        //    строкой (позиции + история) и независимые события/уведомления.
        $order = Order::factory()->paid()->withItems(2)->withHistory()->create();
        WebhookEvent::factory()->forOrder($order)->create();
        OrderNotification::factory()->forOrder($order)->create();

        $this->assertSame(self::SEED_VOLUME + 1, Order::query()->count());
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(2, $order->history()->count());
        $this->assertSame(1, $order->notifications()->count());

        // Данные читаются после генерации: полный агрегат из БД.
        $this->assertNotNull(
            Order::query()->with(['items', 'history', 'notifications'])->find($order->id),
        );

        // 3. Полный rollback: доменные таблицы снимаются.
        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table {$table} must be dropped by rollback.");
        }

        // 4. Повторный migrate с того же нулевого состояния + сидер: набор
        //    снова доступен командой сидирования (критерий готовности задачи).
        $this->artisan('migrate', ['--force' => true, '--seed' => true])->assertExitCode(0);

        $this->assertDomainTablesExist();
        $this->assertSame(self::SEED_VOLUME, Order::query()->count());

        // Все пять таблиц согласованы после пересоздания: демо-набор покрывает
        // каждую таблицу (точные составы набора — зона DemoOrdersSeederTest,
        // здесь — что генерация доступна командой сидирования на чистой базе).
        $this->assertGreaterThan(0, WebhookEvent::query()->count());
        $this->assertGreaterThan(0, OrderNotification::query()->count());
        $this->assertSame(
            0,
            Order::query()->whereDoesntHave('history')->count(),
            'Каждый заказ повторно созданного набора имеет историю статусов.',
        );
        $this->assertTrue(OrderItem::query()->exists());
        $this->assertTrue(OrderStatusHistory::query()->exists());
    }

    protected function tearDown(): void
    {
        // Финальный `migrate --seed` оставляет демо-набор закоммиченным.
        // Класс работает без DB-трейтов: RefreshDatabase-классы того же
        // процесса миграцию не повторяют (RefreshDatabaseState::$migrated
        // глобален на процесс) и видели бы чужие 12 заказов/4 события —
        // редкие флаки счётчиковых ассертов соседних наборов (диагностика
        // T-020). Очистка доменных таблиц в tearDown восстанавливает
        // изоляцию: схема (её проверяет тест) остаётся, данные — нет.
        // DELETE, а не TRUNCATE: без DDL с implicit commit (решение T-007).
        if (Schema::hasTable(self::DOMAIN_TABLES[0])) {
            foreach (self::DOMAIN_TABLES as $table) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function assertDomainTablesExist(): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} must exist after migrations.");
        }
    }
}
