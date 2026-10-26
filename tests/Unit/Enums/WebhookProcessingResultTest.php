<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\EventOutcome;
use App\Enums\WebhookProcessingResult;
use Tests\TestCase;

/**
 * WebhookProcessingResult (T-010): HTTP-словарь исходов FR-WH-10 шире
 * журнального EventOutcome — `applied` отдаётся как `processed`,
 * `duplicate` существует только в HTTP-ответе повторной доставке.
 */
class WebhookProcessingResultTest extends TestCase
{
    public function test_values_match_http_contract(): void
    {
        $this->assertSame(
            ['processed', 'duplicate', 'stale', 'ignored', 'rejected'],
            array_map(static fn(WebhookProcessingResult $result): string => $result->value, WebhookProcessingResult::cases()),
        );
    }

    public function test_from_outcome_maps_journal_to_http_vocabulary(): void
    {
        $this->assertSame(WebhookProcessingResult::PROCESSED, WebhookProcessingResult::fromOutcome(EventOutcome::APPLIED));
        $this->assertSame(WebhookProcessingResult::STALE, WebhookProcessingResult::fromOutcome(EventOutcome::STALE));
        $this->assertSame(WebhookProcessingResult::IGNORED, WebhookProcessingResult::fromOutcome(EventOutcome::IGNORED));
        $this->assertSame(WebhookProcessingResult::REJECTED, WebhookProcessingResult::fromOutcome(EventOutcome::REJECTED));
    }

    /**
     * `duplicate` не переводится из EventOutcome (FR-ORD-01/08): это ответ
     * повторной доставке, а не исход записи журнала — маппинг из БД его
     * породить не может.
     */
    public function test_from_outcome_cannot_produce_duplicate(): void
    {
        $mapped = array_map(
            static fn(EventOutcome $outcome): WebhookProcessingResult => WebhookProcessingResult::fromOutcome($outcome),
            EventOutcome::cases(),
        );

        $this->assertNotContains(WebhookProcessingResult::DUPLICATE, $mapped);
    }
}
