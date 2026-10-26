<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\EventOutcome;
use PHPUnit\Framework\TestCase;
use ValueError;

class EventOutcomeTest extends TestCase
{
    /**
     * Полный состав case'ов: исходы FR-ORD-08 ТЗ, колонка
     * webhook_events.outcome (VARCHAR(16)).
     */
    public function test_cases_match_database_and_api_values(): void
    {
        $this->assertSame(
            ['applied', 'stale', 'ignored', 'rejected'],
            array_map(static fn(EventOutcome $outcome): string => $outcome->value, EventOutcome::cases()),
        );
    }

    public function test_is_backed_by_string(): void
    {
        $this->assertSame('string', (new \ReflectionEnum(EventOutcome::class))->getBackingType()?->getName());
    }

    public function test_values_fit_into_outcome_column(): void
    {
        foreach (EventOutcome::cases() as $outcome) {
            $this->assertLessThanOrEqual(16, mb_strlen($outcome->value));
        }
    }

    public function test_from_maps_known_values_to_cases(): void
    {
        $this->assertSame(EventOutcome::APPLIED, EventOutcome::from('applied'));
        $this->assertSame(EventOutcome::STALE, EventOutcome::from('stale'));
        $this->assertSame(EventOutcome::IGNORED, EventOutcome::from('ignored'));
        $this->assertSame(EventOutcome::REJECTED, EventOutcome::from('rejected'));
    }

    /** Несуществующее значение не маскируется приведением типа. */
    public function test_from_unknown_value_throws_value_error(): void
    {
        $this->expectException(ValueError::class);

        EventOutcome::from('accepted');
    }

    /**
     * duplicate — только HTTP-ответ повторной доставке (FR-ORD-01/FR-ORD-08):
     * в журнале webhook_events исходом быть не может и в enum не входит.
     */
    public function test_duplicate_is_not_a_storable_outcome(): void
    {
        $this->assertNull(EventOutcome::tryFrom('duplicate'));
    }

    public function test_try_from_unknown_value_returns_null(): void
    {
        $this->assertNull(EventOutcome::tryFrom('processed'));
        $this->assertNull(EventOutcome::tryFrom(''));
    }
}
