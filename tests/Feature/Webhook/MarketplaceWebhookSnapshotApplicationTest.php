<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Enums\EventOutcome;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MutatedWebhookBody;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-017. HTTP-сценарии применения снимков (§6.2, шаги 12–14 §7):
 * версионность (FR-ORD-02), полный перезапись (FR-ORD-04/06),
 * восстановление истории (FR-HIS-02), терминальные статусы Q35
 * (FR-ORD-09) и письма-триггеры (§6.5) — на уровне настоящего
 * HTTP-маршрута с реальными примерами и мутантами.
 *
 * Сценарии §8: S-01 (штатный жизненный цикл), S-05 (stale после paid),
 * S-06 (paid раньше created), S-07 (shipped первым; FR-ORD-06 — любой
 * первый снимок), S-19 (моменты, а не настенное время), S-27 (расхождение
 * total — принято), S-29/S-30/S-31/S-39 (терминальные статусы), S-37
 * (ручная коррекция shipped → paid без письма-исправления).
 *
 * Красная фаза: применение снимков — заглушки T-027…T-032
 * (разбор T-021, компаратор T-023 и журнал T-026 готовы; вставка
 * журнала откатывается вместе с транзакцией применения);
 * тесты сейчас падают на 500 от заглушек (первый же assert кода
 * ответа) и зеленеют по мере реализации без правки тестов. Состояние
 * БД готовится фабриками (Order с явной версией снимка — как если бы
 * предыдущее событие уже применилось); входные тела — сырые примеры
 * (байт в байт) либо мутанты с управляемым updated_at (T-015).
 *
 * Не дублируется: кортежный tie-break равных версий (S-20) — unit-набор
 * SnapshotVersionComparatorTest (T-016); тексты и доставка писем — T-019
 * (здесь только факт наличия/отсутствия outbox-записей).
 */
class MarketplaceWebhookSnapshotApplicationTest extends TestCase
{
    use RefreshDatabase;
    use PostsSignedWebhooks;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    private const string ORDER_ID = 'ord_7Q2KX9M4';

    /** Версия снимка order.paid из примеров (10:05:47Z / событие 10:05:48Z). */
    private const string PAID_VERSION_AT = '2026-10-21 10:05:47';

    private const string PAID_EVENT_ID = 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC';

    /** Версия снимка order.shipped из примеров (2026-10-22T06:40:03+00:00). */
    private const string SHIPPED_VERSION_AT = '2026-10-22 06:40:03';

    private const string SHIPPED_EVENT_ID = 'evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0';

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
     * S-01: штатный порядок created → updated → paid → shipped на сырых
     * примерах — все применены (200 processed × 4); итог: статус shipped,
     * позиции 3 футболки + бейсболка, total 4909.50, трек-номер, история
     * [created@10:02:11Z, paid@10:05:47Z, shipped@06:40:03Z 22.10],
     * письма paid ×1 и shipped ×1, журнал 4× applied.
     */
    public function test_full_lifecycle_applies_snapshots_in_delivery_order(): void
    {
        foreach (['order-created', 'order-updated', 'order-paid', 'order-shipped'] as $example) {
            $response = $this->postSignedWebhook(SignedWebhook::forExample($example));

            $this->assertSame(
                ['status' => 'processed'],
                $response->json(),
                sprintf('Пример %s обязан применяться с 200 processed.', $example),
            );
        }

        $this->assertDatabaseHas('orders', [
            'id' => self::ORDER_ID,
            'status' => OrderStatus::SHIPPED->value,
            'total' => '4909.50',
            'shipment_tracking_number' => '1098765432',
            'paid_at' => self::PAID_VERSION_AT,
            'placed_at' => '2026-10-21 10:02:11',
            'source_updated_at' => self::SHIPPED_VERSION_AT,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => self::ORDER_ID,
            'position' => 1,
            'sku' => 'TSHIRT-BLK-M',
            'quantity' => 3,
            'price' => '1290.00',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => self::ORDER_ID,
            'position' => 2,
            'sku' => 'CAP-NVY',
            'quantity' => 1,
            'price' => '890.50',
        ]);
        $this->assertSame(
            [
                ['created', '2026-10-21 10:02:11'],
                ['paid', '2026-10-21 10:05:47'],
                ['shipped', '2026-10-22 06:40:03'],
            ],
            $this->historyRows(),
            'История — факты из снимков по данным MarketHub (FR-HIS-02).',
        );
        $this->assertDatabaseHas('order_notifications', ['order_id' => self::ORDER_ID, 'type' => 'paid']);
        $this->assertDatabaseHas('order_notifications', ['order_id' => self::ORDER_ID, 'type' => 'shipped']);
        $this->assertDatabaseCount('order_notifications', 2);

        // Все четыре события применены и журналированы.
        $this->assertSame(4, WebhookEvent::query()->count());
        $this->assertSame(
            [EventOutcome::APPLIED->value, EventOutcome::APPLIED->value, EventOutcome::APPLIED->value, EventOutcome::APPLIED->value],
            WebhookEvent::query()->orderBy('received_at')->pluck('outcome')->map(static fn($outcome): string => $outcome->value)->all(),
        );
    }

