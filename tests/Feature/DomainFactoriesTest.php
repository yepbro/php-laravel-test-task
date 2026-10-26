<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventOutcome;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNotification;
use App\Models\OrderStatusHistory;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Фабрики доменных моделей (T-006): «одной-двумя строками» создаётся любой
 * типовой заказ/событие/уведомление; значения по умолчанию и states
 * согласованы (статус ⇔ даты ⇔ shipment ⇔ snapshot ⇔ dedupe key),
 * уникальные ограничения не нарушаются, внешние сервисы не привлекаются.
 *
 * Касты и связи моделей проверены в DomainModelsTest (T-005) и здесь
 * не дублируются.
 */
class DomainFactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_defaults_are_consistent_created_order(): void
    {
        $order = Order::factory()->create();

        $this->assertTrue($order->exists);

        // Идентификаторы и форматы.
        $this->assertMatchesRegularExpression('/^ord_[A-Z0-9]{8}$/', $order->id);
        $this->assertMatchesRegularExpression('/^MH-\d{7}$/', $order->number);
        $this->assertContains($order->currency, ['RUB', 'USD', 'EUR']);
        $this->assertSame(3, strlen($order->currency));
        $this->assertSame(OrderStatus::CREATED, $order->status);

        // Нормализованный email = trim + lower от «сырого» email.
        $this->assertSame(mb_strtolower(trim($order->customer_email)), $order->customer_email_normalized);

        // Nullable-поля и shipment-тройка (§6.3: все три NULL ⇔ shipment = null).
        $this->assertNull($order->paid_at);
        $this->assertNull($order->cancelled_at);
        $this->assertNull($order->cancel_reason);
        $this->assertNull($order->shipment_carrier);
        $this->assertNull($order->shipment_tracking_number);
        $this->assertNull($order->shipment_tracking_url);

        // Суммы из БД — строки ровно с двумя знаками (decimal:2).
        $stored = $order->fresh();
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $stored->delivery_price);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $stored->discount);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $stored->total);

        // Даты MarketHub — моменты UTC.
        $this->assertSame(0, $order->placed_at->offset);
        $this->assertSame(0, $order->source_updated_at->offset);
        $this->assertSame(0, $order->version_event_created_at->offset);

        // Версия снимка: событие на секунду позже updated_at (как в конверте).
        $this->assertTrue($order->version_event_created_at->equalTo($order->source_updated_at->addSecond()));
        $this->assertStringStartsWith('evt_', $order->version_event_id);
    }

    public function test_order_snapshot_mirrors_row_fields(): void
    {
        $order = Order::factory()->create();

        $snapshot = $order->fresh()->snapshot;

        $this->assertSame($order->id, $snapshot['id']);
        $this->assertSame($order->number, $snapshot['number']);
        $this->assertSame('created', $snapshot['status']);
        $this->assertSame($order->currency, $snapshot['currency']);
        $this->assertSame($order->customer_email, $snapshot['customer']['email']);
        $this->assertSame($order->customer_name, $snapshot['customer']['name']);
        $this->assertSame($order->customer_phone, $snapshot['customer']['phone']);
        $this->assertSame($order->delivery_price, $snapshot['delivery_price']);
        $this->assertSame($order->discount, $snapshot['discount']);
        $this->assertSame($order->total, $snapshot['total']);
        $this->assertNull($snapshot['shipment']);
        $this->assertNull($snapshot['paid_at']);
        $this->assertNull($snapshot['cancelled_at']);
        $this->assertNull($snapshot['cancel_reason']);
        $this->assertSame([], $snapshot['items']);
        $this->assertSame($this->iso($order->placed_at), $snapshot['created_at']);
        $this->assertSame($this->iso($order->source_updated_at), $snapshot['updated_at']);
    }

    public function test_order_money_overrides_come_back_as_two_decimal_strings(): void
    {
        $order = Order::factory()->create([
            'total' => 100,
            'delivery_price' => '5.5',
            'discount' => 0,
        ]);

        $stored = $order->fresh();

        $this->assertSame('100.00', $stored->total);
        $this->assertSame('5.50', $stored->delivery_price);
        $this->assertSame('0.00', $stored->discount);
        $this->assertSame('100.00', $stored->snapshot['total']);
    }

    public function test_order_email_normalization_follows_override_but_stays_overridable(): void
    {
        $withSpaces = Order::factory()->create(['customer_email' => '  Anna.Smirnova@Example.COM  ']);

        // «Сырой» email хранится как пришёл, нормализованный — trim + lower.
        $this->assertSame('  Anna.Smirnova@Example.COM  ', $withSpaces->customer_email);
        $this->assertSame('anna.smirnova@example.com', $withSpaces->customer_email_normalized);
        $this->assertSame('anna.smirnova@example.com', $withSpaces->fresh()->customer_email_normalized);

        $explicit = Order::factory()->create(['customer_email_normalized' => 'kept@example.com']);

        $this->assertSame('kept@example.com', $explicit->fresh()->customer_email_normalized);
    }

    /** @param list<string> $expectedFacts */
    #[DataProvider('orderStateProvider')]
    public function test_order_states_apply_consistent_field_sets(
        string $state,
        OrderStatus $expectedStatus,
        bool $expectsPaidAt,
        bool $expectsCancelled,
        bool $expectsShipment,
    ): void {
        $order = Order::factory()->{$state}()->create();

        $this->assertSame($expectedStatus, $order->status);

        $expectsPaidAt
            ? $this->assertNotNull($order->paid_at)
            : $this->assertNull($order->paid_at);

        if ($expectsCancelled) {
            $this->assertNotNull($order->cancelled_at);
            $this->assertNotSame('', $order->cancel_reason);
        } else {
            $this->assertNull($order->cancelled_at);
            $this->assertNull($order->cancel_reason);
        }

        // Shipment-тройка всегда согласована: либо все NULL, либо все заполнены
        // и URL содержит трек-номер.
        if ($expectsShipment) {
            $this->assertNotNull($order->shipment_carrier);
            $this->assertNotNull($order->shipment_tracking_number);
            $this->assertStringContainsString($order->shipment_tracking_number, $order->shipment_tracking_url);
        } else {
            $this->assertNull($order->shipment_carrier);
            $this->assertNull($order->shipment_tracking_number);
            $this->assertNull($order->shipment_tracking_url);
        }

        // Версия снимка пересчитана состоянием.
        $this->assertTrue($order->version_event_created_at->equalTo($order->source_updated_at->addSecond()));

        // Snapshot согласован со state.
        $snapshot = $order->fresh()->snapshot;
        $this->assertSame($expectedStatus->value, $snapshot['status']);
        $this->assertSame($expectsPaidAt ? $this->iso($order->paid_at) : null, $snapshot['paid_at']);
        $this->assertSame($expectsCancelled ? $order->cancel_reason : null, $snapshot['cancel_reason']);
        $expectsShipment
            ? $this->assertSame($order->shipment_tracking_number, $snapshot['shipment']['tracking_number'])
            : $this->assertNull($snapshot['shipment']);
    }

    /**
     * @return array<string, array{string, OrderStatus, bool, bool, bool}>
     */
    public static function orderStateProvider(): array
    {
        return [
            'created' => ['created', OrderStatus::CREATED, false, false, false],
            'paid' => ['paid', OrderStatus::PAID, true, false, false],
            'shipped' => ['shipped', OrderStatus::SHIPPED, true, false, true],
            'delivered' => ['delivered', OrderStatus::DELIVERED, true, false, true],
            'cancelled' => ['cancelled', OrderStatus::CANCELLED, false, true, false],
        ];
    }

    public function test_order_states_keep_timeline_monotonic(): void
    {
        $paid = Order::factory()->paid()->create();

        // Снимок оплаты: updated_at совпадает с моментом оплаты.
        $this->assertTrue($paid->paid_at->greaterThanOrEqualTo($paid->placed_at));
        $this->assertTrue($paid->source_updated_at->equalTo($paid->paid_at));

        foreach (['shipped', 'delivered'] as $state) {
            $order = Order::factory()->{$state}()->create();

            $this->assertTrue($order->paid_at->greaterThanOrEqualTo($order->placed_at), "state {$state}");
            $this->assertTrue($order->source_updated_at->greaterThan($order->paid_at), "state {$state}");
            $this->assertTrue($order->source_updated_at->greaterThanOrEqualTo($order->placed_at), "state {$state}");
        }

        $cancelled = Order::factory()->cancelled()->create();

        $this->assertTrue($cancelled->cancelled_at->greaterThan($cancelled->placed_at));
    }

    public function test_order_cancelled_after_paid_keeps_paid_at(): void
    {
        // S-08: отмена оплаченного заказа — paid_at сохраняется.
        $order = Order::factory()->paid()->cancelled()->create();

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->cancelled_at);
        $this->assertNotNull($order->cancel_reason);
    }

    public function test_order_shipped_accepts_explicit_tracking_number(): void
    {
        $order = Order::factory()->shipped('TRACK-777')->create();

        $this->assertSame('TRACK-777', $order->shipment_tracking_number);
        $this->assertStringContainsString('TRACK-777', $order->shipment_tracking_url);
    }

    public function test_order_delivered_keeps_shipment_from_shipped_state_and_does_not_rollback_timeline(): void
    {
        $order = Order::factory()->shipped('TRACK-9')->delivered()->create();

        // Существующая shipment-тройка сохраняется.
        $this->assertSame('TRACK-9', $order->shipment_tracking_number);
        $this->assertSame(OrderStatus::DELIVERED, $order->status);

        // Хронология не откатывается: delivered позже shipped-версии снимка.
        $this->assertTrue($order->source_updated_at->greaterThan($order->paid_at));
    }

    public function test_order_without_phone_and_without_shipment_states(): void
    {
        $noPhone = Order::factory()->withoutPhone()->create();

        $this->assertNull($noPhone->customer_phone);
        $this->assertNull($noPhone->fresh()->snapshot['customer']['phone']);

        // Статус не трогается: законный фиксчур для проверки skip-веток.
        $noShipment = Order::factory()->shipped()->withoutShipment()->create();

        $this->assertSame(OrderStatus::SHIPPED, $noShipment->status);
        $this->assertNull($noShipment->shipment_carrier);
        $this->assertNull($noShipment->shipment_tracking_number);
        $this->assertNull($noShipment->shipment_tracking_url);
        $this->assertNull($noShipment->fresh()->snapshot['shipment']);
    }

    public function test_order_with_items_creates_sequential_positions_and_syncs_snapshot(): void
    {
        $order = Order::factory()->withItems(3)->create();

        $items = $order->items()->orderBy('position')->get();

        $this->assertCount(3, $items);
        $this->assertSame([1, 2, 3], $items->pluck('position')->all());
        $this->assertTrue($items->every(static fn(OrderItem $item): bool => $item->order_id === $order->id));
        $this->assertTrue($items->every(static fn(OrderItem $item): bool => (bool) preg_match('/^\d+\.\d{2}$/', $item->price)));

        // Snapshot согласован с созданными позициями.
        $snapshotItems = $order->fresh()->snapshot['items'];

        $this->assertCount(3, $snapshotItems);
        foreach ($items as $index => $item) {
            $this->assertSame($item->sku, $snapshotItems[$index]['sku']);
            $this->assertSame($item->name, $snapshotItems[$index]['name']);
            $this->assertSame($item->quantity, $snapshotItems[$index]['quantity']);
            $this->assertSame($item->price, $snapshotItems[$index]['price']);
        }

        // withItems(0) — no-op, позиций нет.
        $empty = Order::factory()->withItems(0)->create();

        $this->assertCount(0, $empty->items);
        $this->assertSame([], $empty->fresh()->snapshot['items']);
    }

    /**
     * @param list<string> $chainStates
     * @param list<string> $expectedStatuses
     */
    #[DataProvider('orderHistoryProvider')]
    public function test_order_with_history_builds_facts_from_snapshot(array $chainStates, array $expectedStatuses): void
    {
        $factory = Order::factory();

        foreach ($chainStates as $state) {
            $factory = $factory->{$state}();
        }

        $order = $factory->withHistory()->create();

        $history = $order->history()->orderBy('at')->orderBy('id')->get();

        $this->assertSame(
            $expectedStatuses,
            $history
                ->map(static fn(OrderStatusHistory $entry): string => $entry->status->value)
                ->all(),
        );

        // Моменты переходов — из полей заказа (FR-HIS-01), не из времени фабрики.
        foreach ($history as $entry) {
            $expectedAt = match ($entry->status) {
                OrderStatus::CREATED => $order->placed_at,
                OrderStatus::PAID => $order->paid_at,
                OrderStatus::CANCELLED => $order->cancelled_at,
                OrderStatus::SHIPPED, OrderStatus::DELIVERED => $order->source_updated_at,
            };

            $this->assertSame($expectedAt->format('Y-m-d H:i:s'), $entry->at->format('Y-m-d H:i:s'));
        }
    }

    /**
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function orderHistoryProvider(): array
    {
        return [
            'created' => [['created'], ['created']],
            'paid' => [['paid'], ['created', 'paid']],
            'shipped' => [['shipped'], ['created', 'paid', 'shipped']],
            'delivered' => [['delivered'], ['created', 'paid', 'delivered']],
            'cancelled unpaid' => [['cancelled'], ['created', 'cancelled']],
            'cancelled paid' => [['paid', 'cancelled'], ['created', 'paid', 'cancelled']],
        ];
    }

    public function test_history_factory_for_order_uses_native_timestamps(): void
    {
        foreach (['created', 'paid', 'shipped', 'cancelled'] as $orderState) {
            $order = Order::factory()->{$orderState}()->create();

            $entry = OrderStatusHistory::factory()->forOrder($order)->create();

            $this->assertSame($order->id, $entry->order_id);
            $this->assertSame($order->status, $entry->status);

            $expectedAt = match ($order->status) {
                OrderStatus::CREATED => $order->placed_at,
                OrderStatus::PAID => $order->paid_at,
                OrderStatus::CANCELLED => $order->cancelled_at,
                OrderStatus::SHIPPED, OrderStatus::DELIVERED => $order->source_updated_at,
            };

            $this->assertSame(
                $expectedAt->format('Y-m-d H:i:s'),
                $entry->at->format('Y-m-d H:i:s'),
                "state {$orderState}",
            );
        }
    }

    public function test_history_factory_creates_multiple_entries_without_unique_collisions(): void
    {
        $order = Order::factory()->create();

        foreach ([OrderStatus::CREATED, OrderStatus::PAID, OrderStatus::SHIPPED] as $index => $status) {
            OrderStatusHistory::factory()->forOrder($order)->create([
                'status' => $status,
                'at' => CarbonImmutable::now()->subDays(3 - $index),
            ]);
        }

        $this->assertSame(3, $order->history()->count());
        $this->assertSame(
            ['created', 'paid', 'shipped'],
            $order->history()->orderBy('at')->pluck('status')->map(static fn($status): string => $status->value)->all(),
        );
    }

    public function test_history_factory_defaults_create_valid_entry(): void
    {
        $entry = OrderStatusHistory::factory()->create();

        $this->assertTrue($entry->exists);
        $this->assertInstanceOf(Order::class, $entry->order);
        $this->assertInstanceOf(OrderStatus::class, $entry->status);
        $this->assertStringStartsWith('evt_', $entry->source_event_id);
    }

    public function test_webhook_event_defaults_are_consistent_applied_event(): void
    {
        $event = WebhookEvent::factory()->create();

        $this->assertTrue($event->exists);
        $this->assertStringStartsWith('evt_', $event->id);
        $this->assertContains($event->type, WebhookEventFactory::KNOWN_TYPES);
        $this->assertNull($event->order_id);
        $this->assertSame(EventOutcome::APPLIED, $event->outcome);
        $this->assertNull($event->reject_reason);
        $this->assertSame(1, $event->deliveries);
        $this->assertNull($event->last_received_at);
        $this->assertTrue($event->processed_at->equalTo($event->received_at));

        // Строковый ключ работает: find по event.id.
        $found = WebhookEvent::query()->find($event->id);
        $this->assertNotNull($found);
        $this->assertSame($event->id, $found->id);

        // Payload — валидный конверт, согласованный с записью.
        $payload = json_decode($event->payload, true);
        $this->assertSame($event->id, $payload['id']);
        $this->assertSame($event->type, $payload['type']);
        $this->assertIsString($payload['data']['order']['id']);

        // Хеш согласован с телом.
        $this->assertSame(hash('sha256', $event->payload), $event->payload_sha256);
    }

    public function test_webhook_event_hash_follows_payload_override_and_is_overridable(): void
    {
        $custom = WebhookEvent::factory()->create(['payload' => '{"custom":true}']);

        $this->assertSame('{"custom":true}', $custom->payload);
        $this->assertSame(hash('sha256', '{"custom":true}'), $custom->payload_sha256);

        $explicitHash = str_repeat('a', 64);
        $overridden = WebhookEvent::factory()->create(['payload_sha256' => $explicitHash]);

        $this->assertSame($explicitHash, $overridden->payload_sha256);
    }

    public function test_webhook_event_known_and_unknown_states(): void
    {
        $known = WebhookEvent::factory()->known('order.paid')->forOrder('ord_state_known')->create();

        $this->assertSame('order.paid', $known->type);
        $this->assertSame('ord_state_known', $known->order_id);
        $this->assertSame(EventOutcome::APPLIED, $known->outcome);

        // Payload согласован со ссылкой на заказ.
        $payload = json_decode($known->payload, true);
        $this->assertSame('ord_state_known', $payload['data']['order']['id']);

        $unknown = WebhookEvent::factory()->unknown()->create();

        $this->assertNotContains($unknown->type, WebhookEventFactory::KNOWN_TYPES);
        $this->assertSame(EventOutcome::IGNORED, $unknown->outcome);
    }

    public function test_webhook_event_outcome_states(): void
    {
        $stale = WebhookEvent::factory()->stale()->create();
        $this->assertSame(EventOutcome::STALE, $stale->outcome);
        $this->assertNull($stale->reject_reason);

        $ignored = WebhookEvent::factory()->ignored()->create();
        $this->assertSame(EventOutcome::IGNORED, $ignored->outcome);

        $rejected = WebhookEvent::factory()->rejected()->create();
        $this->assertSame(EventOutcome::REJECTED, $rejected->outcome);
        $this->assertSame('terminal_status', $rejected->reject_reason);
    }

    public function test_webhook_event_redelivered_state(): void
    {
        $event = WebhookEvent::factory()->redelivered(3)->create();

        $this->assertSame(3, $event->deliveries);
        $this->assertNotNull($event->last_received_at);
        $this->assertTrue($event->last_received_at->greaterThan($event->received_at));
    }

    public function test_notification_defaults_match_order_to_type_and_dedupe_key(): void
    {
        foreach (range(1, 15) as $iteration) {
            $notification = OrderNotification::factory()->create();

            $expectedOrderStatus = match ($notification->type) {
                NotificationType::PAID => OrderStatus::PAID,
                NotificationType::SHIPPED => OrderStatus::SHIPPED,
                NotificationType::CANCELLED => OrderStatus::CANCELLED,
            };

            $order = $notification->order;

            $this->assertSame(
                $expectedOrderStatus,
                $order->status,
                "итерация {$iteration}: тип письма должен соответствовать заказу (матрица §6.5)",
            );

            $expectedDedupeKey = $notification->type === NotificationType::SHIPPED
                ? "{$order->id}:shipped:{$order->shipment_tracking_number}"
                : "{$order->id}:{$notification->type->value}";

            $this->assertSame($expectedDedupeKey, $notification->dedupe_key, "итерация {$iteration}");

            // Свежая pending-запись outbox: отправка ещё не заполняла поля.
            $this->assertSame(NotificationStatus::PENDING, $notification->status);
            $this->assertSame(0, $notification->attempts);
            $this->assertNull($notification->sent_at);
            $this->assertNull($notification->skip_reason);
            $this->assertNull($notification->last_error);
            $this->assertNull($notification->recipient);
            $this->assertNull($notification->subject);
            $this->assertStringStartsWith('evt_', $notification->source_event_id);
        }
    }

    public function test_notification_for_order_builds_dedupe_key_from_order(): void
    {
        $paidOrder = Order::factory()->paid()->create();
        $paid = OrderNotification::factory()->forOrder($paidOrder)->create(['type' => NotificationType::PAID]);

        $this->assertSame($paidOrder->id, $paid->order_id);
        $this->assertSame("{$paidOrder->id}:paid", $paid->dedupe_key);

        // Тип можно переопределить при создании — ключ следует финальному типу.
        $shippedOrder = Order::factory()->shipped()->create();
        $shipped = OrderNotification::factory()->forOrder($shippedOrder)->create(['type' => NotificationType::SHIPPED]);

        $this->assertSame(
            "{$shippedOrder->id}:shipped:{$shippedOrder->shipment_tracking_number}",
            $shipped->dedupe_key,
        );

        // Ключ следует и переопределению order_id в create() — записи с чужим
        // вложенным заказом не остаются с «чужим» ключом.
        $ownOrder = Order::factory()->paid()->create();
        $moved = OrderNotification::factory()->create([
            'order_id' => $ownOrder->id,
            'type' => NotificationType::PAID,
        ]);

        $this->assertSame($ownOrder->id, $moved->order_id);
        $this->assertSame("{$ownOrder->id}:paid", $moved->dedupe_key);
    }

    public function test_notification_sent_state_fills_recipient_and_subject(): void
    {
        $cases = [
            [NotificationType::PAID, 'Заказ №%s оплачен'],
            [NotificationType::SHIPPED, 'Заказ №%s передан в доставку'],
            [NotificationType::CANCELLED, 'Заказ №%s отменён'],
        ];

        foreach ($cases as [$type, $subjectTemplate]) {
            $order = Order::factory()->{$type->value}()->create();

            $notification = OrderNotification::factory()
                ->forOrder($order)
                ->sent()
                ->create(['type' => $type]);

            $this->assertSame(NotificationStatus::SENT, $notification->status);
            $this->assertSame(1, $notification->attempts);
            $this->assertNotNull($notification->sent_at);
            $this->assertNull($notification->skip_reason);

            // Получатель и тема — из текущего состояния заказа (§6.5).
            $this->assertSame($order->customer_email, $notification->recipient);
            $this->assertSame(sprintf($subjectTemplate, $order->number), $notification->subject);
            $this->assertStringContainsString('№', $notification->subject);
        }
    }

    public function test_notification_lifecycle_states(): void
    {
        $sending = OrderNotification::factory()->sending()->create();
        $this->assertSame(NotificationStatus::SENDING, $sending->status);
        $this->assertSame(0, $sending->attempts);
        $this->assertNull($sending->sent_at);

        $skipped = OrderNotification::factory()->skipped('no_shipment')->create();
        $this->assertSame(NotificationStatus::SKIPPED, $skipped->status);
        $this->assertSame('no_shipment', $skipped->skip_reason);
        $this->assertNull($skipped->dedupe_key);
        $this->assertNull($skipped->sent_at);

        $failed = OrderNotification::factory()->failed()->create();
        $this->assertSame(NotificationStatus::FAILED, $failed->status);
        $this->assertSame(5, $failed->attempts);
        $this->assertNotSame('', $failed->last_error);
        $this->assertNull($failed->sent_at);

        $noDedupe = OrderNotification::factory()->withoutDedupeKey()->create();
        $this->assertNull($noDedupe->dedupe_key);
    }

    public function test_order_item_factory_auto_positions_and_allows_overrides(): void
    {
        // Автономная позиция: заказ создаётся вложенной фабрикой, position = 1.
        $first = OrderItem::factory()->create();

        $this->assertSame(1, $first->position);
        $this->assertInstanceOf(Order::class, $first->order);

        // Переопределение полей и связи — одной строкой; позиция инкрементируется.
        $second = OrderItem::factory()
            ->for($first->order)
            ->create(['sku' => 'CUSTOM-SKU', 'quantity' => 7, 'price' => '123.45']);

        $this->assertSame(2, $second->position);
        $this->assertSame('CUSTOM-SKU', $second->sku);
        $this->assertSame(7, $second->quantity);
        $this->assertSame('123.45', $second->price);
        $this->assertSame($first->order_id, $second->order_id);
    }

    private function iso(CarbonImmutable $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:sP');
    }
}
