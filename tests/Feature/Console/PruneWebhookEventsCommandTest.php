<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EventOutcome;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Очистка журнала сырых событий (Q20): удаляются только события старше
 * retention-дней от received_at; свежие и повторно доставленные
 * остаются, исход (outcome) на удаление не влияет.
 */
class PruneWebhookEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const RETENTION_DAYS = 90;

    public function test_prunes_only_events_older_than_retention(): void
    {
        $old = WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS + 1),
        ]);
        $fresh = WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS - 1),
        ]);

        $this->artisan('webhooks:prune')
            ->expectsOutputToContain('Удалено событий старше ' . self::RETENTION_DAYS . ' дн.: 1')
            ->assertSuccessful();

        $this->assertNull(WebhookEvent::query()->find($old->id));
        $this->assertNotNull(WebhookEvent::query()->find($fresh->id));
    }

    public function test_prunes_regardless_of_outcome(): void
    {
        $oldApplied = WebhookEvent::factory()->create([
            'outcome' => EventOutcome::APPLIED,
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS + 10),
        ]);
        $oldDuplicateDelivery = WebhookEvent::factory()->create([
            'outcome' => EventOutcome::IGNORED,
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS + 10),
        ]);

        $this->artisan('webhooks:prune')->assertSuccessful();

        $this->assertNull(WebhookEvent::query()->find($oldApplied->id));
        $this->assertNull(WebhookEvent::query()->find($oldDuplicateDelivery->id));
    }

    /**
     * Возраст — от received_at (первой приёмки): повторная доставка не
     * продлевает срок хранения сырого тела (решение T-012).
     */
    public function test_age_is_counted_from_first_receipt_not_last_delivery(): void
    {
        $oldWithRecentRedelivery = WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS + 5),
            'last_received_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->artisan('webhooks:prune')->assertSuccessful();

        $this->assertNull(WebhookEvent::query()->find($oldWithRecentRedelivery->id));
    }

    public function test_reports_zero_when_nothing_to_delete(): void
    {
        WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->artisan('webhooks:prune')
            ->expectsOutputToContain('Удалено событий старше ' . self::RETENTION_DAYS . ' дн.: 0')
            ->assertSuccessful();
    }

    public function test_retention_follows_config_override(): void
    {
        config()->set('marketplace.events_retention_days', 30);

        $monthOld = WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDays(31),
        ]);
        $weekOld = WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDays(7),
        ]);

        $this->artisan('webhooks:prune')
            ->expectsOutputToContain('Удалено событий старше 30 дн.: 1')
            ->assertSuccessful();

        $this->assertNull(WebhookEvent::query()->find($monthOld->id));
        $this->assertNotNull(WebhookEvent::query()->find($weekOld->id));
    }
}
