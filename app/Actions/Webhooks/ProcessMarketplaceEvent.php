<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Data\IncomingWebhook;
use App\Data\MarketplaceEvent;
use App\Data\OrderSnapshot;
use App\Enums\EventOutcome;
use App\Enums\WebhookProcessingResult;
use App\Exceptions\Webhook\WebhookUnavailable;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Support\MarketplaceEventParser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

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
 * Конкурентность (FR-ORD-07, T-027): обработка известного события
 * выполняется под общей блокировкой заказа `order:{id}` (шаг 12 §7);
 * внутри транзакции строка заказа дополнительно блокируется
 * `SELECT … FOR UPDATE` (13b). Дубли и применение одного заказа
 * физически не пересекаются, а с отправкой письма (FR-MAIL-03, T-034)
 * вебхук синхронизируется тем же Redis-lock.
 *
 * Остались за владельцами: T-028 (применение снимка, шаги 13c–13e/13g),
 * T-029 (терминальные статусы, 13d), T-030 (история, 13e), T-032
 * (outbox, 13f). Правило этапа 2 сохраняется для нереализованных шагов:
 * валидное событие известного типа падает заглушкой применения ВНУТРИ
 * транзакции журнала — откат уносит и запись журнала, фиктивный
 * `200 processed` невозможен; невалидное тело завершается
 * MalformedPayload от парсера (→ 400/422, до журналирования).
 */
