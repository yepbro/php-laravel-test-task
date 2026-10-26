<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HTTP-скелет списка заказов GET /api/orders (T-011, §6.6 ТЗ).
 *
 * Честная часть скелета фиксируется реальной работой: стандартная
 * пагинированная обёртка (FR-API-03), сортировка placed_at DESC, id DESC
 * (FR-API-01), фильтр status и игнорирование пустых фильтров (FR-API-02),
 * 422 стандартного формата на некорректные параметры (FR-API-08),
 * JSON независимо от Accept (FR-API-09). Фильтр email — заглушка T-036:
 * непустое значение отказывает 500, а не возвращает неотфильтрованный
 * список, изображая фильтрацию (правило этапа 2).
 */
class OrdersListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_returns_standard_paginated_collection_structure(): void
    {
        $this->seedOrders();

        $response = $this->getJson('/api/orders');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'number',
                    'status',
                    'customer' => ['email', 'name'],
                    'total',
                    'currency',
                    'created_at',
                    'updated_at',
                ],
            ],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'],
        ]);

        // Дефолты пагинации: page = 1, per_page = 20 (FR-API-03).
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_orders_are_sorted_by_placed_at_desc_then_id_desc(): void
    {
        // B и C оформлены в один момент — порядок определяет id DESC
        // (FR-API-01, стабильная сортировка); A старше — в конце.
        $oldest = $this->seedOrder(id: 'ord_aaaaaaaa', placedAt: '2026-10-01 10:00:00');
        $tieLow = $this->seedOrder(id: 'ord_bbb00001', placedAt: '2026-10-03 12:00:00');
        $tieHigh = $this->seedOrder(id: 'ord_bbb00002', placedAt: '2026-10-03 12:00:00');

        $response = $this->getJson('/api/orders');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $tieHigh->id)
            ->assertJsonPath('data.1.id', $tieLow->id)
            ->assertJsonPath('data.2.id', $oldest->id);
    }

    public function test_status_filter_returns_only_matching_orders(): void
    {
        $paid = $this->seedOrder(id: 'ord_paid0001', status: OrderStatus::PAID);
        $this->seedOrder(id: 'ord_created01');
        $this->seedOrder(id: 'ord_created02');

        $this->getJson('/api/orders?status=paid')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $paid->id);

        $this->getJson('/api/orders?status=created')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_empty_filter_values_are_ignored(): void
    {
        $this->seedOrders();

        // Пустые параметры (ConvertEmptyStringsToNull → null) не фильтруют
        // и не ломают пагинацию — «пустой фильтр игнорируется» (FR-API-02).
        $this->getJson('/api/orders?status=&email=&page=&per_page=')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_pagination_boundaries_are_accepted(): void
    {
        $this->seedOrders();

        $this->getJson('/api/orders?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/orders?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/orders?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_pagination_links_preserve_query_string(): void
    {
        $this->seedOrder(id: 'ord_paid0001', status: OrderStatus::PAID);
        $this->seedOrder(id: 'ord_paid0002', status: OrderStatus::PAID);
        $this->seedOrder(id: 'ord_paid0003', status: OrderStatus::PAID);
        $this->seedOrder(id: 'ord_created01');

        $response = $this->getJson('/api/orders?status=paid&per_page=2');

        $response->assertOk()->assertJsonPath('meta.total', 3);

        foreach (['first', 'last', 'next'] as $link) {
            self::assertStringContainsString(
                'status=paid',
                (string) $response->json('links.' . $link),
                "Ссылка {$link} должна сохранять фильтр (withQueryString, FR-API-03).",
            );
            self::assertStringContainsString(
                'per_page=2',
                (string) $response->json('links.' . $link),
                "Ссылка {$link} должна сохранять per_page (withQueryString, FR-API-03).",
            );
        }
    }

    /**
     * Фильтр email — заглушка T-036: непустое значение отказывает
     * LogicException'ом (HTTP 500), не изображая фильтрацию.
     */
    public function test_email_filter_fails_closed_until_t_036(): void
    {
        $this->seedOrders();

        $response = $this->getJson('/api/orders?email=anna@example.com');

        $response->assertStatus(500);
        self::assertStringNotContainsString(
            '"data"',
            $response->getContent(),
            'Заглушка не должна отдавать список, маскирующийся под отфильтрованный.',
        );
    }

    /**
     * Некорректные query-параметры → 422 в стандартном JSON-формате
     * Laravel: message + errors (FR-API-08, A-29).
     */
    #[DataProvider('invalidParametersProvider')]
    public function test_invalid_parameters_yield_standard_422(string $query, string $field): void
    {
        $this->seedOrders();

        $response = $this->getJson('/api/orders' . $query);

        $response
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidParametersProvider(): iterable
    {
        return [
            'status вне enum' => ['?status=unknown', 'status'],
            'per_page = 0' => ['?per_page=0', 'per_page'],
            'per_page = 101' => ['?per_page=101', 'per_page'],
            'page = 0' => ['?page=0', 'page'],
            'page нечисловой' => ['?page=abc', 'page'],
            'per_page нечисловой' => ['?per_page=many', 'per_page'],
        ];
    }

    public function test_json_is_returned_regardless_of_accept_header(): void
    {
        $this->seedOrders();

        $html = $this->call('GET', '/api/orders', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $html->assertStatus(200);
        self::assertStringStartsWith('application/json', (string) $html->headers->get('Content-Type'));

        $noAccept = $this->call('GET', '/api/orders');
        $noAccept->assertStatus(200);
        self::assertStringStartsWith('application/json', (string) $noAccept->headers->get('Content-Type'));
    }

    /**
     * 422 остаётся JSON независимо от Accept (FR-API-09 + FR-API-08).
     */
    public function test_validation_error_422_stays_json_with_html_accept(): void
    {
        $this->seedOrders();

        $response = $this->call('GET', '/api/orders?per_page=101', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

        $response->assertStatus(422);
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertArrayHasKey('errors', (array) $response->json());
    }

    /**
     * Три заказа с фиксированными id (для сортировки) и датами.
     *
     * @return list<Order>
     */
    private function seedOrders(): array
    {
        return [
            $this->seedOrder(id: 'ord_aaaaaaaa', placedAt: '2026-10-01 10:00:00'),
            $this->seedOrder(id: 'ord_bbb00001', placedAt: '2026-10-02 11:00:00'),
            $this->seedOrder(id: 'ord_bbb00002', placedAt: '2026-10-03 12:00:00'),
        ];
    }

    private function seedOrder(string $id, ?OrderStatus $status = null, ?string $placedAt = null): Order
    {
        $placedAt ??= '2026-10-02 12:00:00';

        return Order::factory()->create([
            'id' => $id,
            'status' => $status ?? OrderStatus::CREATED,
            'placed_at' => CarbonImmutable::parse($placedAt),
        ]);
    }
}
