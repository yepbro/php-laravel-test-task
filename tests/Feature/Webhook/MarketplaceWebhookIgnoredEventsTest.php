<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Enums\EventOutcome;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\MutatedWebhookBody;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-017. HTTP-контракт событий неизвестного типа/статуса: 200 ignored,
 * событие сохранено в журнал, заказ/история/письма не изменились
 * (FR-WH-07, шаги 8 и 10 §7).
 *
 * Сценарии §8: S-18 (неизвестный type, в т.ч. без data.order, и его
 * повтор → duplicate), S-36 (неизвестный строковый status у известного
 * типа). Отличие от 422: базовый конверт валиден, расширение протокола
 * (T9) не должно вызывать бесполезные повторные доставки.
 *
 * Зелёные с T-021+T-024+T-026: шаги 8/10 §7 не требуют блокировки
 * заказа и применения снимка (T-027+). Warning-логи unknown_type/
 * unknown_status ассертятся здесь по остатку задачи 017 (NFR-06:
 * владельцы позеленения добавляют ассерты логов в свои наборы).
 */
class MarketplaceWebhookIgnoredEventsTest extends TestCase
{
    use RefreshDatabase;
    use PostsSignedWebhooks;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    /** Событие order-paid из примеров: id для мутантов неизвестного типа. */
    private const string PAID_EVENT_ID = 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC';

    private const string ORDER_ID = 'ord_7Q2KX9M4';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
            'app.debug' => false,
        ]);

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * S-18: неизвестный тип (order.refunded) при валидном конверте →
     * 200 ignored; событие сохранено с outcome=ignored и order_id=NULL
     * (шаг 8 §7 — даже если data.order в теле есть); ни заказов, ни
     * истории, ни писем.
     */
    public function test_unknown_type_is_ignored_and_stored_without_order_changes(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertStringContainsString('unknown_type', $message);

                return $context === [
                    'reason' => 'unknown_type',
                    'event_id' => self::PAID_EVENT_ID,
                    'type' => 'order.refunded',
                ];
            });

        $body = MutatedWebhookBody::fromExample('order-paid')
            ->withType('order.refunded')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $response->assertOk();
        $this->assertSame(['status' => 'ignored'], $response->json(), 'Тело 200 — {"status": "ignored"} (FR-WH-10).');
        $this->assertDatabaseHas('webhook_events', [
            'id' => self::PAID_EVENT_ID,
            'type' => 'order.refunded',
            'order_id' => null,
            'outcome' => EventOutcome::IGNORED->value,
            'deliveries' => 1,
        ]);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertUnknownEventChangedNothing();
    }

    /**
     * S-18: неизвестный тип без data.order вовсе (валидный базовый
     * конверт) — тоже 200 ignored с сохранением события: формат
     * data.order для неизвестного типа не проверяется (FR-WH-06).
     */
    public function test_unknown_type_without_order_is_still_ignored(): void
    {
        $body = '{"id":"evt_wo_order","type":"order.refunded","created_at":"2026-10-21T10:05:48+00:00","data":{}}';

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $response->assertOk();
        $this->assertSame(['status' => 'ignored'], $response->json());
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_wo_order',
            'type' => 'order.refunded',
            'order_id' => null,
            'outcome' => EventOutcome::IGNORED->value,
        ]);
        $this->assertUnknownEventChangedNothing();
    }

    /**
     * S-18 (хвост): повторная доставка события неизвестного типа — общая
     * дедупликация по event.id (FR-WH-07): 200 duplicate, deliveries=2,
     * последняя доставка зафиксирована; заказ по-прежнему не тронут.
     */
    public function test_unknown_type_redelivery_answers_duplicate(): void
    {
        // Ровно один warning — о первой доставке unknown-типа; повтор
        // того же тела не порождает duplicate_payload_mismatch (хеш
        // совпадает) и не дублирует warning (FR-ORD-01).
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn(string $message, array $context): bool => $context['reason'] === 'unknown_type'
                && $context['event_id'] === self::PAID_EVENT_ID);

        $body = MutatedWebhookBody::fromExample('order-paid')
            ->withType('order.refunded')
            ->body();

        $first = $this->postSignedWebhook(SignedWebhook::forBody($body));
        $this->assertSame(['status' => 'ignored'], $first->json());

        // Повтор идёт с НОВЫМ timestamp и подписью (T4 протокола).
        $timestamp = CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp() + 7;
        $second = $this->postSignedWebhook(SignedWebhook::forBody($body, $timestamp));

        $second->assertOk();
        $this->assertSame(['status' => 'duplicate'], $second->json(), 'Повтор unknown-события — 200 duplicate.');
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'id' => self::PAID_EVENT_ID,
            'outcome' => EventOutcome::IGNORED->value,
            'deliveries' => 2,
        ]);

        $event = WebhookEvent::query()->findOrFail(self::PAID_EVENT_ID);
        $this->assertNotNull($event->last_received_at, 'Повторная доставка фиксируется в last_received_at.');
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * S-36: известный тип (order.updated), строковый статус вне enum
     * ('returned') → 200 ignored; событие сохранено с order_id из
     * конверта; существующий заказ, его история и письма не изменились.
     */
    public function test_unknown_status_is_ignored_and_keeps_existing_order(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertStringContainsString('unknown_status', $message);

                return $context === [
                    'reason' => 'unknown_status',
                    'event_id' => 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
                    'order_id' => self::ORDER_ID,
                ];
            });

        $order = Order::factory()->create([
            'id' => self::ORDER_ID,
            'status' => OrderStatus::PAID,
            'total' => '5000.00',
            'paid_at' => '2026-10-21 10:05:47',
            'source_updated_at' => '2026-10-21 10:05:47',
            'version_event_created_at' => '2026-10-21 10:05:48',
            'version_event_id' => self::PAID_EVENT_ID,
        ]);

        $body = MutatedWebhookBody::fromExample('order-updated')
            ->withStatus('returned')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $response->assertOk();
        $this->assertSame(['status' => 'ignored'], $response->json());
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            'type' => 'order.updated',
            'order_id' => self::ORDER_ID,
            'outcome' => EventOutcome::IGNORED->value,
        ]);

        $order->refresh();
        $this->assertSame(OrderStatus::PAID, $order->status, 'Неизвестный статус не меняет заказ.');
        $this->assertSame('5000.00', $order->total);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_status_history', 0);
        $this->assertDatabaseCount('order_notifications', 0);
    }

    /**
     * Событие неизвестного типа/статуса не создаёт заказов и их
     * производных (журнал уже проверен тестом отдельно).
     */
    private function assertUnknownEventChangedNothing(): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_status_history', 0);
        $this->assertDatabaseCount('order_notifications', 0);
    }
}
