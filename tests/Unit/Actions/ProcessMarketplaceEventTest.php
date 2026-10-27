<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Webhooks\ProcessMarketplaceEvent;
use App\Data\IncomingWebhook;
use App\Enums\EventOutcome;
use App\Enums\OrderStatus;
use App\Enums\PayloadIssue;
use App\Enums\WebhookProcessingResult;
use App\Exceptions\Webhook\MalformedPayload;
use App\Exceptions\Webhook\WebhookUnavailable;
use App\Models\Order;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MarketplaceExamples;
use Tests\Support\MutatedWebhookBody;
use Tests\TestCase;

/**
 * ProcessMarketplaceEvent (T-010/T-021/T-026/T-027/T-028): маршрутизация
 * по данным конверта, журналирование событий, контур конкурентности и
 * применение полного снимка заказа. Валидное событие известного типа
 * проходит блокировку заказа `order:{id}` (шаг 12 §7), вставку журнала,
 * блокировку строки `FOR UPDATE` (13b), сравнение версий (13c) и полную
 * перезапись заказа с позициями (13e) — в одной транзакции с записью
 * журнала (FR-ORD-01); неизвестный тип/статус журналируются как ignored
 * (шаги 8/10 §7); невалидное тело отбрасывается парсером до
 * журналирования (MalformedPayload, 400/422).
 *
 * С T-026 Action пишет в БД, поэтому набор использует RefreshDatabase
 * (правило «DB-тесты — только с DB-трейтами»); поля записи журнала
 * (payload байт в байт, хеш, signature timestamp, метки доставок) —
 * здесь, полный HTTP-контракт тех же веток — feature-наборы T-017
 * (IgnoredEvents/DuplicateDelivery — зелёные с T-026, OrderLock —
 * с T-027, SnapshotApplication/CompetingVersions — T-028+).
 */
class ProcessMarketplaceEventTest extends TestCase
{
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    private const string PAID_EVENT_ID = 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC';

    private const string UNKNOWN_TYPE_EVENT_ID = 'evt_01JBUNKNOWNTYPE000000000';

    /** Идентификатор заказа в примере order-paid (ключ блокировки order:{id}). */
    private const string ORDER_ID = 'ord_7Q2KX9M4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    public function test_action_is_resolvable_from_container(): void
    {
        $this->assertInstanceOf(
            ProcessMarketplaceEvent::class,
            $this->app->make(ProcessMarketplaceEvent::class),
        );
    }

    /**
     * Валидное событие известного типа с корректным снимком проходит
     * разбор (T-021), блокировку заказа и загрузку строки FOR UPDATE
     * (T-027, шаги 12/13b §7), вставляется в журнал (13a) и применяется
     * (13c–13g, T-028): заказ создан из paid-снимка полями MarketHub,
     * позиции сохранены, версия снимка и сырой snapshot записаны, журнал
     * финализирован (applied + processed_at, шаг 13g).
     *
     * Перевёрнут с пиннинга заглушки применения (правило задачи 016):
     * до T-028 падал LogicException-заглушкой внутри транзакции.
     */
    public function test_known_type_valid_snapshot_is_applied(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $result = $action->handle($this->paidWebhook());

        $this->assertSame(WebhookProcessingResult::PROCESSED, $result);

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame('4909.50', $order->total);
        $this->assertTrue(
            $order->paid_at->equalTo(CarbonImmutable::parse('2026-10-21T13:05:47+03:00')),
            'paid_at — момент MarketHub (13:05:47+03:00 = 10:05:47Z), а не время обработки.',
        );
        $this->assertTrue(
            $order->placed_at->equalTo(CarbonImmutable::parse('2026-10-21T13:02:11+03:00')),
            'placed_at = created_at MarketHub, отдельно от системных меток Eloquent.',
        );
        $this->assertSame(self::PAID_EVENT_ID, $order->version_event_id);
        $this->assertTrue(
            $order->version_event_created_at->equalTo(CarbonImmutable::parse('2026-10-21T10:05:48+00:00')),
            'Компоненты версии снимка сохраняются на заказе (FR-ORD-02).',
        );
        $this->assertSame(2, $order->items()->count(), 'Позиции снимка сохранены.');
        $this->assertSame('ord_7Q2KX9M4', $order->snapshot['id'], 'orders.snapshot — сырой data.order как прислал MarketHub.');
        $this->assertCount(2, $order->snapshot['items']);

        $event = WebhookEvent::query()->findOrFail(self::PAID_EVENT_ID);
        $this->assertSame(EventOutcome::APPLIED, $event->outcome);
        $this->assertSame(
            CarbonImmutable::parse(self::FROZEN_NOW)->format('Y-m-d H:i:s'),
            $event->processed_at->format('Y-m-d H:i:s'),
            'processed_at финализируется на шаге 13g.',
        );
    }

