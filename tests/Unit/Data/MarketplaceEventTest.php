<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\MarketplaceEvent;
use App\Data\OrderCustomer;
use App\Data\OrderSnapshot;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * DTO конверта события (T-009): строгие типы, readonly, момент времени
 * с исходным смещением, снимок опционален (неизвестный тип/статус).
 */
class MarketplaceEventTest extends TestCase
{
    public function test_holds_envelope_fields(): void
    {
        $createdAt = CarbonImmutable::parse('2026-10-21T10:05:48+00:00');
        $event = new MarketplaceEvent(
            id: 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            type: 'order.paid',
            createdAt: $createdAt,
            order: null,
        );

        $this->assertSame('evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC', $event->id);
        $this->assertSame('order.paid', $event->type);
        $this->assertSame($createdAt, $event->createdAt);
        $this->assertNull($event->order);
    }

    public function test_created_at_is_immutable_and_keeps_offset(): void
    {
        // Примеры событий содержат +03:00 и +00:00 (S-19): DTO хранит
        // момент с исходным смещением, сравнение — как моментов.
        $event = new MarketplaceEvent(
            id: 'evt_offset',
            type: 'order.updated',
            createdAt: CarbonImmutable::parse('2026-10-21T13:02:11+03:00'),
            order: null,
        );

        $this->assertInstanceOf(CarbonImmutable::class, $event->createdAt);
        $this->assertSame('+03:00', $event->createdAt->getTimezone()->getName());
        // Один и тот же момент: +03:00 «настенное» = 10:02:11 UTC (FR-ORD-03).
        $this->assertTrue(
            $event->createdAt->equalTo(CarbonImmutable::parse('2026-10-21T10:02:11+00:00')),
        );
    }

    public function test_can_carry_validated_snapshot(): void
    {
        $snapshot = new OrderSnapshot(
            id: 'ord_7Q2KX9M4',
            number: 'MH-1048213',
            status: OrderStatus::PAID,
            currency: 'RUB',
            customer: new OrderCustomer(
                email: 'anna.smirnova@example.com',
                name: 'Анна Смирнова',
                phone: '+79161234567',
            ),
            items: [],
            deliveryPrice: '349.00',
            discount: '200.00',
            total: '4909.50',
            shipment: null,
            paidAt: CarbonImmutable::parse('2026-10-21T13:05:47+03:00'),
            cancelledAt: null,
            cancelReason: null,
            createdAt: CarbonImmutable::parse('2026-10-21T13:02:11+03:00'),
            updatedAt: CarbonImmutable::parse('2026-10-21T13:05:47+03:00'),
        );
        $event = new MarketplaceEvent(
            id: 'evt_with_order',
            type: 'order.paid',
            createdAt: CarbonImmutable::parse('2026-10-21T10:05:48+00:00'),
            order: $snapshot,
        );

        $this->assertSame($snapshot, $event->order);
    }

    public function test_dto_is_readonly_and_final(): void
    {
        $event = new MarketplaceEvent('evt_ro', 'order.paid', CarbonImmutable::now(), null);
        $reflected = new \ReflectionClass(MarketplaceEvent::class);

        $this->assertTrue($reflected->isReadOnly());
        $this->assertTrue($reflected->isFinal());
        $this->expectException(\Error::class);

        $event->id = 'mutated';
    }
}
