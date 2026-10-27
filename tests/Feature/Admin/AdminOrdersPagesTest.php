<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia-оболочки админки (T-013/T-039, §6.7 ТЗ, Q3-гибрид):
 *
 * - `/` перенаправляет на список заказов (FR-ADM-01);
 * - страницы списка и карточки отвечают Inertia-компонентами;
 * - props — ТОЛЬКО вспомогательные данные (FR-ADM-05): опции фильтра
 *   статусов с русскими подписями и дефолт per_page для списка, id из
 *   URL для карточки. Заказы через props не передаются вовсе;
 * - карточка-оболочка не выбирает заказ из БД: несуществующий id —
 *   всё равно 200, «заказ не найден» — клиентское состояние по 404
 *   от GET /api/orders/{id} (FR-ADM-04).
 *
 * Точный состав props пиннится явной проверкой ключей через макрос
 * inertiaProps(): assertInertia в inertia-laravel v3 НЕ валидирует
 * полноту взаимодействия (interacted()/etc() не вызываются), поэтому
 * where()/missing() одни не уронили бы тест при появлении лишнего
 * prop'а с заказами.
 *
 * T-039 дополняет инварианты гибрида со стороны серверной части
 * списка: наличие заказов в БД не расширяет состав props, а
 * query-string админ-URL (status/email/page/per_page — состояние
 * клиентского слоя) контроллером не читается и не валидируется —
 * тонкая оболочка остаётся тонкой при любых параметрах адреса.
 */
class AdminOrdersPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function expectedStatusOptions(): array
    {
        return [
            ['value' => 'created', 'label' => 'Создан'],
            ['value' => 'paid', 'label' => 'Оплачен'],
            ['value' => 'shipped', 'label' => 'Отправлен'],
            ['value' => 'delivered', 'label' => 'Доставлен'],
            ['value' => 'cancelled', 'label' => 'Отменён'],
        ];
    }
    public function test_root_redirects_to_admin_orders_list(): void
    {
        $this->get('/')->assertRedirect('/admin/orders');
        $this->get(route('home'))->assertRedirect('/admin/orders');
    }

    public function test_orders_list_page_renders_inertia_with_helper_props_only(): void
    {
        $response = $this->get('/admin/orders');

        $response->assertOk()->assertInertia(
            fn(AssertableInertia $page) => $page
                ->component('admin/orders/Index')
                ->where('statusOptions', self::expectedStatusOptions())
                ->where('defaultPerPage', 20),
        );

        // Точный состав props страницы: shared-пропы middleware и ровно
        // два вспомогательных; orders/order и пр. сюда не попадают.
        self::assertEqualsCanonicalizing(
            ['errors', 'name', 'statusOptions', 'defaultPerPage'],
            array_keys($response->inertiaProps()),
        );
    }

    public function test_orders_list_props_do_not_contain_orders_data(): void
    {
        $this->get('/admin/orders')
            ->assertOk()
            ->assertInertia(
                fn(AssertableInertia $page) => $page
                    ->component('admin/orders/Index')
                    ->missing('orders')
                    ->missing('order')
                    ->missing('data')
                    ->missing('meta'),
            );
    }

    public function test_order_page_shell_passes_only_id_and_renders_without_database_lookup(): void
    {
        $response = $this->get('/admin/orders/ord_does-not-exist');

        // несуществующий id: оболочка всё равно 200 — данные карточки
        // браузер запрашивает сам, 404 станет клиентским состоянием
        $response->assertOk()->assertInertia(
            fn(AssertableInertia $page) => $page
                ->component('admin/orders/Show')
                ->where('id', 'ord_does-not-exist'),
        );

        self::assertEqualsCanonicalizing(
            ['errors', 'name', 'id'],
            array_keys($response->inertiaProps()),
        );
    }

    public function test_order_page_shell_props_do_not_contain_order_data(): void
    {
        $this->get('/admin/orders/ord_7Q2KX9M4')
            ->assertOk()
            ->assertInertia(
                fn(AssertableInertia $page) => $page
                    ->component('admin/orders/Show')
                    ->missing('order')
                    ->missing('orders'),
            );
    }

    /**
     * T-039: гибрид не деградирует при наличии данных — даже когда в
     * БД есть заказы, оболочка списка не начинает передавать их через
     * props; состав остаётся ровно вспомогательным (FR-ADM-05).
     */
    public function test_orders_list_props_stay_helper_only_when_orders_exist(): void
    {
        Order::factory()->count(3)->create();

        $response = $this->get('/admin/orders');

        $response->assertOk()->assertInertia(
            fn(AssertableInertia $page) => $page
                ->component('admin/orders/Index')
                ->where('statusOptions', self::expectedStatusOptions())
                ->where('defaultPerPage', 20)
                ->missing('orders')
                ->missing('data')
                ->missing('meta'),
        );

        self::assertEqualsCanonicalizing(
            ['errors', 'name', 'statusOptions', 'defaultPerPage'],
            array_keys($response->inertiaProps()),
        );
    }

    /**
     * T-039: query-string админ-URL — состояние клиентского слоя
     * (status/email/page/per_page), а не серверный вход. Оболочка не
     * валидирует и не читает эти параметры: любой набор фильтров даёт
     * те же вспомогательные props (данные выберет GET /api/orders
     * из браузера, мусор из URL обработает клиент).
     */
    public function test_orders_list_ignores_admin_query_string(): void
    {
        $response = $this->get(
            '/admin/orders?status=paid&email=buyer%40example.com&page=7&per_page=5',
        );

        $response->assertOk()->assertInertia(
            fn(AssertableInertia $page) => $page
                ->component('admin/orders/Index')
                ->where('statusOptions', self::expectedStatusOptions())
                ->where('defaultPerPage', 20)
                ->missing('orders')
                ->missing('filters')
                ->missing('page'),
        );

        self::assertEqualsCanonicalizing(
            ['errors', 'name', 'statusOptions', 'defaultPerPage'],
            array_keys($response->inertiaProps()),
        );
    }
}