    /**
     * FR-ORD-04: свежий снимок перезаписывает заказ полностью — позиции
     * заменяются целиком (старых строк нет), shipment сбрасывается в null
     * явным отсутствием в снимке, email хранится как пришёл, а
     * нормализованный — trim + lower (§6.3).
     */
    public function test_fresh_snapshot_replaces_items_and_shipment_and_normalizes_email(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        // Первый снимок со shipment (order-shipped): трек заполнен.
        $this->assertSame(WebhookProcessingResult::PROCESSED, $action->handle(new IncomingWebhook(
            webhookId: 'evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0',
            signatureTimestamp: 1792577149,
            rawBody: MarketplaceExamples::raw('order-shipped'),
        )));

        $this->assertDatabaseHas('orders', ['id' => self::ORDER_ID, 'shipment_tracking_number' => '1098765432']);

        // Свежая коррекция order.updated: одна новая позиция вместо двух,
        // shipment в снимке отсутствует (null), email с регистром и краями.
        $result = $action->handle(new IncomingWebhook(
            webhookId: 'evt_01JBITEMREPLACE000000000000',
            signatureTimestamp: 1792577149,
            rawBody: MutatedWebhookBody::fromExample('order-updated')
                ->withStatus('paid')
                ->withPath(['data', 'order', 'customer', 'email'], '  Anna.Smirnova@Example.COM ')
                ->withPath(['data', 'order', 'items'], [
                    ['sku' => 'NEW-SKU-1', 'name' => 'Новая позиция', 'quantity' => 2, 'price' => '10.00'],
                ])
                ->with('id', 'evt_01JBITEMREPLACE000000000000')
                ->with('created_at', '2026-10-22T09:00:01+00:00')
                ->withUpdatedAt('2026-10-22T09:00:00+00:00')
                ->body(),
        ));

        $this->assertSame(WebhookProcessingResult::PROCESSED, $result);

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame('  Anna.Smirnova@Example.COM ', $order->customer_email, 'Email хранится как пришёл в снимке.');
        $this->assertSame('anna.smirnova@example.com', $order->customer_email_normalized, 'Нормализованный email — trim + lower.');
        $this->assertNull($order->shipment_carrier, 'Полная перезапись сбрасывает shipment в null (FR-ORD-04).');
        $this->assertNull($order->shipment_tracking_number);
        $this->assertNull($order->shipment_tracking_url);

        $items = $order->items()->orderBy('position')->get();
        $this->assertCount(1, $items, 'Позиции заменяются целиком: старых строк не остаётся.');
        $this->assertSame('NEW-SKU-1', $items[0]->sku);
        $this->assertSame(1, $items[0]->position, 'Порядок позиций снимка сохраняется (position с 1).');
        $this->assertSame(2, $items[0]->quantity);
        $this->assertSame('10.00', $items[0]->price);
    }

    /**
     * FR-ORD-02 (шаг 13c §7): снимок с непревышающей версией не меняет
     * заказ (ни поля, ни позиции), исход журнала — stale с финальным
     * processed_at (FR-ORD-08); HTTP-контракт той же ветки — S-05/S-19
     * в SnapshotApplicationTest.
     */
    public function test_stale_snapshot_keeps_order_and_finalizes_journal_as_stale(): void
    {
        Order::factory()->create([
            'id' => self::ORDER_ID,
            'status' => OrderStatus::PAID,
            'total' => '5000.00',
            'paid_at' => '2026-10-21 10:05:47',
            'source_updated_at' => '2026-10-21 10:05:47',
            'version_event_created_at' => '2026-10-21 10:05:48',
            'version_event_id' => self::PAID_EVENT_ID,
        ]);

        $action = $this->app->make(ProcessMarketplaceEvent::class);

        // order-updated (10:03:30Z) старее применённой версии paid (10:05:47Z).
        $result = $action->handle(new IncomingWebhook(
            webhookId: 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            signatureTimestamp: 1792577149,
            rawBody: MarketplaceExamples::raw('order-updated'),
        ));

        $this->assertSame(WebhookProcessingResult::STALE, $result);

        $order = Order::query()->findOrFail(self::ORDER_ID);
        $this->assertSame(OrderStatus::PAID, $order->status, 'Устаревший снимок не меняет статус.');
        $this->assertSame('5000.00', $order->total, 'Устаревший снимок не перезаписывает суммы.');
        $this->assertSame(self::PAID_EVENT_ID, $order->version_event_id, 'Версия заказа не двигается.');
        $this->assertSame(0, $order->items()->count(), 'Позиции устаревшего снимка не создаются.');

        $event = WebhookEvent::query()->findOrFail('evt_01JAXM0C3T5V7X9Z1B3D5F7H9K');
        $this->assertSame(EventOutcome::STALE, $event->outcome, 'Шаг 13c фиксирует исход stale (FR-ORD-08).');
        $this->assertNotNull($event->processed_at, 'stale — конечный исход, метка завершения проставлена.');
    }

