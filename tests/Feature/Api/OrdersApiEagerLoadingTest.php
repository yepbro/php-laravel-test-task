<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eager loading API заказов без N+1 (T-037, §6.6 ТЗ: «eager loading
 * items/history (без N+1)»).
 *
 * Карточка грузит items/history через loadMissing (T-011), а глобальный
 * строгий режим моделей (Model::shouldBeStrict) делает ленивую загрузку
 * связей падающей — скрытый N+1 через отношения невозможен в принципе.
 * Эти тесты — точечный детектор регрессии иным паттернам: число
 * SQL-запросов ответа не растёт с объёмом данных (карточка — при разном
 * числе позиций и фактов истории, список — при разном числе заказов).
 *
 * Пиннится именно константа, а не абсолютное число запросов: состав
 * запросов байндинга/пагинатора — внутренности фреймворка.
 */
class OrdersApiEagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_query_count_does_not_depend_on_items_and_history_size(): void
    {
        // paid: 2 позиции, 2 факта истории (created, paid);
        // shipped: 5 позиций, 3 факта (created, paid, shipped) —
        // разный объём обоих связанных наборов.
        $small = Order::factory()
            ->paid()
            ->withItems(2)
            ->withHistory()
            ->create(['id' => 'ord_small01']);

        $large = Order::factory()
            ->shipped()
            ->withItems(5)
            ->withHistory()
            ->create(['id' => 'ord_large01']);

        $smallCount = $this->countQueries(fn() => $this->getJson("/api/orders/{$small->id}")->assertOk());
        $largeCount = $this->countQueries(fn() => $this->getJson("/api/orders/{$large->id}")->assertOk());

        self::assertSame(
            $smallCount,
            $largeCount,
            'Число запросов карточки не должно зависеть от объёма items/history (N+1).',
        );
    }

    public function test_list_query_count_does_not_depend_on_order_count(): void
    {
        Order::factory()->count(2)->create();

        $smallCount = $this->countQueries(fn() => $this->getJson('/api/orders')->assertOk());

        Order::factory()->count(3)->create();

        $largeCount = $this->countQueries(fn() => $this->getJson('/api/orders')->assertOk());

        self::assertSame(
            $smallCount,
            $largeCount,
            'Число запросов списка не должно зависеть от числа заказов (N+1).',
        );
    }

    /**
     * Число SQL-запросов, выполненных внутри $request: лог включается
     * только на время запроса, чтобы не считать подготовку данных.
     *
     * @param callable(): mixed $request
     */
    private function countQueries(callable $request): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $request();
        } finally {
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $count;
    }
}
