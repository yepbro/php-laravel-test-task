<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Data\IncomingWebhook;
use App\Data\MarketplaceEvent;
use App\Data\OrderSnapshot;
use App\Enums\EventOutcome;
use App\Enums\WebhookProcessingResult;
use App\Models\WebhookEvent;
use App\Support\MarketplaceEventParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Оркестрация обработки события вебхука — шаги 5–14 алгоритма §7 ТЗ
 * (каркас T-010, разбор тела — T-021, журнал и идемпотентность — T-026).
 *
 * Единая точка последовательной обработки; HTTP-слой (контроллер) только
 * доставляет контекст доставки (IncomingWebhook) и преобразует результат
 * в ответ. Разбор/валидация тела (шаги 5–11 §7) — в MarketplaceEventParser
 * (T-021); журналирование и идемпотентность по event.id (FR-ORD-01/08:
 * вставка события, повторная доставка, конфликт содержимого) — здесь.
 *
 * Идемпотентность — вставка записи журнала с PK = event.id: конфликт
 * ключа означает повторную доставку (в т.ч. гонку параллельных доставок)
 * → deliveries++, last_received_at, при несовпадении payload hash —
 * warning без содержимого тела, 200 duplicate; заказ не применяется,
 * исходные payload/outcome не заменяются.
 *
 * Остались за владельцами: T-027 (блокировка заказа вокруг транзакции
 * применения, шаг 12 §7), T-028 (применение снимка, шаги 13b–13e/13g),
 * T-029 (терминальные статусы, 13d), T-030 (история, 13e), T-032
 * (outbox, 13f). Правило этапа 2 сохраняется для нереализованных шагов:
 * валидное событие известного типа падает заглушкой применения ВНУТРИ
 * транзакции журнала — откат уносит и запись журнала, фиктивный
 * `200 processed` невозможен; невалидное тело завершается
 * MalformedPayload от парсера (→ 400/422, до журналирования).
 */
final class ProcessMarketplaceEvent
{
    public function __construct(
        private readonly MarketplaceEventParser $parser,
    ) {}

    /**
     * @throws \App\Exceptions\Webhook\MalformedPayload невалидное тело (400/422, T-021)
     * @throws \LogicException применение снимка не реализовано (T-027–T-032)
     */
    public function handle(IncomingWebhook $webhook): WebhookProcessingResult
    {
        // Шаги 5–11 §7: разбор сырого тела (JSON/UTF-8, совпадение
        // X-Webhook-Id, базовый конверт, структура и полный снимок для
        // известного типа) — единая точка протокола в парсере.
        $event = $this->parser->parse($webhook);

        // Шаг 8 §7: неизвестный тип — событие журналируется как ignored
        // (повтор → duplicate) без снимка; заказ, история и письма
        // не меняются.
        if (! $this->parser->isKnownType($event->type)) {
            return $this->recordUnknownTypeEvent($event, $webhook);
        }

        // Шаг 10 §7: неизвестный строковый статус у известного типа —
        // событие журналируется как ignored с order_id из конверта
        // (повтор → duplicate); заказ, история и письма не меняются.
        // Парсер гарантирует: для известного типа order === null только
        // в этой ветке (не-строчный статус отсеян как 422 ранее).
        if ($event->order === null) {
            return $this->recordUnknownStatusEvent($event, $webhook);
        }

        // Шаги 12–14 §7: блокировка заказа и транзакция применения.
        return $this->applyUnderOrderLock($event, $event->order, $webhook);
    }

