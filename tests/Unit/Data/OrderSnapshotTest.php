<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\OrderCustomer;
use App\Data\OrderItemSnapshot;
use App\Data\OrderShipment;
use App\Data\OrderSnapshot;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * DTO снимка заказа (T-009): полный состав полей marketplace-webhooks.md,
 * explicit null semantics, суммы-строки, список позиций.
 */
class OrderSnapshotTest extends TestCase
{
    public function test_holds_full_snapshot_from_example(): void
    {
        $snapshot = self::shippedSnapshot();

        $this->assertSame('ord_7Q2KX9M4', $snapshot->id);
        $this->assertSame('MH-1048213', $snapshot->number);
        $this->assertSame(OrderStatus::SHIPPED, $snapshot->status);
        $this->assertSame('RUB', $snapshot->currency);
        $this->assertSame('anna.smirnova@example.com', $snapshot->customer->email);
        $this->assertSame('349.00', $snapshot->deliveryPrice);
        $this->assertSame('200.00', $snapshot->discount);
        $this->assertSame('4909.50', $snapshot->total);
        $this->assertSame('1098765432', $snapshot->shipment?->trackingNumber);
        $this->assertTrue($snapshot->paidAt->equalTo(CarbonImmutable::parse('2026-10-21T13:05:47+03:00')));
        $this->assertTrue($snapshot->createdAt->equalTo(CarbonImmutable::parse('2026-10-21T13:02:11+03:00')));
        $this->assertTrue($snapshot->updatedAt->equalTo(CarbonImmutable::parse('2026-10-22T06:40:03+00:00')));
    }

    /**
     * Explicit null semantics: order-cancelled.json передаёт phone=null,
     * shipment=null, cancel_reason — строка; nullable-поля остаются
     * nullable-типами, а не строками-заглушками.
     */
    public function test_explicit_nulls_are_preserved(): void
    {
        $snapshot = self::cancelledSnapshot();

        $this->assertNull($snapshot->customer->phone);
        $this->assertNull($snapshot->shipment);
        $this->assertSame('Покупатель отказался от заказа', $snapshot->cancelReason);
        $this->assertNotNull($snapshot->cancelledAt);
        $this->assertInstanceOf(OrderCustomer::class, $snapshot->customer);
    }

    /**
     * Позиции — типизированный список в порядке снимка (FR-ORD-04:
     * порядок сохраняется), quantity — int, price — строка с двумя
     * знаками (NFR-02: float в деньгах запрещён).
     */
    public function test_items_are_typed_list_in_snapshot_order(): void
    {
        $snapshot = self::shippedSnapshot();

        $this->assertCount(2, $snapshot->items);
        [$first, $second] = $snapshot->items;

        $this->assertInstanceOf(OrderItemSnapshot::class, $first);
        $this->assertSame('TSHIRT-BLK-M', $first->sku);
        $this->assertSame('Футболка базовая, чёрная, M', $first->name);
        $this->assertSame(3, $first->quantity);
        $this->assertSame('1290.00', $first->price);
        $this->assertSame('CAP-NVY', $second->sku);
        $this->assertSame('890.50', $second->price);
    }

    public function test_shipment_is_a_value_object(): void
    {
        $shipment = self::shippedSnapshot()->shipment;

        $this->assertInstanceOf(OrderShipment::class, $shipment);
        $this->assertSame('СДЭК', $shipment->carrier);
        $this->assertSame(
            'https://www.cdek.ru/ru/tracking?order_id=1098765432',
            $shipment->trackingUrl,
        );
    }

    public function test_snapshot_is_readonly_and_final(): void
    {
        $reflected = new \ReflectionClass(OrderSnapshot::class);

        $this->assertTrue($reflected->isReadOnly());
        $this->assertTrue($reflected->isFinal());

        $snapshot = self::cancelledSnapshot();

        $this->expectException(\Error::class);

        $snapshot->total = '0.00';
    }

    /**
     * Снимок order-shipped.json: полная отправка, paid_at заполнен,
     * shipment передан, updated_at в +00:00 (S-19 — смешанные пояса).
     */
    private static function shippedSnapshot(): OrderSnapshot
    {
        return new OrderSnapshot(
            id: 'ord_7Q2KX9M4',
            number: 'MH-1048213',
            status: OrderStatus::SHIPPED,
            currency: 'RUB',
            customer: new OrderCustomer(
                email: 'anna.smirnova@example.com',
                name: 'Анна Смирнова',
                phone: '+79161234567',
            ),
            items: [
                new OrderItemSnapshot(
                    sku: 'TSHIRT-BLK-M',
                    name: 'Футболка базовая, чёрная, M',
                    quantity: 3,
                    price: '1290.00',
                ),
                new OrderItemSnapshot(
                    sku: 'CAP-NVY',
                    name: 'Бейсболка "Classic" / синяя',
                    quantity: 1,
                    price: '890.50',
                ),
            ],
            deliveryPrice: '349.00',
            discount: '200.00',
            total: '4909.50',
            shipment: new OrderShipment(
                carrier: 'СДЭК',
                trackingNumber: '1098765432',
                trackingUrl: 'https://www.cdek.ru/ru/tracking?order_id=1098765432',
            ),
            paidAt: CarbonImmutable::parse('2026-10-21T13:05:47+03:00'),
            cancelledAt: null,
            cancelReason: null,
            createdAt: CarbonImmutable::parse('2026-10-21T13:02:11+03:00'),
            updatedAt: CarbonImmutable::parse('2026-10-22T06:40:03+00:00'),
        );
    }

    /**
     * Снимок order-cancelled.json: phone=null, shipment=null, отмена
     * оплаченного заказа с причиной.
     */
    private static function cancelledSnapshot(): OrderSnapshot
    {
        return new OrderSnapshot(
            id: 'ord_3HV8PW2C',
            number: 'MH-1048377',
            status: OrderStatus::CANCELLED,
            currency: 'RUB',
            customer: new OrderCustomer(
                email: 'i.lebedev@example.org',
                name: 'Игорь Лебедев',
                phone: null,
            ),
            items: [
                new OrderItemSnapshot(
                    sku: 'MUG-WHT-350',
                    name: 'Кружка керамическая, белая, 350 мл',
                    quantity: 2,
                    price: '649.00',
                ),
            ],
            deliveryPrice: '0.00',
            discount: '0.00',
            total: '1298.00',
            shipment: null,
            paidAt: CarbonImmutable::parse('2026-10-21T18:11:02+03:00'),
            cancelledAt: CarbonImmutable::parse('2026-10-21T20:47:15+03:00'),
            cancelReason: 'Покупатель отказался от заказа',
            createdAt: CarbonImmutable::parse('2026-10-21T18:09:40+03:00'),
            updatedAt: CarbonImmutable::parse('2026-10-21T20:47:15+03:00'),
        );
    }
}
