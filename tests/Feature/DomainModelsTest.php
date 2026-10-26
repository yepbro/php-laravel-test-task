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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Eloquent-модели домена (T-005): ключи, касты, связи и различие
 * системных дат приложения и дат MarketHub на реальной MySQL.
 *
 * Целостность схемы (типы колонок, индексы, FK) проверена в
 * DomainSchemaTest (T-003) и здесь не дублируется.
 */
class DomainModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_uses_markethub_id_as_non_incrementing_string_key(): void
    {
        $order = $this->createOrder('ord_key_test');

        $this->assertFalse($order->incrementing);
        $this->assertSame('string', $order->getKeyType());
        $this->assertSame('ord_key_test', $order->getKey());

        $found = Order::query()->find('ord_key_test');

        $this->assertInstanceOf(Order::class, $found);
        $this->assertSame('ord_key_test', $found->id);
        $this->assertTrue($found->exists);

        // Строковый ключ не переназначается БД: разные заказы независимы.
        $this->createOrder('ord_key_test_second');

        $this->assertSame(2, Order::query()->count());
        $this->assertSame(['ord_key_test', 'ord_key_test_second'], Order::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_order_amounts_are_decimal_strings(): void
    {
        $order = $this->createOrder('ord_money')->fresh();

        $this->assertSame('349.00', $order->delivery_price);
        $this->assertSame('200.00', $order->discount);
        $this->assertSame('4909.50', $order->total);
        $this->assertIsString($order->delivery_price);
        $this->assertIsString($order->total);

        // Числовой ввод тоже возвращается строкой с двумя знаками.
        $paid = $this->createOrder('ord_money_int', ['total' => 100]);
        $this->assertSame('100.00', $paid->fresh()->total);

        $zero = $this->createOrder('ord_money_zero', ['total' => '0.00', 'discount' => '0.00']);
        $this->assertSame('0.00', $zero->fresh()->total);
        $this->assertSame('0.00', $zero->fresh()->discount);
    }

    public function test_order_status_casts_to_enum_both_ways(): void
    {
        $order = $this->createOrder('ord_enum')->fresh();

        $this->assertSame(OrderStatus::CREATED, $order->status);

        $order->status = OrderStatus::SHIPPED;
        $order->save();

        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);

        $order->status = 'delivered';
        $order->save();

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_order_marketplace_dates_are_carbon(): void
    {
        $order = $this->createOrder('ord_dates', [
            'paid_at' => '2026-10-21 10:05:47',
        ])->fresh();

        foreach (['placed_at', 'source_updated_at', 'paid_at', 'version_event_created_at'] as $column) {
            $this->assertInstanceOf(CarbonImmutable::class, $order->{$column}, "{$column} must be a Carbon date.");
        }

        $this->assertSame('2026-10-21 10:02:11', $order->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 10:02:11', $order->source_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 10:02:12', $order->version_event_created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 10:05:47', $order->paid_at->format('Y-m-d H:i:s'));

        // Nullable-даты остаются null, пока их не задали.
        $this->assertNull($order->cancelled_at);

        $order->cancelled_at = CarbonImmutable::parse('2026-10-22 06:40:03');
        $order->save();

        $this->assertSame('2026-10-22 06:40:03', $order->fresh()->cancelled_at->format('Y-m-d H:i:s'));
    }

    public function test_order_snapshot_casts_to_array(): void
    {
        $snapshot = [
            'id' => 'ord_snapshot',
            'number' => 'MH-1048213',
            'items' => [
                ['sku' => 'TSHIRT-BLK-M', 'quantity' => 3, 'price' => '1290.00'],
            ],
        ];

        $order = $this->createOrder('ord_snapshot', ['snapshot' => $snapshot])->fresh();

        // JSON-колонка MySQL нормализует порядок ключей объекта, поэтому
        // сравниваем по ключам, а не весь массив целиком.
        $this->assertIsArray($order->snapshot);
        $this->assertCount(3, $order->snapshot);
        $this->assertSame('ord_snapshot', $order->snapshot['id']);
        $this->assertSame('MH-1048213', $order->snapshot['number']);
        $this->assertSame('TSHIRT-BLK-M', $order->snapshot['items'][0]['sku']);
        $this->assertSame(3, $order->snapshot['items'][0]['quantity']);
        $this->assertSame('1290.00', $order->snapshot['items'][0]['price']);
    }

    public function test_order_system_timestamps_are_independent_from_marketplace_dates(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 26, 12, 0, 0));

        $order = $this->createOrder('ord_sys_dates');

        $this->travelBack();

        $order = $order->fresh();

        // created_at — системная метка нашей записи (момент вставки)…
        $this->assertInstanceOf(CarbonImmutable::class, $order->created_at);
        $this->assertSame('2026-10-26 12:00:00', $order->created_at->format('Y-m-d H:i:s'));

        // …а placed_at/source_updated_at — даты MarketHub из снимка.
        $this->assertSame('2026-10-21 10:02:11', $order->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 10:02:11', $order->source_updated_at->format('Y-m-d H:i:s'));
        $this->assertNotSame(
            $order->created_at->format('Y-m-d H:i:s'),
            $order->placed_at->format('Y-m-d H:i:s'),
        );

        // Изменение системной updated_at не трогает даты маркетплейса.
        $this->travelTo(CarbonImmutable::create(2026, 10, 26, 13, 0, 0));

        $order->touch();

        $this->travelBack();

        $order = $order->fresh();

        $this->assertSame('2026-10-26 13:00:00', $order->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 10:02:11', $order->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 10:02:11', $order->source_updated_at->format('Y-m-d H:i:s'));
    }

    public function test_order_relations_load_from_database(): void
    {
        $order = $this->createOrder('ord_relations');

        $order->items()->createMany([
            ['position' => 1, 'sku' => 'TSHIRT-BLK-M', 'name' => 'Футболка базовая', 'quantity' => 3, 'price' => '1290.00'],
            ['position' => 2, 'sku' => 'CAP-NVY', 'name' => 'Бейсболка', 'quantity' => 1, 'price' => '890.50'],
        ]);
        $order->history()->createMany([
            ['status' => OrderStatus::CREATED, 'at' => '2026-10-21 10:02:11', 'source_event_id' => 'evt_created'],
            ['status' => OrderStatus::PAID, 'at' => '2026-10-21 10:05:47', 'source_event_id' => 'evt_paid'],
        ]);
        $order->notifications()->create([
            'type' => NotificationType::PAID,
            'status' => NotificationStatus::SENT,
            'recipient' => 'anna.smirnova@example.com',
            'subject' => 'Заказ №MH-1048213 оплачен',
            'source_event_id' => 'evt_paid',
            'sent_at' => '2026-10-21 10:05:48',
        ]);

        $loaded = Order::query()->with(['items', 'history', 'notifications'])->find('ord_relations');

        $this->assertNotNull($loaded);
        $this->assertCount(2, $loaded->items);
        $this->assertInstanceOf(OrderItem::class, $loaded->items->first());

        $this->assertCount(2, $loaded->history);
        $this->assertInstanceOf(OrderStatusHistory::class, $loaded->history->first());

        // Порядок строк без ORDER BY БД не гарантирует — проверяем состав,
        // а не индексы; сортировка истории — ответственность выборки (FR-HIS-04).
        $this->assertTrue($loaded->history->contains(
            static fn(OrderStatusHistory $entry): bool => $entry->status === OrderStatus::CREATED,
        ));
        $this->assertTrue($loaded->history->contains(
            static fn(OrderStatusHistory $entry): bool => $entry->status === OrderStatus::PAID,
        ));

        $this->assertCount(1, $loaded->notifications);
        $this->assertInstanceOf(OrderNotification::class, $loaded->notifications->first());
    }

    public function test_order_item_casts_and_belongs_to_order(): void
    {
        $order = $this->createOrder('ord_item');

        $item = $order->items()->create([
            'position' => 1,
            'sku' => 'TSHIRT-BLK-M',
            'name' => 'Футболка базовая',
            'quantity' => 3,
            'price' => '1290.00',
        ]);

        $item = $item->fresh();

        $this->assertSame(1, $item->position);
        $this->assertSame(3, $item->quantity);
        $this->assertIsInt($item->quantity);
        $this->assertSame('1290.00', $item->price);
        $this->assertIsString($item->price);

        $this->assertInstanceOf(Order::class, $item->order);
        $this->assertSame('ord_item', $item->order->id);
    }

    public function test_order_item_has_no_system_timestamps(): void
    {
        $order = $this->createOrder('ord_item_ts');

        $item = $order->items()->create([
            'position' => 1,
            'sku' => 'SKU-1',
            'name' => 'Товар',
            'quantity' => 1,
            'price' => '10.00',
        ]);

        $this->assertFalse($item->usesTimestamps());
        $this->assertArrayNotHasKey('created_at', $item->getAttributes());
        $this->assertArrayNotHasKey('updated_at', $item->getAttributes());
        $this->assertNull($item->created_at);
        $this->assertNull($item->updated_at);
    }

    public function test_status_history_casts_and_belongs_to_order(): void
    {
        $order = $this->createOrder('ord_history');

        $entry = $order->history()->create([
            'status' => OrderStatus::PAID,
            'at' => '2026-10-21 10:05:47',
            'source_event_id' => 'evt_paid',
        ]);

        $entry = $entry->fresh();

        $this->assertSame(OrderStatus::PAID, $entry->status);
        $this->assertInstanceOf(CarbonImmutable::class, $entry->at);
        $this->assertSame('2026-10-21 10:05:47', $entry->at->format('Y-m-d H:i:s'));

        $this->assertInstanceOf(Order::class, $entry->order);
        $this->assertSame('ord_history', $entry->order->id);

        // source_event_id nullable
        $bare = $order->history()->create([
            'status' => OrderStatus::SHIPPED,
            'at' => '2026-10-22 06:40:03',
        ]);

        $this->assertNull($bare->fresh()->source_event_id);
    }

    public function test_status_history_has_no_system_timestamps(): void
    {
        $order = $this->createOrder('ord_history_ts');

        $entry = $order->history()->create([
            'status' => OrderStatus::CREATED,
            'at' => '2026-10-21 10:02:11',
        ]);

        $this->assertFalse($entry->usesTimestamps());
        $this->assertArrayNotHasKey('created_at', $entry->getAttributes());
        $this->assertArrayNotHasKey('updated_at', $entry->getAttributes());
    }

    public function test_webhook_event_uses_event_id_as_non_incrementing_string_key(): void
    {
        $event = WebhookEvent::create($this->eventAttributes('evt_key_test'));

        $this->assertFalse($event->incrementing);
        $this->assertSame('string', $event->getKeyType());

        $found = WebhookEvent::query()->find('evt_key_test');

        $this->assertInstanceOf(WebhookEvent::class, $found);
        $this->assertSame('evt_key_test', $found->id);
    }

    public function test_webhook_event_payload_stays_raw_bytes(): void
    {
        $payload = '{"id":"evt_raw","note":"Привет","items":[1,2]}' . "\n";

        $event = WebhookEvent::create($this->eventAttributes('evt_raw', ['payload' => $payload]));

        $stored = $event->fresh()->payload;

        $this->assertIsString($stored);
        $this->assertSame($payload, $stored);
    }

    public function test_webhook_event_casts(): void
    {
        $event = WebhookEvent::create($this->eventAttributes('evt_casts', [
            'outcome' => EventOutcome::STALE,
            'reject_reason' => 'terminal_status',
            'last_received_at' => '2026-10-21 10:06:00',
        ]));

        $event = $event->fresh();

        $this->assertSame(EventOutcome::STALE, $event->outcome);
        $this->assertIsInt($event->signature_timestamp);
        $this->assertSame(1792577149, $event->signature_timestamp);

        // deliveries имеет DEFAULT 1 в БД и приводится к int.
        $this->assertIsInt($event->deliveries);
        $this->assertSame(1, $event->deliveries);

        $this->assertInstanceOf(CarbonImmutable::class, $event->received_at);
        $this->assertSame('2026-10-21 10:02:12', $event->received_at->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(CarbonImmutable::class, $event->last_received_at);
        $this->assertSame('2026-10-21 10:06:00', $event->last_received_at->format('Y-m-d H:i:s'));
        $this->assertNull($event->processed_at);

        $event->processed_at = CarbonImmutable::parse('2026-10-21 10:06:01');
        $event->save();

        $this->assertSame('2026-10-21 10:06:01', $event->fresh()->processed_at->format('Y-m-d H:i:s'));
    }

    public function test_webhook_event_order_relation_is_nullable_soft_reference(): void
    {
        // Событие неизвестного типа или до создания заказа — order_id NULL.
        $unknown = WebhookEvent::create($this->eventAttributes('evt_unknown', [
            'type' => 'invoice.updated',
            'outcome' => EventOutcome::IGNORED,
        ]));

        $this->assertNull($unknown->order);

        // Событие со ссылкой на существующий заказ.
        $this->createOrder('ord_evt');

        $linked = WebhookEvent::create($this->eventAttributes('evt_linked', ['order_id' => 'ord_evt']));

        $this->assertInstanceOf(Order::class, $linked->order);
        $this->assertSame('ord_evt', $linked->order->id);
    }

    public function test_webhook_event_survives_order_deletion_with_dangling_reference(): void
    {
        // Мягкая ссылка без FK (решение T-003): журнал — независимый аудит.
        $order = $this->createOrder('ord_soft_ref');
        $event = WebhookEvent::create($this->eventAttributes('evt_soft_ref', [
            'order_id' => 'ord_soft_ref',
        ]));

        $order->delete();

        // Строка события и сама ссылка сохраняются (каскадов нет), но связь
        // order() по висячей ссылке возвращает null.
        $survived = $event->fresh();

        $this->assertNotNull($survived);
        $this->assertSame('ord_soft_ref', $survived->order_id);
        $this->assertNull($survived->order);
    }

    public function test_webhook_event_has_no_system_timestamps(): void
    {
        $event = WebhookEvent::create($this->eventAttributes('evt_ts'));

        $this->assertFalse($event->usesTimestamps());
        $this->assertArrayNotHasKey('created_at', $event->getAttributes());
        $this->assertArrayNotHasKey('updated_at', $event->getAttributes());
    }

    public function test_notification_casts_and_belongs_to_order(): void
    {
        $order = $this->createOrder('ord_notify');

        $notification = $order->notifications()->create([
            'type' => NotificationType::SHIPPED,
            'dedupe_key' => 'ord_notify:shipped:TRACK-1',
            'status' => NotificationStatus::PENDING,
            'attempts' => 0,
            'source_event_id' => 'evt_shipped',
        ]);

        $notification = $notification->fresh();

        $this->assertSame(NotificationType::SHIPPED, $notification->type);
        $this->assertSame(NotificationStatus::PENDING, $notification->status);
        $this->assertIsInt($notification->attempts);
        $this->assertSame(0, $notification->attempts);
        $this->assertNull($notification->sent_at);
        $this->assertNull($notification->skip_reason);

        $this->assertInstanceOf(Order::class, $notification->order);
        $this->assertSame('ord_notify', $notification->order->id);

        $notification->sent_at = CarbonImmutable::parse('2026-10-22 06:41:00');
        $notification->save();

        $sent = $notification->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $sent->sent_at);
        $this->assertSame('2026-10-22 06:41:00', $sent->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_notification_has_system_timestamps(): void
    {
        $order = $this->createOrder('ord_notify_ts');

        $notification = $order->notifications()->create([
            'type' => NotificationType::CANCELLED,
            'status' => NotificationStatus::SKIPPED,
            'skip_reason' => 'no_shipment',
            'source_event_id' => 'evt_cancelled',
        ]);

        $this->assertTrue($notification->usesTimestamps());

        $stored = $notification->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $stored->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $stored->updated_at);
        $this->assertNotNull($stored->created_at);
        $this->assertNotNull($stored->updated_at);
    }

    /**
     * Базовые атрибуты заказа (пример §6.5 ТЗ).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function orderAttributes(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'number' => 'MH-1048213',
            'status' => OrderStatus::CREATED,
            'currency' => 'RUB',
            'customer_email' => 'Anna.Smirnova@Example.com',
            'customer_email_normalized' => 'anna.smirnova@example.com',
            'customer_name' => 'Анна Смирнова',
            'customer_phone' => null,
            'delivery_price' => '349.00',
            'discount' => '200.00',
            'total' => '4909.50',
            'placed_at' => '2026-10-21 10:02:11',
            'source_updated_at' => '2026-10-21 10:02:11',
            'version_event_created_at' => '2026-10-21 10:02:12',
            'version_event_id' => 'evt_created',
            'snapshot' => ['id' => $id, 'number' => 'MH-1048213'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createOrder(string $id, array $overrides = []): Order
    {
        return Order::create($this->orderAttributes($id, $overrides));
    }

    /**
     * Базовые атрибуты события вебхука.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function eventAttributes(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'type' => 'order.created',
            'order_id' => null,
            'signature_timestamp' => 1792577149,
            'payload' => '{"id": "' . $id . '"}',
            'payload_sha256' => str_repeat('a', 64),
            'outcome' => EventOutcome::APPLIED,
            'received_at' => '2026-10-21 10:02:12',
        ], $overrides);
    }
}