    /**
     * T-027 (шаг 12 §7, FR-ORD-07): занятая блокировка `order:{id}` —
     * обработка повторяемо недоступна: WebhookUnavailable (HTTP-контракт
     * 503 — MarketplaceWebhookOrderLockTest), событие в журнал не
     * попадает — вставка 13a находится внутри контура блокировки,
     * до неё обработка не доходит.
     */
    public function test_busy_order_lock_is_repeatable_refusal_without_journal(): void
    {
        config(['marketplace.order_lock_timeout' => 0]);

        $lock = Cache::lock('order:' . self::ORDER_ID, 10);
        $this->assertTrue($lock->get(), 'Тестовая подготовка: лок заказа должен быть занят тестом.');

        try {
            $action = $this->app->make(ProcessMarketplaceEvent::class);

            try {
                $action->handle($this->paidWebhook());

                $this->fail('Ожидался WebhookUnavailable: блокировка заказа занята.');
            } catch (WebhookUnavailable) {
                // Ожидаемый повторяемый отказ — рендерится в 503.
            }

            // Вставка 13a — внутри контура блокировки: при отказе захвата
            // записи в журнале нет.
            $this->assertDatabaseCount('webhook_events', 0);
        } finally {
            $lock->release();
        }
    }

    /**
     * T-027 (§7 шаг 13): блокировка освобождается в finally даже при
     * исключении внутри транзакции применения — следующий обработчик
     * того же заказа не ждёт истечения lease (15 с).
     *
     * Перевёрнут с пиннинга заглушки применения (правило задачи 016):
     * сбой применения инжектируется событием модели Order::saving —
     * сохранение заказа (13e, T-028) падает внутри транзакции под
     * блокировкой, исключение покидает handle(), откат уносит и запись
     * журнала (FR-ORD-01).
     */
    public function test_order_lock_is_released_when_application_fails(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        // Инжекция живёт до конца теста: каждый тест получает свежий
        // dispatcher моделей (InteractsWithTestCaseLifecycle::setUp).
        Order::saving(static function (): void {
            throw new \RuntimeException('Инжектированный сбой применения в тесте.');
        });

        try {
            $action->handle($this->paidWebhook());

            $this->fail('Ожидался инжектированный сбой применения: исключение должно покинуть handle().');
        } catch (\RuntimeException) {
            // Сбой применения упал внутри контура — lock обязан
            // освобождаться в finally, а не по истечении lease.
        }

        $probe = Cache::lock('order:' . self::ORDER_ID, 10);
        $this->assertTrue($probe->get(), 'Блокировка освобождена в finally, а не по истечении lease.');
        $probe->release();

        // Транзакция применения откатилась целиком (FR-ORD-01).
        $this->assertDatabaseCount('webhook_events', 0);
    }

    /**
     * Неизвестный тип (шаг 8 §7): 200 ignored, событие сохранено с
     * order_id = NULL и исходом ignored; payload — сырые байты тела
     * байт в байт, включая финальный перевод строки (FR-ORD-08, T1),
     * payload hash — от того же тела; deliveries=1, момент приёма
     * зафиксирован, last_received_at ещё нет.
     */
    public function test_unknown_type_is_journaled_as_ignored(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $rawBody = '{"id":"' . self::UNKNOWN_TYPE_EVENT_ID . '","type":"order.refunded",'
            . '"created_at":"2026-10-21T10:05:48+00:00","data":{}}' . "\n";

        $result = $action->handle(new IncomingWebhook(
            webhookId: self::UNKNOWN_TYPE_EVENT_ID,
            signatureTimestamp: 1792577149,
            rawBody: $rawBody,
        ));

        $this->assertSame(WebhookProcessingResult::IGNORED, $result);

        $event = WebhookEvent::query()->findOrFail(self::UNKNOWN_TYPE_EVENT_ID);
        $this->assertSame('order.refunded', $event->type);
        $this->assertNull($event->order_id, 'data.order для неизвестного типа не читается (шаг 8 §7).');
        $this->assertSame($rawBody, $event->payload, 'Сырое тело хранится байт в байт, включая финальный перевод строки.');
        $this->assertSame(hash('sha256', $rawBody), $event->payload_sha256);
        $this->assertSame(1792577149, $event->signature_timestamp);
        $this->assertSame(EventOutcome::IGNORED, $event->outcome);
        $this->assertSame(1, $event->deliveries);
        $this->assertSame(
            CarbonImmutable::parse(self::FROZEN_NOW)->format('Y-m-d H:i:s'),
            $event->received_at->format('Y-m-d H:i:s'),
        );
        $this->assertNull($event->last_received_at, 'last_received_at появляется только у повторной доставки.');
        $this->assertNotNull($event->processed_at, 'ignored — конечный исход, обработка завершена на вставке.');
    }

