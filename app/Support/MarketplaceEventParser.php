<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\IncomingWebhook;
use App\Data\MarketplaceEvent;
use App\Data\OrderCustomer;
use App\Data\OrderItemSnapshot;
use App\Data\OrderShipment;
use App\Data\OrderSnapshot;
use App\Enums\OrderStatus;
use App\Enums\PayloadIssue;
use App\Exceptions\Webhook\MalformedPayload;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Типизированный разбор тела вебхука MarketHub (T-021): шаги 5–11 §7 ТЗ.
 *
 * Единственное место, знающее протокол тела (task/marketplace-webhooks.md):
 * превращает сырые байты IncomingWebhook в MarketplaceEvent, отделяя ошибки
 * формата (400: не-JSON/не-UTF-8, несовпадение id — FR-WH-05) от ошибок
 * структуры (422: базовый конверт — FR-WH-06, снимок известного статуса —
 * FR-WH-08). Дискриминатор — PayloadIssue у MalformedPayload; HTTP-вид
 * строит WebhookResponseMapper и сюда не относится.
 *
 * Неизвестный тип и неизвестный строковый статус — НЕ ошибки (FR-WH-07,
 * ловушка T9): разбор завершается конвертом без снимка (order = null),
 * решение журналировать `ignored` принимает Action (шаги 8/10 §7, T-026).
 *
 * Дата-время — только ISO 8601 с явным смещением (±HH:MM или Z);
 * «настенное» время без offset неоднозначно и не принимается (FR-ORD-03).
 * День дополнительно проверяется checkdate(): Carbon молча прокатывает
 * несуществующие даты (2026-02-29 → 2026-03-01).
 *
 * Расхождение арифметики total (FR-WH-09, S-27) — не повод для отказа:
 * логируется warning (A-17) после успешной валидации снимка. Стоимость
 * позиций — Money::subtotal (единая точка денежной арифметики, T-022;
 * поведение и контекст warning — без изменений, см. DECISIONS.md,
 * задачи 021/022), сложение и сравнение — BigDecimal без float (NFR-02).
 */
final class MarketplaceEventParser
{
    /**
     * Известные типы событий (FR-WH-07, шаг 8 §7): список расширяется
     * маркетплейсом без уведомления, всё вне списка — unknown.
     */
    public const KNOWN_TYPES = [
        'order.created',
        'order.updated',
        'order.paid',
        'order.shipped',
        'order.delivered',
        'order.cancelled',
    ];

    /** ISO 8601 дата-время со смещением: дата, T, время, опциональные дробные секунды, Z либо ±HH:MM. */
    private const string DATETIME_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/';

    /** Денежная сумма: строка с двумя знаками после точки, минус допустим (FR-WH-08). */
    private const string AMOUNT_PATTERN = '/^-?\d+\.\d{2}$/';

    /** Код валюты: ровно три заглавные буквы, списком не ограничен (FR-WH-08). */
    private const string CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    public function isKnownType(string $type): bool
    {
        return in_array($type, self::KNOWN_TYPES, true);
    }

    /**
     * Разобрать сырые байты в типизированный конверт (шаги 5–11 §7).
     *
     * Для известного типа — вплоть до полностью валидного снимка
     * (ошибки конверта/снимка бросаются ДО журналирования, FR-ORD-08);
     * для неизвестного типа — только базовый конверт: формат data.order
     * не проверяется, чтобы расширение протокола не вызывало бесполезные
     * повторы (FR-WH-06/07).
     *
     * @throws MalformedPayload InvalidJson/EventIdMismatch (→ 400, FR-WH-05)
     *                          или InvalidEnvelope/InvalidSnapshot (→ 422, FR-WH-06/08)
     */
    public function parse(IncomingWebhook $webhook): MarketplaceEvent
    {
        $body = $this->decodeJson($webhook->rawBody);

        $this->assertEventIdMatches($webhook->webhookId, $body);

        $id = $this->nonEmptyString($body, 'id', PayloadIssue::InvalidEnvelope, 'id');
        $type = $this->nonEmptyString($body, 'type', PayloadIssue::InvalidEnvelope, 'type');
        $createdAt = $this->parseDatetime($body, 'created_at', PayloadIssue::InvalidEnvelope, 'created_at');
        $data = $this->objectValue($body, 'data', PayloadIssue::InvalidEnvelope, 'data');

        if (! $this->isKnownType($type)) {
            // Шаг 8 §7: неизвестный тип — data.order не читается вовсе.
            return new MarketplaceEvent($id, $type, $createdAt, order: null, orderId: null);
        }

        // Шаг 9 §7: структура известного типа — data.order объект,
        // order.id — непустая строка, order.status — строка (шаг 9
        // требует именно «строку», без непустоты — в отличие от
        // «непустых id/type» шага 7): пустая строка — значение вне
        // enum → ignored (FR-WH-07, ловушка T9); не-строка или
        // отсутствие — 422 (S-17).
        $order = $this->objectValue($data, 'order', PayloadIssue::InvalidEnvelope, 'data.order');
        $orderId = $this->nonEmptyString($order, 'id', PayloadIssue::InvalidEnvelope, 'data.order.id');
        $status = $this->stringValue($order, 'status', PayloadIssue::InvalidEnvelope, 'data.order.status');

        // Шаг 10 §7: строковый статус вне enum — снимок не валидируется.
        $orderStatus = OrderStatus::tryFrom($status);

        if ($orderStatus === null) {
            return new MarketplaceEvent($id, $type, $createdAt, order: null, orderId: $orderId);
        }

        // Шаг 11 §7: полная валидация снимка известного статуса.
        $snapshot = $this->buildSnapshot($order, $orderStatus, $orderId);

        $this->warnOnTotalMismatch($id, $snapshot);

        return new MarketplaceEvent($id, $type, $createdAt, order: $snapshot, orderId: $orderId);
    }

