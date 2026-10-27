<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OpenApiSchema;
use Tests\TestCase;

/**
 * Контрактная валидация карточки GET /api/orders/{id} по
 * task/openapi.yaml (T-018, FR-API-10, §11 ТЗ; S-25/S-26).
 *
 * Ответ {"data": Order} проверяется целиком против JSON Schema
 * 2020-12 из контракта (Tests\Support\OpenApiSchema): транзитивно
 * валидируются required/nullable-поля, items[].subtotal, shipment,
 * даты и history[]. Здесь же недостающие T-011 края карточки:
 * все nullable одновременно null (created-заказ), ветка отмены,
 * одинаковые SKU и валюта USD (S-26), JSON-ответ 200 без Accept.
 *
 * Значения полей, subtotal = price × quantity, хронология истории,
 * tie-break по рангу статуса, shipment-объект и 404 — зелёные
 * тесты T-011 (OrderShowApiTest), не дублируются.
 */
class OrderShowContractApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_response_matches_openapi_contract(): void
    {
        $order = $this->seedPaidOrder();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        OpenApiSchema::assertResponseMatchesSchema(
            $response,
            OpenApiSchema::ORDER_RESPONSE,
            'карточки заказа',
        );
    }

    /**
     * FR-API-05: nullable-поля присутствуют ВСЕГДА — край «все пять
     * одновременно null» (created-заказ без телефона и истории);
     * пустая history — допустимый край контракта, items — с одной
     * позицией (заодно край subtotal на created-заказе).
     */
    public function test_created_order_has_all_nullable_fields_null_and_matches_contract(): void
    {
        $order = Order::factory()
            ->withoutPhone()
            ->withItems(1)
            ->create(['id' => 'ord_created01']);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.customer.phone', null)
            ->assertJsonPath('data.shipment', null)
            ->assertJsonPath('data.paid_at', null)
            ->assertJsonPath('data.cancelled_at', null)
            ->assertJsonPath('data.cancel_reason', null)
            ->assertJsonCount(0, 'data.history');

        OpenApiSchema::assertResponseMatchesSchema(
            $response,
            OpenApiSchema::ORDER_RESPONSE,
            'карточки созданного заказа',
        );
    }

    /**
     * Ветка отмены (S-08): cancelled_at — дата MarketHub в UTC без
     * долей секунды (FR-API-06, A-21), cancel_reason — строка.
     */
    public function test_cancelled_order_returns_cancel_fields_and_matches_contract(): void
    {
        $order = Order::factory()
            ->cancelled()
            ->withHistory()
            ->create([
                'id' => 'ord_cancel01',
                'cancelled_at' => CarbonImmutable::parse('2026-10-21 10:30:00 UTC'),
                'cancel_reason' => 'Заказ отменён покупателем',
            ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_at', '2026-10-21T10:30:00+00:00')
            ->assertJsonPath('data.cancel_reason', 'Заказ отменён покупателем');

        OpenApiSchema::assertResponseMatchesSchema(
            $response,
            OpenApiSchema::ORDER_RESPONSE,
            'карточки отменённого заказа',
        );
    }

    /**
     * S-26: несколько позиций с одинаковым SKU и валюта USD
     * сохраняются и отдаются без искажений — обе позиции на месте
     * в порядке position, currency неизменна.
     */
    public function test_same_sku_items_and_foreign_currency_survive_roundtrip(): void
    {
        $order = Order::factory()->create([
            'id' => 'ord_sku00001',
            'currency' => 'USD',
        ]);

        OrderItem::factory()->createMany([
            [
                'order_id' => $order->id,
                'position' => 1,
                'sku' => 'TSHIRT-BLK-M',
                'name' => 'Футболка базовая, чёрная, M',
                'quantity' => 2,
                'price' => '1499.90',
            ],
            [
                'order_id' => $order->id,
                'position' => 2,
                'sku' => 'TSHIRT-BLK-M',
                'name' => 'Футболка базовая, чёрная, M, подарок',
                'quantity' => 3,
                'price' => '1499.90',
            ],
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.sku', 'TSHIRT-BLK-M')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.1.sku', 'TSHIRT-BLK-M')
            ->assertJsonPath('data.items.1.quantity', 3);

        OpenApiSchema::assertResponseMatchesSchema(
            $response,
            OpenApiSchema::ORDER_RESPONSE,
            'карточки заказа с одинаковыми SKU и валютой USD',
        );
    }

    /**
     * FR-API-09 для 200 карточки: ответ JSON без заголовка Accept
     * (T-011 фиксировал список-200/422 и карточку-404).
     */
    public function test_show_returns_json_without_accept_header(): void
    {
        $order = $this->seedPaidOrder();

        $response = $this->call('GET', "/api/orders/{$order->id}");

        $response->assertStatus(200);
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
