<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\NotificationStatus;
use PHPUnit\Framework\TestCase;
use ValueError;

class NotificationStatusTest extends TestCase
{
    /**
     * Полный состав case'ов: статусы уведомлений из §6.5 ТЗ, колонка
     * order_notifications.status (VARCHAR(16)).
     */
    public function test_cases_match_database_and_api_values(): void
    {
        $this->assertSame(
            ['pending', 'sending', 'sent', 'skipped', 'failed'],
            array_map(static fn(NotificationStatus $status): string => $status->value, NotificationStatus::cases()),
        );
    }

    public function test_is_backed_by_string(): void
    {
        $this->assertSame('string', (new \ReflectionEnum(NotificationStatus::class))->getBackingType()?->getName());
    }

    public function test_values_fit_into_status_column(): void
    {
        foreach (NotificationStatus::cases() as $status) {
            $this->assertLessThanOrEqual(16, mb_strlen($status->value));
        }
    }

    public function test_from_maps_known_values_to_cases(): void
    {
        $this->assertSame(NotificationStatus::PENDING, NotificationStatus::from('pending'));
        $this->assertSame(NotificationStatus::SENDING, NotificationStatus::from('sending'));
        $this->assertSame(NotificationStatus::SENT, NotificationStatus::from('sent'));
        $this->assertSame(NotificationStatus::SKIPPED, NotificationStatus::from('skipped'));
        $this->assertSame(NotificationStatus::FAILED, NotificationStatus::from('failed'));
    }

    /** Несуществующее значение не маскируется приведением типа. */
    public function test_from_unknown_value_throws_value_error(): void
    {
        $this->expectException(ValueError::class);

        NotificationStatus::from('delivered');
    }

    public function test_try_from_unknown_value_returns_null(): void
    {
        $this->assertNull(NotificationStatus::tryFrom('delivered'));
        $this->assertNull(NotificationStatus::tryFrom('duplicate'));
    }
}