    /**
     * Шаг 5 §7 (FR-WH-05): тело — корректный JSON. Ошибки кодировки UTF-8
     * и синтаксиса для протокола неразличимы — обе invalid_json → 400.
     *
     * @return array<int|string, mixed> тело как JSON-объект
     */
    private function decodeJson(string $rawBody): array
    {
        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedPayload(PayloadIssue::InvalidJson);
        }

        if (! is_array($decoded)) {
            // Валидный JSON, но не объект (число/строка/null): конверту
            // неоткуда взять обязательные поля → 422, не 400 (FR-WH-06).
            throw new MalformedPayload(PayloadIssue::InvalidEnvelope, 'Тело вебхука не является JSON-объектом.');
        }

        return $decoded;
    }

    /**
     * Шаг 6 §7 (FR-WH-05): X-Webhook-Id обязан совпадать с `id` тела.
     * Отсутствующий или не-строчный body.id совпасть не может → 400
     * (проверка идёт до валидации конверта: порядок шагов 6→7 §7).
     *
     * @param array<int|string, mixed> $body
     */
    private function assertEventIdMatches(string $webhookId, array $body): void
    {
        if (($body['id'] ?? null) !== $webhookId) {
            throw new MalformedPayload(PayloadIssue::EventIdMismatch);
        }
    }

    /**
     * Полная валидация снимка известного статуса (шаг 11 §7, FR-WH-08):
     * типы и форматы всех обязательных полей по marketplace-webhooks.md.
     *
     * @param array<int|string, mixed> $order data.order после шага 9
     */
    private function buildSnapshot(array $order, OrderStatus $status, string $orderId): OrderSnapshot
    {
        $number = $this->nonEmptyString($order, 'number', PayloadIssue::InvalidSnapshot, 'data.order.number');

        $currency = $this->nonEmptyString($order, 'currency', PayloadIssue::InvalidSnapshot, 'data.order.currency');
        if (preg_match(self::CURRENCY_PATTERN, $currency) !== 1) {
            throw new MalformedPayload(
                PayloadIssue::InvalidSnapshot,
                'data.order.currency: ожидался код валюты из трёх заглавных букв.',
            );
        }

        return new OrderSnapshot(
            id: $orderId,
            number: $number,
            status: $status,
            currency: $currency,
            customer: $this->customer($order),
            items: $this->items($order),
            deliveryPrice: $this->amount($order, 'delivery_price', 'data.order.delivery_price'),
            discount: $this->amount($order, 'discount', 'data.order.discount'),
            total: $this->amount($order, 'total', 'data.order.total'),
            shipment: $this->shipment($order),
            paidAt: $this->nullableDatetime($order, 'paid_at', 'data.order.paid_at'),
            cancelledAt: $this->nullableDatetime($order, 'cancelled_at', 'data.order.cancelled_at'),
            cancelReason: $this->nullableString($order, 'cancel_reason', 'data.order.cancel_reason'),
            createdAt: $this->parseDatetime($order, 'created_at', PayloadIssue::InvalidSnapshot, 'data.order.created_at'),
            updatedAt: $this->parseDatetime($order, 'updated_at', PayloadIssue::InvalidSnapshot, 'data.order.updated_at'),
        );
    }

    /**
     * Обязательное поле-объект (data, data.order, customer, shipment).
     * Пустой объект ([]) эквивалентен пустому assoc-массиву после
     * json_decode и допустим — дальше провалятся его обязательные поля.
     *
     * @param array<int|string, mixed> $array
     * @return array<int|string, mixed>
     */
    private function objectValue(array $array, string $key, PayloadIssue $issue, string $path): array
    {
        $value = $array[$key] ?? null;

        if (! is_array($value)) {
            throw new MalformedPayload($issue, sprintf('%s: ожидался объект.', $path));
        }

        if ($value !== [] && array_is_list($value)) {
            throw new MalformedPayload($issue, sprintf('%s: ожидался объект, получен массив.', $path));
        }

        return $value;
    }

    /**
     * Customer: email/name — непустые строки. Формат адреса сознательно
     * не проверяется: невалидный email — предупреждение письма, а не
     * отказ вебхука (FR-MAIL-07); phone — string|null.
     *
     * @param array<int|string, mixed> $order
     */
    private function customer(array $order): OrderCustomer
    {
        $customer = $this->objectValue($order, 'customer', PayloadIssue::InvalidSnapshot, 'data.order.customer');

        return new OrderCustomer(
            email: $this->nonEmptyString($customer, 'email', PayloadIssue::InvalidSnapshot, 'data.order.customer.email'),
            name: $this->nonEmptyString($customer, 'name', PayloadIssue::InvalidSnapshot, 'data.order.customer.name'),
            phone: $this->nullableString($customer, 'phone', 'data.order.customer.phone'),
        );
    }

    /**
     * Позиции: список объектов в порядке снимка (FR-ORD-04); пустой
     * список протоколом не запрещён и принимается.
     *
     * @param array<int|string, mixed> $order
     * @return list<OrderItemSnapshot>
     */
    private function items(array $order): array
    {
        $items = $order['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            throw new MalformedPayload(
                PayloadIssue::InvalidSnapshot,
                'data.order.items: ожидался массив позиций.',
            );
        }

        $parsed = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new MalformedPayload(
                    PayloadIssue::InvalidSnapshot,
                    sprintf('data.order.items.%d: ожидался объект позиции.', $index),
                );
            }

            $parsed[] = new OrderItemSnapshot(
                sku: $this->nonEmptyString($item, 'sku', PayloadIssue::InvalidSnapshot, sprintf('data.order.items.%d.sku', $index)),
                name: $this->nonEmptyString($item, 'name', PayloadIssue::InvalidSnapshot, sprintf('data.order.items.%d.name', $index)),
                quantity: $this->quantity($item, $index),
                price: $this->amount($item, 'price', sprintf('data.order.items.%d.price', $index)),
            );
        }

        return $parsed;
    }

    /**
     * quantity — целое ≥ 1 (FR-WH-08): строка "3" и bool — неверный
     * тип, а не значение для приведения.
     *
     * @param array<int|string, mixed> $item
     */
    private function quantity(array $item, int $index): int
    {
        $value = $item['quantity'] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new MalformedPayload(
                PayloadIssue::InvalidSnapshot,
                sprintf('data.order.items.%d.quantity: ожидалось целое число не меньше 1.', $index),
            );
        }

        return $value;
    }

    /**
     * shipment — null либо объект с тремя непустыми строками (формат
     * URL протоколом задаётся как строка и отдельно не проверяется).
     *
     * @param array<int|string, mixed> $order
     */
    private function shipment(array $order): ?OrderShipment
    {
        $shipment = $order['shipment'] ?? null;

        if ($shipment === null) {
            return null;
        }

        if (! is_array($shipment) || ($shipment !== [] && array_is_list($shipment))) {
            throw new MalformedPayload(
                PayloadIssue::InvalidSnapshot,
                'data.order.shipment: ожидался объект или null.',
            );
        }

        return new OrderShipment(
            carrier: $this->nonEmptyString($shipment, 'carrier', PayloadIssue::InvalidSnapshot, 'data.order.shipment.carrier'),
            trackingNumber: $this->nonEmptyString($shipment, 'tracking_number', PayloadIssue::InvalidSnapshot, 'data.order.shipment.tracking_number'),
            trackingUrl: $this->nonEmptyString($shipment, 'tracking_url', PayloadIssue::InvalidSnapshot, 'data.order.shipment.tracking_url'),
        );
    }

    /**
     * Денежная сумма: строка ^-?\d+\.\d{2}$ (формат amount протокола,
     * NFR-02: float в деньгах запрещён).
     *
     * @param array<int|string, mixed> $array
     */
    private function amount(array $array, string $key, string $path): string
    {
        $value = $array[$key] ?? null;

        if (! is_string($value) || preg_match(self::AMOUNT_PATTERN, $value) !== 1) {
            throw new MalformedPayload(
                PayloadIssue::InvalidSnapshot,
                sprintf('%s: ожидалась строка-сумма с двумя знаками после точки.', $path),
            );
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $array
     */
    private function nonEmptyString(array $array, string $key, PayloadIssue $issue, string $path): string
    {
        $value = $array[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new MalformedPayload($issue, sprintf('%s: ожидалась непустая строка.', $path));
        }

        return $value;
    }

    /**
     * Обязательное поле-строка без требования непустоты: `order.status`
     * по шагу 9 §7 — именно «строка» (в отличие от «непустых id/type»
     * шага 7): пустая строка — строковое значение вне enum → ветка
     * ignored (FR-WH-07), а не 422 с бесполезными повторами (T9).
     *
     * @param array<int|string, mixed> $array
     */
    private function stringValue(array $array, string $key, PayloadIssue $issue, string $path): string
    {
        $value = $array[$key] ?? null;

        if (! is_string($value)) {
            throw new MalformedPayload($issue, sprintf('%s: ожидалась строка.', $path));
        }

        return $value;
    }

    /**
     * Nullable-строка (phone, cancel_reason): null либо любая строка —
     * пустые значения типизированы и проходят; парсер проверяет только
     * тип, бизнес-смысл обрабатывают нижние слои (письма, БД).
     *
     * @param array<int|string, mixed> $array
     */
    private function nullableString(array $array, string $key, string $path): ?string
    {
        $value = $array[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new MalformedPayload(
                PayloadIssue::InvalidSnapshot,
                sprintf('%s: ожидалась строка или null.', $path),
            );
        }

        return $value;
    }

    /**
     * Дата-время ISO 8601 с обязательным смещением (±HH:MM или Z),
     * дробные секунды допустимы (момент однозначен и без потерь).
     *
     * @param array<int|string, mixed> $array
     */
    private function parseDatetime(array $array, string $key, PayloadIssue $issue, string $path): CarbonImmutable
    {
        $value = $array[$key] ?? null;

        if (! is_string($value) || preg_match(self::DATETIME_PATTERN, $value, $matches) !== 1) {
            throw new MalformedPayload(
                $issue,
                sprintf('%s: ожидалась дата-время ISO 8601 со смещением часового пояса.', $path),
            );
        }

        // Carbon «прокатывает» несуществующие дни (2026-02-29 → 03-01),
        // поэтому календарная проверка отдельно.
        if (checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) !== true) {
            throw new MalformedPayload($issue, sprintf('%s: несуществующая календарная дата.', $path));
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new MalformedPayload($issue, sprintf('%s: не удалось разобрать значение как момент времени.', $path));
        }
    }

    /**
     * @param array<int|string, mixed> $array
     */
    private function nullableDatetime(array $array, string $key, string $path): ?CarbonImmutable
    {
        if (($array[$key] ?? null) === null) {
            return null;
        }

        return $this->parseDatetime($array, $key, PayloadIssue::InvalidSnapshot, $path);
    }

    /**
     * Warning о расхождении арифметики total (FR-WH-09, A-17, S-27):
     * Σ(price×quantity) + delivery − discount ≠ total. Стоимость каждой
     * позиции — Money::subtotal: одна точка арифметики на парсер и API
     * (T-022; письма подключат её в T-033). Событие принято, total
     * хранится дословно как прислал MarketHub. Предупреждение без
     * состояния: повторная доставка повторит запись в лог (см. DECISIONS,
     * задача 021). Контекст — без тела и персональных данных (NFR-04/06).
     */
    private function warnOnTotalMismatch(string $eventId, OrderSnapshot $snapshot): void
    {
        $expected = BigDecimal::of($snapshot->deliveryPrice);

        foreach ($snapshot->items as $item) {
            $expected = $expected->plus(
                BigDecimal::of(Money::subtotal($item->price, $item->quantity)),
            );
        }

        $expected = $expected->minus(BigDecimal::of($snapshot->discount));

        if ($expected->compareTo(BigDecimal::of($snapshot->total)) === 0) {
            return;
        }

        Log::warning('Расхождение total снимка с арифметикой позиций; событие принято (FR-WH-09).', [
            'reason' => 'total_mismatch',
            'event_id' => $eventId,
            'order_id' => $snapshot->id,
            'expected_total' => (string) $expected->toScale(2),
            'declared_total' => $snapshot->total,
        ]);
    }
}
