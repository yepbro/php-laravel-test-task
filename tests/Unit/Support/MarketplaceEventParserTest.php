<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Data\IncomingWebhook;
use App\Data\MarketplaceEvent;
use App\Enums\OrderStatus;
use App\Enums\PayloadIssue;
use App\Exceptions\Webhook\MalformedPayload;
use App\Support\MarketplaceEventParser;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use JsonException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\MarketplaceExamples;
use Tests\Support\MutatedWebhookBody;
use Tests\TestCase;

/**
 * Типизированный разбор конверта и снимка (T-021): шаги 5–11 §7 ТЗ.
 *
 * Полный HTTP-путь тех же правил (вплоть до кодов ответа и состояния БД)
 * покрыт feature-наборами T-017 (MarketplaceWebhookPayloadErrorsTest,
 * IgnoredEventsTest) — они зеленеют после снятия заглушки HMAC (T-024)
 * и журналирования (T-026); здесь — поведение парсера напрямую: ошибки
 * формата (InvalidJson/EventIdMismatch → 400) против структуры
 * (InvalidEnvelope/InvalidSnapshot → 422), ветки unknown type/status,
 * money-форматы, warning о расхождении total (FR-WH-09, S-27).
 */
class MarketplaceEventParserTest extends TestCase
{
    private MarketplaceEventParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new MarketplaceEventParser();
    }

    /**
     * Happy path: пример order-paid (известный тип и статус) разбирается
     * в полностью валидный OrderSnapshot — поля, позиции, суммы, моменты.
     */
    public function test_parses_paid_example_into_validated_snapshot(): void
    {
        $event = $this->parser->parse($this->webhookForExample('order-paid'));

        $this->assertSame('evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC', $event->id);
        $this->assertSame('order.paid', $event->type);
        $this->assertSame('ord_7Q2KX9M4', $event->orderId);
        $this->assertTrue(
            $event->createdAt->equalTo(CarbonImmutable::parse('2026-10-21T10:05:48+00:00')),
            'created_at конверта — момент времени.',
        );

        $snapshot = $event->order;
        $this->assertNotNull($snapshot);
        $this->assertSame('ord_7Q2KX9M4', $snapshot->id);
        $this->assertSame('MH-1048213', $snapshot->number);
        $this->assertSame(OrderStatus::PAID, $snapshot->status);
        $this->assertSame('RUB', $snapshot->currency);
        $this->assertSame('anna.smirnova@example.com', $snapshot->customer->email);
        $this->assertSame('+79161234567', $snapshot->customer->phone);
        $this->assertCount(2, $snapshot->items);
        $this->assertSame('TSHIRT-BLK-M', $snapshot->items[0]->sku);
        $this->assertSame('Футболка базовая, чёрная, M', $snapshot->items[0]->name);
        $this->assertSame(3, $snapshot->items[0]->quantity);
        $this->assertSame('1290.00', $snapshot->items[0]->price);
        $this->assertSame('CAP-NVY', $snapshot->items[1]->sku);
        $this->assertSame('349.00', $snapshot->deliveryPrice);
        $this->assertSame('200.00', $snapshot->discount);
        $this->assertSame('4909.50', $snapshot->total);
        $this->assertNull($snapshot->shipment);
        $this->assertTrue($snapshot->paidAt->equalTo(CarbonImmutable::parse('2026-10-21T13:05:47+03:00')));
        $this->assertNull($snapshot->cancelledAt);
        $this->assertNull($snapshot->cancelReason);
        $this->assertTrue($snapshot->createdAt->equalTo(CarbonImmutable::parse('2026-10-21T13:02:11+03:00')));
        $this->assertTrue($snapshot->updatedAt->equalTo(CarbonImmutable::parse('2026-10-21T13:05:47+03:00')));
    }

    /**
     * S-19: снимки приходят в разных поясах (+03:00 и +00:00) — парсер
     * хранит моменты с исходным смещением, не строки и не «настенное»
     * время. order-shipped — единственный пример с updated_at в +00:00
     * и непустым shipment.
     */
    public function test_preserves_source_offsets_and_shipment_of_shipped_example(): void
    {
        $event = $this->parser->parse($this->webhookForExample('order-shipped'));

        $snapshot = $event->order;
        $this->assertNotNull($snapshot);
        $this->assertSame('+00:00', $snapshot->updatedAt->getTimezone()->getName());
        $this->assertSame(OrderStatus::SHIPPED, $snapshot->status);
        $this->assertSame('СДЭК', $snapshot->shipment?->carrier);
        $this->assertSame('1098765432', $snapshot->shipment?->trackingNumber);
        $this->assertSame(
            'https://www.cdek.ru/ru/tracking?order_id=1098765432',
            $snapshot->shipment?->trackingUrl,
        );
        // Один и тот же момент в разных поясах: 13:05:47+03:00 = 10:05:47Z.
        $this->assertTrue(
            $snapshot->paidAt->utc()->equalTo(CarbonImmutable::parse('2026-10-21T10:05:47+00:00')),
        );
    }

    /**
     * FR-WH-07/T9: неизвестный тип распознаётся после базового конверта;
     * data.order для него не обязателен и не проверяется вовсе — даже
     * откровенно сломанный объект не мешает разбору (расширение протокола
     * не должно вызывать бесполезные повторы).
     */
    public function test_unknown_type_skips_order_validation_entirely(): void
    {
        $body = MutatedWebhookBody::fromExample('order-paid')
            ->withType('order.refunded')
            ->withPath(['data', 'order'], 'совсем не объект')
            ->body();

        $event = $this->parser->parse($this->webhook($body));

        $this->assertFalse($this->parser->isKnownType('order.refunded'));
        $this->assertNull($event->order);
        $this->assertNull($event->orderId, 'order_id не извлекается для неизвестного типа (шаг 8 §7).');
        $this->assertSame('evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC', $event->id);
    }

    /** FR-WH-06: валидный конверт без data.order у неизвестного типа разбирается. */
    public function test_unknown_type_without_order_object_is_parsed(): void
    {
        $body = '{"id":"evt_wo_order","type":"order.refunded","created_at":"2026-10-21T10:05:48+00:00","data":{}}';

        $event = $this->parser->parse($this->webhook($body));

        $this->assertNull($event->order);
        $this->assertNull($event->orderId);
    }

    /**
     * FR-WH-07/S-36: неизвестный строковый статус у известного типа — не
     * ошибка: снимок не строится, но order_id из конверта доступен журналу
     * (шаг 10 §7 — запись webhook_events с order_id).
     */
    public function test_unknown_string_status_keeps_order_id_without_snapshot(): void
    {
        $body = MutatedWebhookBody::fromExample('order-updated')
            ->withStatus('returned')
            ->body();

        $event = $this->parser->parse($this->webhook($body));

        $this->assertSame('order.updated', $event->type);
        $this->assertNull($event->order, 'Снимок неизвестного статуса не валидируется и не строится.');
        $this->assertSame('ord_7Q2KX9M4', $event->orderId);
    }

    /**
     * Граница статуса (ревью r2, P2): шаг 9 §7 требует от status
     * «строку» — без непустоты (в отличие от «непустых id/type»
     * шага 7). Пустая и пробельная строка — строковые значения вне
     * enum → та же ignored-ветка (FR-WH-07), не 422: глитч-событие
     * не должно получать бесполезных повторов (ловушка T9).
     */
    #[TestWith([''])]
    #[TestWith([' '])]
    public function test_blank_string_status_is_unknown_status_not_envelope_error(string $status): void
    {
        $body = MutatedWebhookBody::fromExample('order-updated')
            ->withStatus($status)
            ->body();

        $event = $this->parser->parse($this->webhook($body));

        $this->assertNull($event->order, 'Строковый статус вне enum не валидируется как снимок.');
        $this->assertSame('ord_7Q2KX9M4', $event->orderId, 'order_id из конверта доступен журналу (шаг 10 §7).');
    }

    /** Список известных типов — ровно шесть из FR-WH-07. */
    public function test_known_types_match_protocol_list(): void
    {
        $known = ['order.created', 'order.updated', 'order.paid', 'order.shipped', 'order.delivered', 'order.cancelled'];
        $unknown = ['order.refunded', 'order.paid.v2', '', 'ORD.PAID'];

        foreach ($known as $type) {
            $this->assertTrue($this->parser->isKnownType($type), sprintf('Тип %s должен быть известным.', $type));
        }

        foreach ($unknown as $type) {
            $this->assertFalse($this->parser->isKnownType($type), sprintf('Тип %s не должен быть известным.', $type));
        }
    }

    /**
     * Шаг 5 §7/FR-WH-05: не-JSON и не-UTF-8 тело — invalid_json (→ 400),
     * а не 422/500. X-Webhook-Id здесь не участвует — проверка тела.
     */
    #[TestWith(['сломанный json'])]
    #[TestWith(['{"id":"evt_broken", не json' . "\n"])]
    #[TestWith(['{"id":"evt_encoding","note":"' . "\xB1" . '"}'])]
    public function test_invalid_json_or_encoding_is_rejected(string $rawBody): void
    {
        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse(new IncomingWebhook(
                webhookId: 'evt_any',
                signatureTimestamp: 1792577149,
                rawBody: $rawBody,
            )),
            PayloadIssue::InvalidJson,
        );
    }

    /**
     * Валидный JSON, но не объект: конверту неоткуда взять обязательные
     * поля — invalid_envelope (422), не invalid_json (FR-WH-06).
     */
    #[TestWith(['null'])]
    #[TestWith(['"hello"'])]
    #[TestWith(['123'])]
    public function test_non_object_json_is_invalid_envelope(string $rawBody): void
    {
        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse(new IncomingWebhook(
                webhookId: 'evt_any',
                signatureTimestamp: 1792577149,
                rawBody: $rawBody,
            )),
            PayloadIssue::InvalidEnvelope,
        );
    }

    /**
     * Шаг 6 §7/S-16: X-Webhook-Id обязан строго совпадать с body.id;
     * отсутствующий или не-строчный body.id совпасть не может.
     */
    public function test_event_id_must_match_header(): void
    {
        // Валидное тело, другой заголовок.
        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse(
                $this->webhook(MutatedWebhookBody::fromExample('order-paid')->body(), 'evt_totally_different'),
            ),
            PayloadIssue::EventIdMismatch,
        );

        // body.id отсутствует.
        $missing = '{"type":"order.paid","created_at":"2026-10-21T10:05:48+00:00","data":{}}';
        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse($this->webhook($missing, 'evt_missing_id')),
            PayloadIssue::EventIdMismatch,
        );

        // body.id не строка — совпасть с заголовком не может.
        $nonString = '{"id":123,"type":"order.paid","created_at":"2026-10-21T10:05:48+00:00","data":{}}';
        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse($this->webhook($nonString, 'evt_non_string_id')),
            PayloadIssue::EventIdMismatch,
        );
    }

    /**
     * Шаг 7 §7/FR-WH-06: базовый конверт — непустые строки id/type,
     * created_at ISO 8601 со смещением (несуществующая дата и offset
     * вне диапазона — тоже отказ), data — объект.
     */
    #[TestWith(['type пустая строка', ['type'], ''])]
    #[TestWith(['type не строка', ['type'], 42])]
    #[TestWith(['id пустая строка', ['id'], ''])]
    #[TestWith(['created_at отсутствует', ['created_at'], null])]
    #[TestWith(['created_at не строка', ['created_at'], 1792577149])]
    #[TestWith(['created_at без смещения', ['created_at'], '2026-10-21T10:05:48'])]
    #[TestWith(['created_at только дата', ['created_at'], '2026-10-21'])]
    #[TestWith(['created_at несуществующий день', ['created_at'], '2026-02-30T10:05:48+00:00'])]
    #[TestWith(['created_at смещение вне диапазона', ['created_at'], '2026-10-21T10:05:48+99:99'])]
    #[TestWith(['data отсутствует', ['data'], null])]
    #[TestWith(['data строка', ['data'], 'не объект'])]
    #[TestWith(['data массив', ['data'], [1, 2]])]
    public function test_envelope_violations_are_invalid_envelope(string $case, array $path, mixed $value): void
    {
        unset($case);

        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse($this->webhook($this->mutatedBody($path, $value))),
            PayloadIssue::InvalidEnvelope,
        );
    }

    /**
     * Шаг 9 §7/S-17: для известного типа data.order обязан быть объектом,
     * order.id и order.status — строками; нарушение — invalid_envelope
     * (422), а не «ignored»: не-строчный статус не является неизвестным
     * строковым значением (FR-WH-07).
     */
    #[TestWith(['order отсутствует', ['data', 'order'], null])]
    #[TestWith(['order не объект', ['data', 'order'], 'строка'])]
    #[TestWith(['order.id отсутствует', ['data', 'order', 'id'], null])]
    #[TestWith(['order.id не строка', ['data', 'order', 'id'], 7])]
    #[TestWith(['order.status null', ['data', 'order', 'status'], null])]
    #[TestWith(['order.status число', ['data', 'order', 'status'], 3])]
    public function test_known_type_structure_violations_are_invalid_envelope(string $case, array $path, mixed $value): void
    {
        unset($case);

        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse($this->webhook($this->mutatedBody($path, $value))),
            PayloadIssue::InvalidEnvelope,
        );
    }

    /**
     * Шаг 11 §7/FR-WH-08: полный снимок известного статуса — типы и
     * форматы всех обязательных полей; любое нарушение — invalid_snapshot
     * (422), событие не доходит до журнала (FR-ORD-08).
     */
    #[TestWith(['total один знак после точки', ['data', 'order', 'total'], '4909.5'])]
    #[TestWith(['total без дробной части', ['data', 'order', 'total'], '4909'])]
    #[TestWith(['total число', ['data', 'order', 'total'], 4909.5])]
    #[TestWith(['total отсутствует', ['data', 'order', 'total'], null])]
    #[TestWith(['quantity ноль', ['data', 'order', 'items', 0, 'quantity'], 0])]
    #[TestWith(['quantity отрицательное', ['data', 'order', 'items', 0, 'quantity'], -1])]
    #[TestWith(['quantity строка', ['data', 'order', 'items', 0, 'quantity'], '3'])]
    #[TestWith(['currency нижний регистр', ['data', 'order', 'currency'], 'rub'])]
    #[TestWith(['currency две буквы', ['data', 'order', 'currency'], 'RU'])]
    #[TestWith(['currency четыре буквы', ['data', 'order', 'currency'], 'RUBB'])]
    #[TestWith(['currency с цифрой', ['data', 'order', 'currency'], 'RU1'])]
    #[TestWith(['currency отсутствует', ['data', 'order', 'currency'], null])]
    #[TestWith(['price число', ['data', 'order', 'items', 0, 'price'], 1290])]
    #[TestWith(['price неверный формат', ['data', 'order', 'items', 0, 'price'], '-12.5'])]
    #[TestWith(['sku отсутствует', ['data', 'order', 'items', 0, 'sku'], null])]
    #[TestWith(['items не список', ['data', 'order', 'items'], ['sku' => 'X']])]
    #[TestWith(['позиция не объект', ['data', 'order', 'items'], ['не объект']])]
    #[TestWith(['delivery_price отсутствует', ['data', 'order', 'delivery_price'], null])]
    #[TestWith(['discount один знак', ['data', 'order', 'discount'], '2.5'])]
    #[TestWith(['customer отсутствует', ['data', 'order', 'customer'], null])]
    #[TestWith(['customer.name отсутствует', ['data', 'order', 'customer', 'name'], null])]
    #[TestWith(['customer.phone число', ['data', 'order', 'customer', 'phone'], 79001234567])]
    #[TestWith(['number отсутствует', ['data', 'order', 'number'], null])]
    #[TestWith(['shipment без tracking_url', ['data', 'order', 'shipment'], ['carrier' => 'СДЭК', 'tracking_number' => '1']])]
    #[TestWith(['shipment не объект и не null', ['data', 'order', 'shipment'], 'нет'])]
    #[TestWith(['paid_at без смещения', ['data', 'order', 'paid_at'], '2026-10-21T13:05:47'])]
    #[TestWith(['paid_at число', ['data', 'order', 'paid_at'], 1])]
    #[TestWith(['cancelled_at несуществующий день', ['data', 'order', 'cancelled_at'], '2026-02-30T10:00:00+00:00'])]
    #[TestWith(['cancel_reason число', ['data', 'order', 'cancel_reason'], 404])]
    #[TestWith(['updated_at отсутствует', ['data', 'order', 'updated_at'], null])]
    public function test_snapshot_violations_are_invalid_snapshot(string $case, array $path, mixed $value): void
    {
        unset($case);

        $this->assertMalformedPayload(
            fn(): MarketplaceEvent => $this->parser->parse($this->webhook($this->mutatedBody($path, $value))),
            PayloadIssue::InvalidSnapshot,
        );
    }

    /**
     * Допустимые края протокола: любая валюта из трёх заглавных букв
     * (не только RUB/USD/EUR), отрицательные суммы (формат допускает
     * минус), пустой items, phone=null, пустой cancel_reason, дробные
     * секунды и Z-смещение (FR-WH-08, ISO 8601).
     */
    public function test_snapshot_accepts_protocol_edges(): void
    {
        $body = json_encode([
            'id' => 'evt_edges',
            'type' => 'order.paid',
            'created_at' => '2026-10-21T10:05:48.123Z',
            'data' => [
                'order' => [
                    'id' => 'ord_edges',
                    'number' => 'MH-1',
                    'status' => 'paid',
                    'currency' => 'JPY',
                    'customer' => ['email' => 'a@b.c', 'name' => 'Имя', 'phone' => null],
                    'items' => [],
                    'delivery_price' => '10.00',
                    'discount' => '-5.00',
                    'total' => '15.00',
                    'shipment' => null,
                    'paid_at' => '2026-10-21T10:05:48Z',
                    'cancelled_at' => null,
                    'cancel_reason' => '',
                    'created_at' => '2026-10-21T09:00:00Z',
                    'updated_at' => '2026-10-21T10:05:48.500+02:00',
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        assert(is_string($body));

        $event = $this->parser->parse($this->webhook($body));

        $snapshot = $event->order;
        $this->assertNotNull($snapshot);
        $this->assertSame('JPY', $snapshot->currency);
        $this->assertSame('-5.00', $snapshot->discount);
        $this->assertSame([], $snapshot->items);
        $this->assertNull($snapshot->customer->phone);
        $this->assertSame('', $snapshot->cancelReason);
        $this->assertTrue(
            $snapshot->paidAt->equalTo(CarbonImmutable::parse('2026-10-21T10:05:48+00:00')),
            'Z — то же нулевое смещение, момент совпадает.',
        );
        $this->assertSame('+02:00', $snapshot->updatedAt->getTimezone()->getName());
    }

    /**
     * FR-WH-09/S-27: расхождение total с арифметикой позиций — не отказ:
     * снимок разбирается с дословным total, в лог уходит warning с
     * контекстом event/order и обеими суммами (A-17, NFR-06).
     */
    public function test_total_mismatch_is_accepted_with_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertStringContainsString('total', $message);

                return $context['reason'] === 'total_mismatch'
                    && $context['event_id'] === 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC'
                    && $context['order_id'] === 'ord_7Q2KX9M4'
                    && $context['expected_total'] === '4909.50'
                    && $context['declared_total'] === '9999.00';
            });

        $body = MutatedWebhookBody::fromExample('order-paid')
            ->withPath(['data', 'order', 'total'], '9999.00')
            ->body();

        $event = $this->parser->parse($this->webhook($body));

        $this->assertNotNull($event->order);
        $this->assertSame('9999.00', $event->order->total, 'total берётся из снимка дословно, не пересчитывается.');
    }

    /** Сходящаяся арифметика примеров не порождает warning (A-17). */
    public function test_consistent_total_does_not_warn(): void
    {
        Log::shouldReceive('warning')->never();

        foreach (['order-created', 'order-updated', 'order-paid', 'order-shipped', 'order-cancelled'] as $example) {
            $event = $this->parser->parse($this->webhookForExample($example));

            $this->assertNotNull($event->order, sprintf('Пример %s должен разбираться в снимок.', $example));
        }
    }

    /**
     * Мутант order-paid: value null означает «поле отсутствует» (удаление
     * ветки по пути), иначе — подмена значения.
     *
     * @param non-empty-list<non-empty-string|int> $path
     */
    private function mutatedBody(array $path, mixed $value): string
    {
        if ($value !== null) {
            return MutatedWebhookBody::fromExample('order-paid')->withPath($path, $value)->body();
        }

        $decoded = json_decode(MarketplaceExamples::raw('order-paid'), true);
        assert(is_array($decoded));

        $reference = &$decoded;

        foreach ($path as $index => $segment) {
            if ($index === array_key_last($path)) {
                unset($reference[$segment]);

                break;
            }

            if (! is_array($reference[$segment] ?? null)) {
                break;
            }

            /** @var array<int|string, mixed> $nested */
            $nested = &$reference[$segment];
            $reference = &$nested;
        }
        unset($reference);

        try {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Не удалось закодировать тело мутанта: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Контекст доставки для парсера: X-Webhook-Id из body.id (как в
     * send-event.php), если не задан явно.
     */
    private function webhook(string $rawBody, ?string $webhookId = null): IncomingWebhook
    {
        if ($webhookId === null) {
            $decoded = json_decode($rawBody, true);
            $webhookId = is_array($decoded) && is_string($decoded['id'] ?? null)
                ? $decoded['id']
                : 'evt_header';
        }

        return new IncomingWebhook(
            webhookId: $webhookId,
            signatureTimestamp: 1792577149,
            rawBody: $rawBody,
        );
    }

    private function webhookForExample(string $example): IncomingWebhook
    {
        return $this->webhook(MarketplaceExamples::raw($example));
    }

    /**
     * Ожидание MalformedPayload с конкретным PayloadIssue — дискриминатором
     * HTTP-кода (FR-WH-05/06/08): проверяется класс и issue, а не текст.
     *
     * @param Closure(): MarketplaceEvent $parse
     */
    private function assertMalformedPayload(Closure $parse, PayloadIssue $expected): void
    {
        try {
            $parse();
        } catch (MalformedPayload $exception) {
            $this->assertSame(
                $expected,
                $exception->issue,
                sprintf('Неверная причина отбраковки (%s): %s', $exception->issue->value, $exception->getMessage()),
            );

            return;
        }

        $this->fail(sprintf('Ожидался MalformedPayload(%s), разбор прошёл успешно.', $expected->value));
    }
}