    /**
     * Журналирование события неизвестного типа (шаг 8 §7): в одной
     * транзакции идемпотентная вставка с исходом ignored и
     * order_id = NULL (`data.order` для неизвестного типа не читается);
     * повторная доставка — общая duplicate-ветка. Заказ, история и
     * письма не меняются; warning фиксирует расширение протокола
     * (NFR-06, решение задачи 017 ТЗ-плана).
     */
    private function recordUnknownTypeEvent(
        MarketplaceEvent $event,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        return DB::connection()->transaction(function () use ($event, $webhook): WebhookProcessingResult {
            $inserted = $this->insertJournalRow(
                $event,
                $webhook,
                EventOutcome::IGNORED,
                orderId: null,
                processedAt: CarbonImmutable::now(),
            );

            if (! $inserted) {
                return $this->registerDuplicateDelivery($event, $webhook);
            }

            Log::warning('Событие неизвестного типа (unknown_type) сохранено с исходом ignored (FR-WH-07).', [
                'reason' => 'unknown_type',
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return WebhookProcessingResult::IGNORED;
        });
    }

    /**
     * Журналирование события с неизвестным строковым статусом
     * (шаг 10 §7): в одной транзакции идемпотентная вставка с исходом
     * ignored и order_id из конверта; повторная доставка — общая
     * duplicate-ветка. Заказ, история и письма не меняются; значение
     * статуса доступно из сохранённого payload (NFR-06).
     */
    private function recordUnknownStatusEvent(
        MarketplaceEvent $event,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        return DB::connection()->transaction(function () use ($event, $webhook): WebhookProcessingResult {
            $inserted = $this->insertJournalRow(
                $event,
                $webhook,
                EventOutcome::IGNORED,
                orderId: $event->orderId,
                processedAt: CarbonImmutable::now(),
            );

            if (! $inserted) {
                return $this->registerDuplicateDelivery($event, $webhook);
            }

            Log::warning('Неизвестный статус события (unknown_status): событие сохранено с исходом ignored (FR-WH-07).', [
                'reason' => 'unknown_status',
                'event_id' => $event->id,
                'order_id' => $event->orderId,
            ]);

            return WebhookProcessingResult::IGNORED;
        });
    }

    /**
     * Применение события под блокировкой заказа (шаги 12–14 §7).
     *
     * Контур транзакции (FR-ORD-01): вставка события в журнал (13a) и
     * применение снимка (13b–13g) — ОДНА транзакция; откат применения
     * уносит и запись журнала. Повторная доставка того же event.id до
     * применения не доходит: вставка не состоялась → duplicate-ветка
     * (deliveries++, last_received_at, warning при несовпадении
     * payload hash) → 200 duplicate; исходные payload/outcome и заказ
     * не меняются (FR-ORD-01/08).
     *
     * | Шаг | Действие | Задача |
     * |---|---|---|
     * | 12 | `Cache::lock("order:{id}", lease)->block(timeout)`; не взят → 503 | T-027: захват добавляется ВОКРУГ вызова транзакции (до неё, освобождение в finally) — структура метода не меняется |
     * | 13a | идемпотентная вставка webhook_events(outcome=applied); дубль → 200 duplicate | T-026 (реализовано ниже) |
     * | 13b | `Order::lockForUpdate()->find(order.id)` | T-027 |
     * | 13c | версия снимка `(updated_at, event.created_at, event.id)` не новее → 200 stale; сравнение готово (SnapshotVersionComparator, T-023) | T-028 |
     * | 13d | текущий статус терминальный и новый другой → 200 rejected | T-029 |
     * | 13e | полная перезапись заказа и позиций, версия и snapshot; факты истории | T-028/T-030 |
     * | 13f | outbox-уведомление при допустимой смене статуса | T-032 |
     * | 13g | webhook_events.outcome=applied, processed_at; commit | T-026 (вставка) + T-028 (финализация) |
     * | 14 | dispatch уведомления после коммита → 200 processed | T-032/T-034 |
     *
     * @throws \LogicException применение снимка не реализовано (T-027–T-032)
     */
    private function applyUnderOrderLock(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        return DB::connection()->transaction(function () use ($event, $snapshot, $webhook): WebhookProcessingResult {
            // Шаг 13a §7: outcome=applied ставится на вставке (FR-ORD-08),
            // processed_at финализируется на шаге 13g (T-028).
            $inserted = $this->insertJournalRow(
                $event,
                $webhook,
                EventOutcome::APPLIED,
                orderId: $event->orderId,
                processedAt: null,
            );

            if (! $inserted) {
                return $this->registerDuplicateDelivery($event, $webhook);
            }

            return $this->applySnapshotToOrder($event, $snapshot);
        });
    }

    /**
     * Идемпотентная вставка события в журнал (INSERT IGNORE шагов 8/10/13a
     * §7, FR-ORD-01/08): PK = event.id, сырое тело байт в байт, хеш тела,
     * timestamp подписи, deliveries=1, время приёма.
     *
     * Литеральный `insertOrIgnore()` (INSERT IGNORE MySQL) глотает и
     * прочие ошибки строки (NOT NULL, усечение), а не только конфликт
     * ключа; вместо него — точный перехват UniqueConstraintViolationException:
     * единственное уникальное ограничение таблицы — PK, семантика
     * «вставка не состоялась только для дубля» сохранена, иные ошибки
     * не маскируются.
     *
     * @param CarbonImmutable|null $processedAt момент завершения обработки:
     *        конечный на вставке исход (ignored) — текущее время;
     *        применяемый снимок — null, финализирует шаг 13g (T-028)
     */
    private function insertJournalRow(
        MarketplaceEvent $event,
        IncomingWebhook $webhook,
        EventOutcome $outcome,
        ?string $orderId,
        ?CarbonImmutable $processedAt,
    ): bool {
        try {
            WebhookEvent::query()->create([
                'id' => $event->id,
                'type' => $event->type,
                'order_id' => $orderId,
                'signature_timestamp' => $webhook->signatureTimestamp,
                'payload' => $webhook->rawBody,
                'payload_sha256' => hash('sha256', $webhook->rawBody),
                'outcome' => $outcome,
                'deliveries' => 1,
                'received_at' => CarbonImmutable::now(),
                'processed_at' => $processedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Повторная доставка уже журналированного события (FR-ORD-01): не
     * применять снимок повторно; deliveries++ и last_received_at — одним
     * атомарным UPDATE (без read-modify-write гонки параллельных
     * доставок); при несовпадении payload hash — warning без содержимого
     * тела (NFR-04/06: только идентификаторы и хеши). Исходные payload/
     * payload_sha256/outcome/order_id/signature_timestamp/received_at
     * не заменяются — `duplicate` только HTTP-ответ (FR-ORD-08).
     *
     * Выполняется в транзакции вызывающей ветки (шаги 8/10/13a §7);
     * строка существует — вставка только что на ней и споткнулась.
     */
    private function registerDuplicateDelivery(
        MarketplaceEvent $event,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        $stored = WebhookEvent::query()->findOrFail($event->id);

        $incomingHash = hash('sha256', $webhook->rawBody);

        if ($stored->payload_sha256 !== $incomingHash) {
            Log::warning('Повтор события с изменённым телом (duplicate_payload_mismatch): сохранён исходный payload.', [
                'reason' => 'duplicate_payload_mismatch',
                'event_id' => $event->id,
                'order_id' => $stored->order_id,
                'stored_payload_sha256' => $stored->payload_sha256,
                'incoming_payload_sha256' => $incomingHash,
            ]);
        }

        WebhookEvent::query()
            ->whereKey($event->id)
            ->increment('deliveries', extra: ['last_received_at' => CarbonImmutable::now()]);

        return WebhookProcessingResult::DUPLICATE;
    }

    /**
     * Шаги 13b–13g §7: блокировка строки заказа, сравнение версий,
     * терминальные статусы, полная перезапись заказа и позиций, факты
     * истории и outbox-уведомление — внутри уже открытой транзакции
     * журнала (см. applyUnderOrderLock).
     *
     * @throws \LogicException пока не реализовано (задачи T-027–T-032)
     */
    private function applySnapshotToOrder(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
    ): WebhookProcessingResult {
        throw new \LogicException('Применение снимка заказа ещё не реализовано (задачи T-027–T-032).');
    }
}
