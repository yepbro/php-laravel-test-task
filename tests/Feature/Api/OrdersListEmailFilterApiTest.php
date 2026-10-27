<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Фильтр email списка заказов GET /api/orders (T-018, §6.6 ТЗ; S-24).
 *
 * Точное совпадение без учёта регистра по `customer_email_normalized`
 * (FR-API-02, A-30 — значение `trim + lower`): OrderListQuery сравнивает
 * нормализованный вход (ListOrdersRequest) с нормализованным полем БД.
 * Три исходных теста набора были КРАСНЫМИ до T-036 (падали первым
 * assertOk() на фактическом 500 fail-closed заглушки OrderListQuery)
 * и позеленели реализацией T-036 без правки тестов; trim-край входа
 * добавлен тестом в T-036 (новое покрытие входной нормализации).
 * Пустой email-фильтр (игнорируется) уже покрыт зелёным тестом T-011
 * (OrdersListApiTest::test_empty_filter_values_are_ignored).
 *
 * Сортировка, status-фильтр, пагинация, сохранение query string,
 * 422 некорректных параметров и JSON без Accept — тоже T-011
 * (OrdersListApiTest) и здесь не дублируются.
 */
class OrdersListEmailFilterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /**
     * Точное совпадение без учёта регистра: запрос в верхнем регистре
     * находит заказ со смешанным регистром хранения (сравнение по
     * normalized-адресу, A-30), исходный email отдаётся без искажений
     * (S-26). Красный до T-036.
     */
    public function test_email_filter_matches_exact_address_case_insensitively(): void
    {
        $anna = $this->seedOrder(id: 'ord_anna0001', email: 'Anna.Smirnova@Example.COM');
        $this->seedOrder(id: 'ord_bob00001', email: 'bob@example.com');

        $this->getJson('/api/orders?email=ANNA.SMIRNOVA@EXAMPLE.COM')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $anna->id)
            ->assertJsonPath('data.0.customer.email', 'Anna.Smirnova@Example.COM');
    }

    /**
     * «Точное совпадение» — не поиск подстроки: частичный префикс,
     * адрес с расширением домена и несуществующий адрес дают пустую
     * страницу с валидной обёрткой пагинации (FR-API-02). Красный
     * до T-036.
     */
    public function test_email_filter_requires_full_address_exact_match(): void
    {
        $this->seedOrder(id: 'ord_anna0001', email: 'anna.smirnova@example.com');
        $this->seedOrder(id: 'ord_bob00001', email: 'bob@example.com');

        // Точность: подстрока адреса — не совпадение.
        foreach ([
            'anna.smirnova@example.c',
            'anna.smirnova@example.com.ua',
            'nobody@example.com',
        ] as $email) {
            $this->getJson('/api/orders?email=' . urlencode($email))
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0)
                ->assertJsonPath('meta.current_page', 1);
        }
    }

    /**
     * Email комбинируется со status-фильтром и сохраняется в ссылках
     * пагинации вместе с остальными параметрами (FR-API-02 +
     * FR-API-03, withQueryString; T-011 фиксировал сохранение только
     * status/per_page). Красный до T-036.
     */
    public function test_email_filter_combines_with_status_and_is_kept_in_pagination_links(): void
    {
        $this->seedOrder(id: 'ord_anna_p1', email: 'anna@example.com', status: OrderStatus::PAID, placedAt: '2026-10-02 12:00:00');
        $this->seedOrder(id: 'ord_anna_p2', email: 'anna@example.com', status: OrderStatus::PAID, placedAt: '2026-10-03 12:00:00');
        $this->seedOrder(id: 'ord_anna_c1', email: 'anna@example.com', status: OrderStatus::CREATED);
        $this->seedOrder(id: 'ord_bob_p1', email: 'bob@example.com', status: OrderStatus::PAID);

        $response = $this->getJson('/api/orders?email=anna@example.com&status=paid&per_page=1');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'ord_anna_p2');

        foreach (['first', 'last', 'next'] as $link) {
            parse_str((string) parse_url((string) $response->json("links.{$link}"), PHP_URL_QUERY), $params);

            self::assertSame(
                'anna@example.com',
                $params['email'] ?? null,
                "Ссылка {$link} должна сохранять фильтр email (withQueryString, FR-API-03).",
            );
            self::assertSame(
                'paid',
                $params['status'] ?? null,
                "Ссылка {$link} должна сохранять фильтр status (withQueryString, FR-API-03).",
            );
            self::assertSame(
                '1',
                $params['per_page'] ?? null,
                "Ссылка {$link} должна сохранять per_page (withQueryString, FR-API-03).",
            );
        }
    }

    /**
     * Пробельные края входа не мешают точному совпадению: значение
     * фильтра нормализуется trim + lower (A-30) до сравнения с
     * `customer_email_normalized`. Добавлено в T-036 вместе с
     * реализацией фильтра (край входной нормализации).
     */
    public function test_email_filter_trims_input_value_before_matching(): void
    {
        $anna = $this->seedOrder(id: 'ord_anna0001', email: 'Anna.Smirnova@Example.COM');
        $this->seedOrder(id: 'ord_bob00001', email: 'bob@example.com');

        $this->getJson('/api/orders?email=' . urlencode('  Anna.Smirnova@Example.COM  '))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $anna->id);
    }

    /**
     * Заказ с заданным email; normalized-адрес фабрика вычисляет сама.
     */
    private function seedOrder(string $id, string $email, ?OrderStatus $status = null, ?string $placedAt = null): Order
    {
        return Order::factory()->create([
            'id' => $id,
            'customer_email' => $email,
            'status' => $status ?? OrderStatus::CREATED,
            'placed_at' => CarbonImmutable::parse($placedAt ?? '2026-10-02 12:00:00'),
        ]);
    }
}
