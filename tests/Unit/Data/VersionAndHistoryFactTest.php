<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\HistoryFact;
use App\Data\Version;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * DTO версии заказа и факта истории (T-009).
 */
class VersionAndHistoryFactTest extends TestCase
{
    public function test_version_holds_comparison_tuple(): void
    {
        $orderUpdatedAt = CarbonImmutable::parse('2026-10-22T06:40:03+00:00');
        $eventCreatedAt = CarbonImmutable::parse('2026-10-22T06:40:04+00:00');
        $version = new Version($orderUpdatedAt, $eventCreatedAt, 'evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0');

        $this->assertSame($orderUpdatedAt, $version->orderUpdatedAt);
        $this->assertSame($eventCreatedAt, $version->eventCreatedAt);
        $this->assertSame('evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0', $version->eventId);
    }

    /**
     * Компоненты-даты — моменты: +03:00 и +00:00 могут задавать один
     * и тот же момент (S-19), нормализация — в компараторе (T-023).
     */
    public function test_version_dates_keep_source_offsets(): void
    {
        $version = new Version(
            CarbonImmutable::parse('2026-10-21T13:05:47+03:00'),
            CarbonImmutable::parse('2026-10-21T10:05:48+00:00'),
            'evt_offsets',
        );

        $this->assertSame('+03:00', $version->orderUpdatedAt->getTimezone()->getName());
        $this->assertSame('+00:00', $version->eventCreatedAt->getTimezone()->getName());
    }

    public function test_history_fact_holds_status_and_moment(): void
    {
        $at = CarbonImmutable::parse('2026-10-22T06:40:03+00:00');
        $fact = new HistoryFact(OrderStatus::SHIPPED, $at);

        $this->assertSame(OrderStatus::SHIPPED, $fact->status);
        $this->assertSame($at, $fact->at);
    }
}