    /**
     * Неизвестный строковый статус у известного типа (шаг 10 §7, S-36):
     * 200 ignored, событие сохранено с order_id из конверта — отдельная
     * от unknown type ветка маршрутизации (ревью r2, P3).
     */
    public function test_unknown_status_is_journaled_as_ignored_with_order_id(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $result = $action->handle(new IncomingWebhook(
            webhookId: 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            signatureTimestamp: 1792577149,
            rawBody: MutatedWebhookBody::fromExample('order-updated')
                ->withStatus('returned')
                ->body(),
        ));

        $this->assertSame(WebhookProcessingResult::IGNORED, $result);

        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            'type' => 'order.updated',
            'order_id' => 'ord_7Q2KX9M4',
            'outcome' => EventOutcome::IGNORED->value,
            'deliveries' => 1,
        ]);
    }

    /**
     * Повторная доставка события неизвестного типа (S-02, общая
     * duplicate-ветка шага 8 §7): 200 duplicate; deliveries=2,
     * last_received_at обновлён; исходные payload/payload_sha256/
     * outcome/signature_timestamp не перезаписаны — «duplicate» только
     * HTTP-ответ (FR-ORD-01/08).
     */
    public function test_unknown_type_redelivery_is_duplicate_and_keeps_original_row(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $originalBody = '{"id":"' . self::UNKNOWN_TYPE_EVENT_ID . '","type":"order.refunded",'
            . '"created_at":"2026-10-21T10:05:48+00:00","data":{}}';

        $this->assertSame(
            WebhookProcessingResult::IGNORED,
            $action->handle(new IncomingWebhook(
                webhookId: self::UNKNOWN_TYPE_EVENT_ID,
                signatureTimestamp: 1792577149,
                rawBody: $originalBody,
            )),
        );

        // Повтор: новый timestamp подписи и изменённое тело (S-02).
        $alteredBody = '{"id":"' . self::UNKNOWN_TYPE_EVENT_ID . '","type":"order.refunded",'
            . '"created_at":"2026-10-21T10:05:48+00:00","data":{"reason":"rerun"}}';

        $result = $action->handle(new IncomingWebhook(
            webhookId: self::UNKNOWN_TYPE_EVENT_ID,
            signatureTimestamp: 1792577156,
            rawBody: $alteredBody,
        ));

        $this->assertSame(WebhookProcessingResult::DUPLICATE, $result);

        $event = WebhookEvent::query()->findOrFail(self::UNKNOWN_TYPE_EVENT_ID);
        $this->assertSame($originalBody, $event->payload, 'Исходное тело дублем не перезаписывается.');
        $this->assertSame(hash('sha256', $originalBody), $event->payload_sha256);
        $this->assertSame(EventOutcome::IGNORED, $event->outcome);
        $this->assertSame(1792577149, $event->signature_timestamp, 'Timestamp первой доставки сохранён.');
        $this->assertSame(2, $event->deliveries);
        $this->assertNotNull($event->last_received_at);
        // Один event.id — одна строка журнала.
        $this->assertDatabaseCount('webhook_events', 1);
    }

    /**
     * Невалидное тело отбрасывается парсером до журналирования:
     * MalformedPayload с типизированной причиной (FR-WH-05/06/08).
     */
    public function test_invalid_body_is_rejected_by_parser_before_journal(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        try {
            $action->handle(new IncomingWebhook(
                webhookId: self::PAID_EVENT_ID,
                signatureTimestamp: 1792577149,
                rawBody: '{"id":"' . self::PAID_EVENT_ID . '","type":"order.paid"}',
            ));

            $this->fail('Ожидался MalformedPayload(InvalidEnvelope).');
        } catch (MalformedPayload $exception) {
            $this->assertSame(PayloadIssue::InvalidEnvelope, $exception->issue);
        }

        // Невалидные тела в журнал не попадают (FR-ORD-08).
        $this->assertDatabaseCount('webhook_events', 0);
    }

    /** Контекст доставки: пример order-paid, подписанный идентификатор события из тела. */
    private function paidWebhook(): IncomingWebhook
    {
        return new IncomingWebhook(
            webhookId: self::PAID_EVENT_ID,
            signatureTimestamp: 1792577149,
            rawBody: MarketplaceExamples::raw('order-paid'),
        );
    }
}