    /**
     * S-05: order.updated со старым снимком (status=created, paid_at=null)
     * приходит после применённого order.paid → 200 stale: статус остаётся
     * paid, paid_at не обнуляется, состав не перезаписывается, письмо не
     * создаётся; событие фиксируется в журнале с outcome=stale (FR-ORD-08).
     */
    public function test_older_update_after_paid_is_stale_and_keeps_paid_state(): void
    {
        Order::factory()->create([
            'id' => self::ORDER_ID,
            'status' => OrderStatus::PAID,
            'total' => '5000.00',
            'paid_at' => self::PAID_VERSION_AT,
            'source_updated_at' => self::PAID_VERSION_AT,
            'version_event_created_at' => '2026-10-21 10:05:48',
            'version_event_id' => self::PAID_EVENT_ID,
        ]);

        $response = $this->postSignedWebhook(SignedWebhook::forExample('order-updated'));

        $response->assertOk();
        $this->assertSame(['status' => 'stale'], $response->json());

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame(OrderStatus::PAID, $order->status, 'Старый снимок не откатывает статус.');
        $this->assertSame('5000.00', $order->total, 'Старый снимок не перезаписывает суммы.');
        $this->assertTrue(
            $order->paid_at->equalTo(CarbonImmutable::parse(self::PAID_VERSION_AT . ' UTC')),
            'paid_at не обнуляется устаревшим снимком.',
        );
        // Позиции устаревшего снимка не создаются.
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            'outcome' => EventOutcome::STALE->value,
            'order_id' => self::ORDER_ID,
        ]);
    }

    /**
     * S-06 (FR-ORD-06): order.paid приходит РАНЬШЕ order.created — заказ
     * создаётся из paid-снимка; затем created (старее) → 200 stale;
     * история содержит оба факта (created-факт восстанавливается из
     * устаревшего снимка, FR-HIS-02); письмо paid ×1.
     */
    public function test_paid_before_created_creates_order_then_treats_created_as_stale(): void
    {
        $paid = $this->postSignedWebhook(SignedWebhook::forExample('order-paid'));

        $this->assertSame(['status' => 'processed'], $paid->json());
        $this->assertDatabaseHas('orders', [
            'id' => self::ORDER_ID,
            'status' => OrderStatus::PAID->value,
            'paid_at' => self::PAID_VERSION_AT,
        ]);

        $created = $this->postSignedWebhook(SignedWebhook::forExample('order-created'));

        $this->assertSame(['status' => 'stale'], $created->json(), 'created-снимок старее применённого paid.');

        $this->assertDatabaseHas('orders', ['id' => self::ORDER_ID, 'status' => OrderStatus::PAID->value]);
        $this->assertSame(
            [
                ['created', '2026-10-21 10:02:11'],
                ['paid', '2026-10-21 10:05:47'],
            ],
            $this->historyRows(),
        );
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertDatabaseHas('order_notifications', ['order_id' => self::ORDER_ID, 'type' => 'paid']);
        $this->assertDatabaseHas('webhook_events', ['id' => self::PAID_EVENT_ID, 'outcome' => EventOutcome::APPLIED->value]);
        $this->assertDatabaseHas('webhook_events', ['id' => 'evt_01JAXKZB7M2D9F4H6J8K0N3P5R', 'outcome' => EventOutcome::STALE->value]);
    }

    /**
     * S-07 (FR-ORD-06): order.shipped первым (оплата потеряна/опоздала) —
     * заказ создаётся из shipped-снимка со статусом shipped; история
     * восстанавливает и пропущенные факты: created, paid (по paid_at
     * снимка), shipped; письмо shipped ×1, paid — нет (A-22).
     */
    public function test_shipped_first_restores_missed_paid_fact(): void
    {
        $response = $this->postSignedWebhook(SignedWebhook::forExample('order-shipped'));

        $this->assertSame(['status' => 'processed'], $response->json());

        $this->assertDatabaseHas('orders', [
            'id' => self::ORDER_ID,
            'status' => OrderStatus::SHIPPED->value,
            'paid_at' => self::PAID_VERSION_AT,
            'shipment_tracking_number' => '1098765432',
        ]);
        $this->assertSame(
            [
                ['created', '2026-10-21 10:02:11'],
                ['paid', '2026-10-21 10:05:47'],
                ['shipped', '2026-10-22 06:40:03'],
            ],
            $this->historyRows(),
            'Пропущенный paid восстанавливается по paid_at снимка (FR-HIS-02).',
        );
        $this->assertDatabaseCount('order_notifications', 1);
        $this->assertDatabaseHas('order_notifications', ['order_id' => self::ORDER_ID, 'type' => 'shipped']);
    }

    /**
     * S-19: «настенное» время свежее (08:00+03:00 против 06:40 UTC), но
     * МОМЕНТ старее (05:00Z < 06:40Z) — сравнение идёт по моментам UTC
     * (FR-ORD-03): 200 stale, версия и состояние заказа не изменились.
     */
    public function test_fresher_wall_time_but_older_moment_is_stale(): void
    {
        Order::factory()->create([
            'id' => self::ORDER_ID,
            'status' => OrderStatus::SHIPPED,
            'total' => '4909.50',
            'source_updated_at' => self::SHIPPED_VERSION_AT,
            'version_event_created_at' => '2026-10-22 06:40:04',
            'version_event_id' => self::SHIPPED_EVENT_ID,
        ]);

        $body = MutatedWebhookBody::fromExample('order-shipped')
            ->with('id', 'evt_01JBWALLTIME0000000000000000')
            ->with('created_at', '2026-10-22T08:00:01+03:00')
            ->withUpdatedAt('2026-10-22T08:00:00+03:00')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $response->assertOk();
        $this->assertSame(['status' => 'stale'], $response->json(), 'Порядок определяют моменты UTC, а не строки времени.');

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame(OrderStatus::SHIPPED, $order->status);
        $this->assertTrue(
            $order->source_updated_at->equalTo(CarbonImmutable::parse(self::SHIPPED_VERSION_AT . ' UTC')),
            'Версия заказа не двигается устаревшим моментом.',
        );
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JBWALLTIME0000000000000000',
            'outcome' => EventOutcome::STALE->value,
        ]);
    }

    /**
     * S-27 (FR-WH-09): расхождение total с арифметикой позиций — не
     * основание для отказа: 200 processed, total сохранён как присланный
     * MarketHub (не пересчитан из компонентов), событие applied.
     */
    public function test_total_arithmetic_mismatch_is_still_processed(): void
    {
        $body = MutatedWebhookBody::fromExample('order-paid')
            ->withPath(['data', 'order', 'total'], '9999.00')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(['status' => 'processed'], $response->json(), 'Расхождение сумм — warning, не отказ.');

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame('9999.00', $order->total, 'total берётся из снимка MarketHub дословно.');
        $this->assertDatabaseHas('webhook_events', ['id' => self::PAID_EVENT_ID, 'outcome' => EventOutcome::APPLIED->value]);
    }

    /**
     * S-29: применён delivered, затем СВЕЖИЙ снимок со статусом paid —
     * выход из терминального статуса запрещён (Q35/FR-ORD-09): 200
     * rejected, событие сохранено с reject_reason=terminal_status,
     * состояние заказа не изменилось, письма нет.
     */
    public function test_fresh_snapshot_after_delivered_is_rejected(): void
    {
        $this->createDeliveredOrder();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($this->freshSnapshot('order-paid', 'paid')));

        $this->assertSame(['status' => 'rejected'], $response->json());
        $this->assertOrderUntouchedInTerminalCase();
        $this->assertDatabaseHas('webhook_events', [
            'outcome' => EventOutcome::REJECTED->value,
            'reject_reason' => 'terminal_status',
            'order_id' => self::ORDER_ID,
        ]);
    }

    /**
     * S-30: применён cancelled, затем свежий shipped — то же правило:
     * 200 rejected, состояние/история/письма без изменений.
     */
    public function test_fresh_snapshot_after_cancelled_is_rejected(): void
    {
        Order::factory()->create([
            'id' => self::ORDER_ID,
            'status' => OrderStatus::CANCELLED,
            'total' => '1298.00',
            'paid_at' => '2026-10-21 15:11:02',
            'cancelled_at' => '2026-10-21 17:47:15',
            'source_updated_at' => '2026-10-21 17:47:15',
            'version_event_created_at' => '2026-10-21 17:47:16',
            'version_event_id' => 'evt_01JAXZ6E8G0J2L4N6Q8S0U2W4Y',
        ]);

        $response = $this->postSignedWebhook(SignedWebhook::forBody($this->freshSnapshot('order-shipped', 'shipped')));

        $this->assertSame(['status' => 'rejected'], $response->json());
        $this->assertSame(OrderStatus::CANCELLED, Order::query()->findOrFail(self::ORDER_ID)->status);
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseHas('webhook_events', [
            'outcome' => EventOutcome::REJECTED->value,
            'reject_reason' => 'terminal_status',
        ]);
    }

    /**
     * S-39 (допущение Q35): переход между самими терминальными статусами
     * (delivered → cancelled) свежим снимком — тоже rejected.
     */
    public function test_terminal_to_terminal_transition_is_rejected(): void
    {
        $this->createDeliveredOrder();

        $body = MutatedWebhookBody::fromExample('order-cancelled')
            ->withPath(['data', 'order', 'id'], self::ORDER_ID)
            ->with('id', 'evt_01JBTERM2TERM00000000000000')
            ->with('created_at', '2026-10-22T09:00:01+00:00')
            ->withUpdatedAt('2026-10-22T09:00:00+00:00')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(['status' => 'rejected'], $response->json(), 'delivered → cancelled запрещён (консервативное допущение Q35).');
        $this->assertOrderUntouchedInTerminalCase();
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JBTERM2TERM00000000000000',
            'outcome' => EventOutcome::REJECTED->value,
            'reject_reason' => 'terminal_status',
        ]);
    }

    /**
     * S-31: свежий снимок с ТЕМ ЖЕ терминальным статусом (delivered) и
     * изменёнными данными — применяется (200 processed): данные заказа
     * обновлены, нового уведомления не создано (смены статуса нет).
     */
    public function test_same_terminal_status_with_new_data_is_applied(): void
    {
        $this->createDeliveredOrder();

        $body = MutatedWebhookBody::fromExample('order-shipped')
            ->withStatus('delivered')
            ->with('id', 'evt_01JBSAMEDELIVERED00000000000')
            ->with('created_at', '2026-10-22T09:00:01+00:00')
            ->withUpdatedAt('2026-10-22T09:00:00+00:00')
            ->withPath(['data', 'order', 'delivery_price'], '599.00')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(['status' => 'processed'], $response->json(), 'Тот же терминальный статус обновляет данные.');

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame(OrderStatus::DELIVERED, $order->status);
        $this->assertSame('599.00', $order->delivery_price);
        $this->assertTrue(
            $order->source_updated_at->equalTo(CarbonImmutable::parse('2026-10-22 09:00:00 UTC')),
            'Версия снимка продвигается.',
        );
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JBSAMEDELIVERED00000000000',
            'outcome' => EventOutcome::APPLIED->value,
        ]);
    }

    /**
     * S-37 (Q28): свежая ручная коррекция shipped → paid через
     * order.updated — применяется: статус paid, shipment сброшен в null
     * (полный перезапись, FR-ORD-04), в истории фиксируется откат
     * (FR-HIS-03), но письмо-исправление «оплачен» НЕ создаётся.
     */
    public function test_manual_rollback_shipped_to_paid_applies_without_correction_email(): void
    {
        Order::factory()
            ->shipped()
            ->withHistory()
            ->create([
                'id' => self::ORDER_ID,
                'status' => OrderStatus::SHIPPED,
                'total' => '4909.50',
                'placed_at' => '2026-10-21 10:02:11',
                'paid_at' => self::PAID_VERSION_AT,
                'source_updated_at' => self::SHIPPED_VERSION_AT,
                'version_event_created_at' => '2026-10-22 06:40:04',
                'version_event_id' => self::SHIPPED_EVENT_ID,
                'shipment_tracking_number' => '1098765432',
            ]);

        $body = MutatedWebhookBody::fromExample('order-updated')
            ->withStatus('paid')
            ->withPath(['data', 'order', 'paid_at'], '2026-10-21T13:05:47+03:00')
            ->with('id', 'evt_01JBROLLBACKPAID0000000000000')
            ->with('created_at', '2026-10-22T09:00:01+00:00')
            ->withUpdatedAt('2026-10-22T09:00:00+00:00')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(['status' => 'processed'], $response->json(), 'Свежая коррекция применяется (откат не терминальный).');

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNull($order->shipment_carrier, 'Откат сбрасывает shipment (полный перезапись снимка).');
        $this->assertNull($order->shipment_tracking_number);
        // Письмо-исправление paid не создаётся (Q28); факт отката
        // фиксируется моментом updated_at коррекции (FR-HIS-03).
        $this->assertDatabaseCount('order_notifications', 0);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => self::ORDER_ID,
            'status' => OrderStatus::PAID->value,
            'at' => '2026-10-22 09:00:00',
        ]);
    }

    /**
     * Заказ в delivered с версией order.shipped из примеров — база
     * сценариев S-29/S-31/S-39.
     */
    private function createDeliveredOrder(): void
    {
        Order::factory()->create([
            'id' => self::ORDER_ID,
            'status' => OrderStatus::DELIVERED,
            'total' => '4909.50',
            'placed_at' => '2026-10-21 10:02:11',
            'paid_at' => self::PAID_VERSION_AT,
            'source_updated_at' => self::SHIPPED_VERSION_AT,
            'version_event_created_at' => '2026-10-22 06:40:04',
            'version_event_id' => self::SHIPPED_EVENT_ID,
            'shipment_tracking_number' => '1098765432',
        ]);
    }

    /**
     * Свежий снимок на основе примера: новый event.id/created_at и
     * updated_at 09:00 UTC (строго новее версии 2026-10-22 06:40:03Z),
     * статус — аргумент.
     *
     * @return string байты мутанта
     */
    private function freshSnapshot(string $example, string $status): string
    {
        return MutatedWebhookBody::fromExample($example)
            ->withStatus($status)
            ->with('id', 'evt_01JBFRESH' . strtoupper($status) . '000000000000')
            ->with('created_at', '2026-10-22T09:00:01+00:00')
            ->withUpdatedAt('2026-10-22T09:00:00+00:00')
            ->body();
    }

    /**
     * Инварианты сценариев терминального отклонения: заказ неизменен,
     * история/письма не создаются.
     */
    private function assertOrderUntouchedInTerminalCase(): void
    {
        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame(OrderStatus::DELIVERED, $order->status);
        $this->assertSame('4909.50', $order->total);
        // История и письма не меняются при rejected.
        $this->assertDatabaseCount('order_status_history', 0);
        $this->assertDatabaseCount('order_notifications', 0);
    }

    /**
     * История заказов как плоский список [status, 'Y-m-d H:i:s']
     * в хронологическом порядке (FR-HIS-04).
     *
     * @return list<list<string>>
     */
    private function historyRows(): array
    {
        $rows = DB::table('order_status_history')
            ->where('order_id', self::ORDER_ID)
            ->orderBy('at')
            ->get(['status', 'at']);

        return $rows
            ->map(static fn($row): array => [$row->status, (string) $row->at])
            ->all();
    }
}
