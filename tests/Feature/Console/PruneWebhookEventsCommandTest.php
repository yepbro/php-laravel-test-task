<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EventOutcome;
use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    /**
     * Граница retention: событие ровно на пороге (received_at == now −
     * N дней) ещё НЕ удаляется — удаление строго «старше порога»
     * (`received_at < threshold`), секундой старше — удаляется.
     * Время заморожено travelTo: обе стороны границы детерминированы,
     * гонки между фабрикой и командой нет.
     */
    public function test_boundary_event_exactly_at_retention_is_kept(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00'));

        $exactlyOnThreshold = WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS),
        ]);
        $oneSecondOlder = WebhookEvent::factory()->create([
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS)->subSecond(),
        ]);

        $this->artisan('webhooks:prune')->assertSuccessful();

        $this->assertNotNull(
            WebhookEvent::query()->find($exactlyOnThreshold->id),
            'Ровно на пороге retention — ещё хранится (удаление строго старше).',
        );
        $this->assertNull(
            WebhookEvent::query()->find($oneSecondOlder->id),
            'Секундой старше порога — удаляется.',
        );
    }

    /**
     * Событие связано с заказом мягкой ссылкой без FK (решение T-003):
     * удаление устаревшего события не трогает сам заказ и его outbox-
     * записи — сырой журнал живёт по retention-политике, доменные
     * данные по своей. Непустой прогон пишет info-токен
     * webhook_events_pruned (NFR-06).
     */
    public function test_pruned_event_keeps_referenced_order_and_its_notifications(): void
    {
        config()->set('marketplace.events_retention_days', self::RETENTION_DAYS);
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertStringContainsString('webhook_events_pruned', $message);

                return $context['reason'] === 'webhook_events_pruned'
                    && $context['deleted'] === 1
                    && $context['retention_days'] === self::RETENTION_DAYS;
            });

        $order = Order::factory()->paid()->create();
        $notification = OrderNotification::factory()->forOrder($order)->create([
            'type' => NotificationType::PAID,
        ]);
        $oldEvent = WebhookEvent::factory()->forOrder($order)->create([
            'received_at' => CarbonImmutable::now()->subDays(self::RETENTION_DAYS + 1),
        ]);

        $this->artisan('webhooks:prune')->assertSuccessful();

        $this->assertNull(WebhookEvent::query()->find($oldEvent->id));
        $this->assertNotNull(Order::query()->find($order->id));
        $this->assertNotNull($notification->fresh());
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
