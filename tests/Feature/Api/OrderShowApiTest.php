<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-скелет карточки заказа GET /api/orders/{id} (T-011, §6.6 ТЗ):
 * структура контракта Order из task/openapi.yaml — все required-поля,
 * включая nullable, присутствуют всегда (FR-API-05); даты — UTC +00:00
 * без долей секунды (FR-API-06, A-21); created_at/updated_at — даты
 * маркетплейса (FR-API-07); subtotal = price × quantity; несуществующий
 * id — 404 JSON (FR-API-05) независимо от Accept (FR-API-09).
 */
class OrderShowApiTest extends TestCase
{
    use RefreshDatabase;

    /** Регэксп формата дат API: ISO 8601, UTC, без долей секунды (A-21). */
    private const string ISO_UTC = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/';

    public function test_show_returns_full_order_contract_with_nullable_fields_present(): void
    {
        $order = $this->seedPaidOrder();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'id',
                'number',
                'status',
                'customer' => ['email', 'name', 'phone'],
                'currency',
                'items' => [
                    '*' => ['sku', 'name', 'quantity', 'price', 'subtotal'],
                ],
                'delivery_price',
                'discount',
                'total',
                'shipment',
                'paid_at',
                'cancelled_at',
                'cancel_reason',
                'created_at',
                'updated_at',
                'history' => [
                    '*' => ['status', 'at'],
                ],
            ],
        ]);

        // Идентификатор и статус заказа MarketHub (строковый PK).
        $response
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.number', $order->number)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.customer.email', $order->customer_email)
            ->assertJsonPath('data.customer.name', $order->customer_name);

        // Nullable-поля присутствуют всегда — как null (FR-API-05).
        $response
            ->assertJsonPath('data.customer.phone', null)
            ->assertJsonPath('data.shipment', null)
            ->assertJsonPath('data.cancelled_at', null)
            ->assertJsonPath('data.cancel_reason', null);

        // Даты маркетплейса в UTC без долей секунды (FR-API-06/07, A-21).
        $response
            ->assertJsonPath('data.created_at', '2026-10-21T10:02:11+00:00')
            ->assertJsonPath('data.updated_at', '2026-10-21T10:05:47+00:00')
            ->assertJsonPath('data.paid_at', '2026-10-21T10:05:47+00:00');
        self::assertMatchesRegularExpression(self::ISO_UTC, (string) $response->json('data.created_at'));
    }

    public function test_items_are_ordered_by_position_with_exact_subtotal(): void
    {
        $order = $this->seedPaidOrder();

        $items = $order->items->sortBy('position')->values();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        self::assertSame($items->count(), count($response->json('data.items')));

        foreach ($items as $index => $item) {
            self::assertSame($item->sku, $response->json("data.items.{$index}.sku"));
            self::assertSame($item->quantity, $response->json("data.items.{$index}.quantity"));
            self::assertSame($item->price, $response->json("data.items.{$index}.price"));

            $expectedSubtotal = (string) BigDecimal::of($item->price)
                ->multipliedBy($item->quantity)
                ->toScale(2);

            self::assertSame(
                $expectedSubtotal,
                $response->json("data.items.{$index}.subtotal"),
                'subtotal = price × quantity строкой с двумя знаками (FR-API-05/06).',
            );
        }
    }

    public function test_history_is_chronological(): void
    {
        $order = $this->seedPaidOrder();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.history.0.status', 'created')
            ->assertJsonPath('data.history.0.at', '2026-10-21T10:02:11+00:00')
            ->assertJsonPath('data.history.1.status', 'paid')
            ->assertJsonPath('data.history.1.at', '2026-10-21T10:05:47+00:00');
    }

    /**
     * FR-HIS-04: при равном `at` порядок определяется рангом статуса,
     * а не порядком вставки (id). cancelled (ранг 4) вставлен раньше
     * paid (ранг 1) с той же секундой — оплата в выводе раньше отмены.
     */
    public function test_history_ties_are_broken_by_status_rank_not_insert_order(): void
    {
        $order = Order::factory()->create();

        $order->history()->createMany([
            [
                'status' => OrderStatus::CANCELLED,
                'at' => CarbonImmutable::parse('2026-10-21 10:05:47 UTC'),
                'source_event_id' => 'evt_cancelled',
            ],
            [
                'status' => OrderStatus::PAID,
                'at' => CarbonImmutable::parse('2026-10-21 10:05:47 UTC'),
                'source_event_id' => 'evt_paid',
            ],
        ]);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.history.0.status', 'paid')
            ->assertJsonPath('data.history.1.status', 'cancelled');
    }

    public function test_shipment_object_is_present_for_shipped_order(): void
    {
        $order = Order::factory()
            ->shipped('1098765432')
            ->create();

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.shipment.carrier', $order->shipment_carrier)
            ->assertJsonPath('data.shipment.tracking_number', '1098765432')
            ->assertJsonPath('data.shipment.tracking_url', $order->shipment_tracking_url);
    }

    public function test_missing_order_returns_404_json(): void
    {
        $response = $this->getJson('/api/orders/ord_does_not_exist');

        $response->assertStatus(404);
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertArrayHasKey('message', (array) $response->json());
    }

    /**
     * 404 остаётся JSON независимо от Accept (FR-API-09).
     */
    public function test_missing_order_404_stays_json_with_html_accept(): void
    {
        $response = $this->call('GET', '/api/orders/ord_does_not_exist', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

        $response->assertStatus(404);
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    /**
     * Оплаченный заказ без телефона, с двумя позициями и историей
     * (created@placed_at, paid@paid_at — FR-HIS-02).
     */
    private function seedPaidOrder(): Order
    {
        return Order::factory()
            ->paid()
            ->withoutPhone()
            ->withItems(2)
            ->withHistory()
            ->create([
                'id' => 'ord_test0001',
                'placed_at' => CarbonImmutable::parse('2026-10-21 10:02:11 UTC'),
                'paid_at' => CarbonImmutable::parse('2026-10-21 10:05:47 UTC'),
                'source_updated_at' => CarbonImmutable::parse('2026-10-21 10:05:47 UTC'),
            ]);
    }
}