final class ProcessMarketplaceEvent
{
    /** Попытки транзакции применения при конкурентной ошибке БД (FR-ORD-07: attempts: 3). */
    private const int TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly MarketplaceEventParser $parser,
        private readonly ConcurrencyErrorDetector $concurrencyErrors,
    ) {}

    /**
     * @throws \App\Exceptions\Webhook\MalformedPayload невалидное тело (400/422, T-021)
     * @throws \App\Exceptions\Webhook\WebhookUnavailable блокировка заказа не получена либо
     *         транзакция не завершилась за отведённые попытки (503, T-027)
     * @throws \LogicException применение снимка не реализовано (T-028–T-032)
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
     * Применение события под блокировкой заказа (шаги 12–14 §7, T-027).
     *
     * Контур конкурентности (FR-ORD-07): общая для вебхуков и отправки
     * письма блокировка `order:{id}` берётся ДО транзакции с ограниченным
     * ожиданием; вся транзакция применения — вставка события в журнал
     * (13a), duplicate-ветка и применение снимка (13b–13g) — выполняется
     * под ней и освобождается в finally ВСЕГДА (включая исключения
     * применения и неудачу захвата — release без владения безопасен:
     * драйвер сверяет owner-токен). Откат применения уносит и запись
     * журнала (FR-ORD-01).
     *
     * Сетевые вызовы (SMTP/HTTP) внутри контура запрещены (NFR-01:
     * никаких сетевых вызовов в запросе вебхука; отправка письма —
     * outbox + очередь T-032/T-034 вне запроса) — транзакция приёма
     * не держит соединений дольше собственных БД-операций.
     *
     * | Шаг | Действие | Статус |
     * |---|---|---|
     * | 12 | `Cache::lock("order:{id}", lease)->block(timeout)`; не взят → 503 | реализовано (T-027) |
     * | 13a | идемпотентная вставка webhook_events(outcome=applied); дубль → 200 duplicate | реализовано (T-026) |
     * | 13b | `Order::lockForUpdate()->find(order.id)` — FOR UPDATE в транзакции | каркас (T-027), потребитель — T-028 |
     * | 13c | версия снимка `(updated_at, event.created_at, event.id)` не новее → 200 stale; сравнение готово (SnapshotVersionComparator, T-023) | T-028 |
     * | 13d | текущий статус терминальный и новый другой → 200 rejected | T-029 |
     * | 13e | полная перезапись заказа и позиций, версия и snapshot; факты истории | T-028/T-030 |
     * | 13f | outbox-уведомление при допустимой смене статуса | T-032 |
     * | 13g | webhook_events.outcome=applied, processed_at; commit | T-026 (вставка) + T-028 (финализация) |
     * | 14 | dispatch уведомления после коммита → 200 processed | T-032/T-034 |
     *
     * @throws \App\Exceptions\Webhook\WebhookUnavailable блокировка не получена за
     *         order_lock_timeout либо исчерпаны попытки транзакции (503)
     * @throws \LogicException применение снимка не реализовано (T-028–T-032)
     */
    private function applyUnderOrderLock(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        // Шаг 12 §7: Redis-блокировка в бою (CACHE_STORE=redis); семантика
        // едина для любого LockProvider-стора — тесты работают на array.
        // Lease (15 с) превышает SMTP timeout — lock не истечёт под
        // чужой длинной операцией (FR-ORD-07/FR-MAIL-03).
        $lock = Cache::lock(
            'order:' . $snapshot->id,
            (int) config('marketplace.order_lock_lease'),
        );

        try {
            try {
                // Ожидание ограничено order_lock_timeout: занятая
                // блокировка (параллельная доставка, отправка письма) —
                // повторяемый отказ 503, MarketHub повторит доставку.
                $lock->block((int) config('marketplace.order_lock_timeout'));
            } catch (LockTimeoutException $exception) {
                $this->logRepeatableRefusal('order_lock_timeout', $event, $snapshot->id);

                throw new WebhookUnavailable(previous: $exception);
            }

            return $this->runApplicationTransaction($event, $snapshot, $webhook);
        } finally {
            // §7 шаг 13: «lock освобождать в finally» — единственная точка
            // освобождения на все исходы контура.
            $lock->release();
        }
    }

    /**
     * Транзакция применения (шаг 13 §7): вставка журнала (13a),
     * duplicate-ветка и применение снимка (13b–13g) — одна транзакция
     * (FR-ORD-01); попытки при конкурентной ошибке БД (deadlock 1213 /
     * lock wait timeout 1205) — FR-ORD-07: Laravel откатывает попытку
     * и повторяет транзакцию целиком до трёх раз; после исчерпания
     * «безопасно завершить обработку в срок нельзя» → повторяемый 503
     * (не громкий 500): MarketHub обязан повторить доставку.
     *
     * @throws \App\Exceptions\Webhook\WebhookUnavailable исчерпаны попытки транзакции
     * @throws \LogicException применение снимка не реализовано (T-028–T-032)
     */
    private function runApplicationTransaction(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        try {
            return DB::connection()->transaction(
                function () use ($event, $snapshot, $webhook): WebhookProcessingResult {
                    // Шаг 13a §7: outcome=applied ставится на вставке
                    // (FR-ORD-08), processed_at финализируется на шаге
                    // 13g (T-028).
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

                    // Шаг 13b §7: строка заказа блокируется SELECT … FOR
                    // UPDATE до чтения/записи — вместе с Redis-lock это
                    // вторая линия защиты (FR-MAIL-04). Первый снимок
                    // (строки нет) → null: блокировать нечего, гонку
                    // создания исключает общая блокировка order:{id}.
                    $order = Order::query()->lockForUpdate()->find($snapshot->id);

                    return $this->applySnapshotToOrder($event, $snapshot, $order);
                },
                attempts: self::TRANSACTION_ATTEMPTS,
            );
        } catch (PDOException $exception) {
            // Сюда попадают все БД-исключения после исчерпания попыток
            // transaction(): QueryException (deadlock/lock wait на
            // statement top-level транзакции), DeadlockException
            // (вложенная транзакция — НЕ наследник QueryException,
            // сестринский класс под PDOException) и сырая PDOException
            // commit-фазы. Детектор — контейнерный singleton, тот же,
            // каким Laravel решает ретраи внутри transaction():
            // конвертируем только конкурентные ошибки, прочие ошибки
            // БД пробрасываются — громкий 500 без маскировки.
            if (! $this->concurrencyErrors->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            $this->logRepeatableRefusal('transaction_retry_exhausted', $event, $snapshot->id);

            throw new WebhookUnavailable(previous: $exception);
        }
    }

    /**
     * Warning о повторяемом отказе конкурентности (NFR-06): машинный
     * токен причины дублируется в тексте сообщения (лог грепается без
     * разбора контекста), формулировка причины — своя для каждого
     * токена: при разборе инцидента причина должна читаться из текста
     * однозначно. Контекст — только идентификаторы, без тела, секрета
     * и подписи.
     */
    private function logRepeatableRefusal(
        string $reason,
        MarketplaceEvent $event,
        string $orderId,
    ): void {
        $cause = match ($reason) {
            'order_lock_timeout' => 'блокировка заказа не получена за order_lock_timeout',
            'transaction_retry_exhausted' => 'исчерпаны попытки транзакции при конкурентной ошибке БД',
            default => throw new \LogicException(sprintf(
                'Отсутствует формулировка предупреждения для причины %s — добавьте её.',
                $reason,
            )),
        };

        Log::warning(sprintf('Обработка вебхука повторяемо отклонена (%s): %s — MarketHub обязан повторить доставку (503).', $reason, $cause), [
            'reason' => $reason,
            'event_id' => $event->id,
            'order_id' => $orderId,
        ]);
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
     * Шаги 13c–13g §7: сравнение версий, терминальные статусы, полная
     * перезапись заказа и позиций, факты истории и outbox-уведомление —
     * внутри уже открытой транзакции журнала под блокировкой заказа
     * (см. applyUnderOrderLock / runApplicationTransaction).
     *
     * @param Order|null $order строка заказа, заблокированная FOR UPDATE
     *        на шаге 13b; null — первый снимок (заказ будет создан, T-028)
     *
     * @throws \LogicException пока не реализовано (заглушка с T-027; применение — задачи T-028–T-032)
     */
    private function applySnapshotToOrder(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        ?Order $order,
    ): WebhookProcessingResult {
        throw new \LogicException('Применение снимка заказа ещё не реализовано (каркас T-027; применение — задачи T-028–T-032).');
    }
}
