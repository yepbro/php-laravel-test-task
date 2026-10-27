<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Enums\EventOutcome;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-017. HTTP-контракт повторной доставки (FR-ORD-01): тот же event.id
 * с новыми timestamp/подписью (T4 протокола) → 200 duplicate, deliveries
 * растёт, исходный outcome/payload/хеш не заменяются, заказ не меняется.
 *
 * Сценарии §8: S-02 (повтор с изменённым payload — warning-кейс
 * duplicate_payload_mismatch, но исход тот же duplicate), S-03/S-04
 * (последовательная «конкурирующая» доставка: второй запрос после
 * завершения первого). Честная параллельная доставка (N одновременных
 * HTTP-запросов, S-04 в полном виде) в PHPUnit-процессе ненадёжна —
 * по §11 ТЗ она остаётся интеграционному P1-тесту задачи T-019;
 * здесь фиксируется последовательная семантика того же сценария.
 *
 * Красная фаза: идемпотентная вставка — заглушка T-026 (после T-021/
 * T-024 разбора и подписи, до неё блокировка T-027): тесты сейчас
 * получают 500 и падают на утверждении «200 duplicate/processed».
 */
class MarketplaceWebhookDuplicateDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use PostsSignedWebhooks;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    /** Событие order-paid из примеров (id тела примера). */
    private const string PAID_EVENT_ID = 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC';

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
     * S-02: уже журналированное событие (подготовлено фабрикой с другим
     * payload — повтор пришёл с ИЗМЕНЁННЫМ телом и новым timestamp/
     * подписью) → 200 duplicate; deliveries=2, last_received_at обновлён;
     * исходные outcome/payload/payload_sha256 не перезаписаны (FR-ORD-01);
     * снимок повторно не применяется — заказа по-прежнему нет.
     */
    public function test_redelivery_with_new_timestamp_and_altered_payload_is_duplicate(): void
    {
        $originalPayload = '{"id":"' . self::PAID_EVENT_ID . '","type":"order.paid","data":{"order":{"total":"1.00"}}}';

        WebhookEvent::factory()->create([
            'id' => self::PAID_EVENT_ID,
            'type' => 'order.paid',
            'order_id' => null,
            'payload' => $originalPayload,
            'outcome' => EventOutcome::APPLIED,
            'deliveries' => 1,
            'received_at' => CarbonImmutable::parse('2026-10-21 10:05:49'),
            'last_received_at' => null,
        ]);

        $timestamp = CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp();

        $response = $this->postSignedWebhook(
            SignedWebhook::forExample('order-paid', $timestamp),
        );

        $response->assertOk();
        $this->assertSame(['status' => 'duplicate'], $response->json(), 'Повторная доставка отвечает duplicate (FR-WH-10).');
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'id' => self::PAID_EVENT_ID,
            'outcome' => EventOutcome::APPLIED->value,
            'payload' => $originalPayload,
            'payload_sha256' => hash('sha256', $originalPayload),
            'deliveries' => 2,
            'last_received_at' => CarbonImmutable::parse(self::FROZEN_NOW)->format('Y-m-d H:i:s'),
        ]);
        // Дубль не применяет снимок повторно.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_notifications', 0);
    }

    /**
     * S-03/S-04 (последовательно): первое применение события → 200
     * processed; повтор того же event.id после завершения обработки →
     * 200 duplicate; в журнале одна строка с deliveries=2, заказ не
     * изменился от повтора (состав и суммы остались от первого снятия).
     */
    public function test_sequential_redelivery_after_processing_is_duplicate(): void
    {
        $first = $this->postSignedWebhook(SignedWebhook::forExample('order-created'));

        $first->assertOk();
        $this->assertSame(['status' => 'processed'], $first->json());

        $timestamp = CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp() + 7;
        $second = $this->postSignedWebhook(SignedWebhook::forExample('order-created', $timestamp));

        $second->assertOk();
        $this->assertSame(['status' => 'duplicate'], $second->json(), 'Второй запрос того же event.id — duplicate.');

        // Один event.id — одна строка журнала (S-04).
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JAXKZB7M2D9F4H6J8K0N3P5R',
            'outcome' => EventOutcome::APPLIED->value,
            'deliveries' => 2,
        ]);

        // Заказ остался от первой доставки: состав order-created
        // (2 футболки + бейсболка, 3619.50) — дубль ничего не перезаписал.
        $this->assertDatabaseHas('orders', [
            'id' => 'ord_7Q2KX9M4',
            'status' => 'created',
            'total' => '3619.50',
        ]);
        $this->assertDatabaseCount('order_items', 2);
    }
}
