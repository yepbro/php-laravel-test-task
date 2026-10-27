<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;

/**
 * Контрактная валидация списка GET /api/orders по task/openapi.yaml
 * (T-018, FR-API-10, §11 ТЗ «API-контракт»; S-24).
 *
 * Весь конверт ответа (data/links/meta) проверяется против JSON
 * Schema 2020-12, извлечённой из контракта по JSON-указателю
 * (Tests\Support\OpenApiSchema) — транзитивно валидируется каждый
 * элемент data[] по OrderListItem, а также PaginationLinks/meta.
 *
 * Сортировка, status-фильтр, пустые фильтры, границы per_page,
 * сохранение query string, 422 и JSON без Accept уже покрыты
 * зелёными тестами T-011 (OrdersListApiTest); email-фильтр —
 * OrdersListEmailFilterApiTest (красный до T-036). Здесь контрактная
 * валидация формы ответа, отсутствующий в T-011 край S-24 «страница за
 * пределами» и строгие ключи элемента списка — T-037. Оба контрактных
 * теста зелёные: скелет T-011 отдаёт контрактный ответ честно
 * (фиксация существующего поведения, как в T-016).
 */
class OrdersListContractApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_response_matches_openapi_contract(): void
    {
        $this->seedOrders();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        OpenApiSchema::assertResponseMatchesSchema(
            $response,
            OpenApiSchema::LIST_RESPONSE,
            'списка заказов',
        );
    }

    /**
     * T-037 (FR-API-04): элемент списка — строго поля схемы OrderListItem
     * и только они; customer — ровно email/name (без phone — он есть
     * только в карточке). JSON Schema контракта additionalProperties не
     * запрещает (описание openapi.yaml прямо разрешает дополнительные
     * поля), поэтому валидация выше лишних полей не ловит — строгость
     * «только поля контракта» пиннится сравнением множества ключей.
     */
    public function test_list_items_have_exactly_the_contract_fields(): void
    {
        $this->seedOrders();

        $items = $this->getJson('/api/orders')
            ->assertOk()
            ->json('data');

        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertEqualsCanonicalizing(
                ['id', 'number', 'status', 'customer', 'total', 'currency', 'created_at', 'updated_at'],
                array_keys($item),
            );
            self::assertEqualsCanonicalizing(['email', 'name'], array_keys($item['customer']));
        }
    }

    /**
     * S-24 «страница за пределами»: страница много дальше last_page —
     * 200 с пустым data, null from/to/next и неизменным total;
     * обёртка остаётся контрактной (required links/meta на месте).
     */
    public function test_page_beyond_last_returns_empty_page_matching_contract(): void
    {
        $this->seedOrders();

        $response = $this->getJson('/api/orders?per_page=2&page=99');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 99)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.from', null)
            ->assertJsonPath('meta.to', null)
            ->assertJsonPath('links.next', null);

        OpenApiSchema::assertResponseMatchesSchema(
            $response,
            OpenApiSchema::LIST_RESPONSE,
            'списка заказов на странице за пределами',
        );
    }

    /**
     * Четыре заказа в разных статусах (enum OrderListItem), один
     * без телефона, размещённые в разные моменты.
     *
     * @return list<Order>
     */
    private function seedOrders(): array
    {
        return [
            $this->seedOrder(id: 'ord_created01', status: OrderStatus::CREATED, placedAt: '2026-10-01 10:00:00'),
            $this->seedOrder(id: 'ord_paid0001', status: OrderStatus::PAID, placedAt: '2026-10-02 11:00:00'),
            $this->seedOrder(id: 'ord_shipp0001', status: OrderStatus::SHIPPED, placedAt: '2026-10-03 12:00:00', withoutPhone: true),
            $this->seedOrder(id: 'ord_cance0001', status: OrderStatus::CANCELLED, placedAt: '2026-10-04 13:00:00'),
        ];
    }

    private function seedOrder(string $id, OrderStatus $status, string $placedAt, bool $withoutPhone = false): Order
    {
        $factory = Order::factory();

        if ($withoutPhone) {
            $factory = $factory->withoutPhone();
        }

        return $factory->create([
            'id' => $id,
            'status' => $status,
            'placed_at' => CarbonImmutable::parse($placedAt),
        ]);
    }
}
