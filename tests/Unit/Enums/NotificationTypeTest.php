<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\NotificationType;
use PHPUnit\Framework\TestCase;
use ValueError;

class NotificationTypeTest extends TestCase
{
    /**
     * Полный состав case'ов: типы писем из §6.5 ТЗ, колонка
     * order_notifications.type (VARCHAR(16)).
     */
    public function test_cases_match_database_and_api_values(): void
    {
        $this->assertSame(
            ['paid', 'shipped', 'cancelled'],
            array_map(static fn(NotificationType $type): string => $type->value, NotificationType::cases()),
        );
    }

    public function test_is_backed_by_string(): void
    {
        $this->assertSame('string', (new \ReflectionEnum(NotificationType::class))->getBackingType()?->getName());
    }

    public function test_values_fit_into_type_column(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->assertLessThanOrEqual(16, mb_strlen($type->value));
        }
    }

    public function test_from_maps_known_values_to_cases(): void
    {
        $this->assertSame(NotificationType::PAID, NotificationType::from('paid'));
        $this->assertSame(NotificationType::SHIPPED, NotificationType::from('shipped'));
        $this->assertSame(NotificationType::CANCELLED, NotificationType::from('cancelled'));
    }

    /** Несуществующее значение не маскируется приведением типа. */
    public function test_from_unknown_value_throws_value_error(): void
    {
        $this->expectException(ValueError::class);

        NotificationType::from('created');
    }

    public function test_try_from_unknown_value_returns_null(): void
    {
        $this->assertNull(NotificationType::tryFrom('created'));
        $this->assertNull(NotificationType::tryFrom('delivered'));
    }
}
