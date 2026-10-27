<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Data\HistoryFact;
use App\Data\IncomingWebhook;
use App\Data\MarketplaceEvent;
use App\Data\OrderSnapshot;
use App\Data\Version;
use App\Enums\EventOutcome;
use App\Enums\NotificationStatus;
use App\Enums\OrderStatus;
use App\Enums\WebhookProcessingResult;
use App\Exceptions\Webhook\WebhookUnavailable;
use App\Jobs\SendOrderNotification;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\OrderStatusHistory;
use App\Models\WebhookEvent;
use App\Support\HistoryBuilder;
use App\Support\MarketplaceEventParser;
use App\Support\NotificationDedupeKey;
use App\Support\NotificationTrigger;
use App\Support\SnapshotVersionComparator;
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
 * (каркас T-010, разбор тела — T-021, журнал и идемпотентность — T-026,
 * outbox-уведомления — T-032).
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
 * Применение снимка (FR-ORD-02/04/06, T-028): первый снимок создаёт
 * заказ из любого статуса; более свежая версия полностью перезаписывает
 * поля заказа и позиции (включая явные null); устаревшая версия —
 * 200 stale без изменения заказа. Даты MarketHub (placed_at/
 * source_updated_at) хранятся отдельно от системных меток Eloquent.
 *
 * Терминальные статусы (FR-ORD-09, допущение Q35, T-029): любой свежий
 * снимок с ДРУГИМ статусом при текущем delivered/cancelled отклоняется
 * (200 rejected, причина terminal_status в журнале, warning); снимок
 * с тем же терминальным статусом применяется как обычный.
 *
 * История статусов (FR-HIS-02/03, T-030): из КАЖДОГО валидного снимка
 * известного статуса — применённого и устаревшего — в ту же транзакцию
 * идемпотентно записываются достоверные факты (UNIQUE(order_id, status,
 * at)); применённый откат статуса добавляет факт коррекции «новый
 * статус @ updated_at». Отклонённый (rejected) снимок историю не
 * меняет. Детерминированный порядок выдачи — уровень вывода
 * (OrderResource, FR-HIS-04).
 *
 * Остались за владельцами: T-033 (тексты писем), T-034 (механика
 * отправки в SendOrderNotification), T-035 (sweeper/prune). Outbox
 * и dispatch после commit — здесь (шаги 13f/14).
 */
final class ProcessMarketplaceEvent
{
    /** Попытки транзакции применения при конкурентной ошибке БД (FR-ORD-07: attempts: 3). */
    private const int TRANSACTION_ATTEMPTS = 3;

    /** Причина отклонения свежего снимка из терминального статуса (webhook_events.reject_reason, FR-ORD-09/Q35). */
    private const string REJECT_REASON_TERMINAL_STATUS = 'terminal_status';

    public function __construct(
        private readonly MarketplaceEventParser $parser,
        private readonly SnapshotVersionComparator $versions,
        private readonly HistoryBuilder $history,
        private readonly NotificationTrigger $notifications,
        private readonly ConcurrencyErrorDetector $concurrencyErrors,
    ) {}

    /**
     * @throws \App\Exceptions\Webhook\MalformedPayload невалидное тело (400/422, T-021)
     * @throws \App\Exceptions\Webhook\WebhookUnavailable блокировка заказа не получена либо
     *         транзакция не завершилась за отведённые попытки (503, T-027)
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
     * | 13b | `Order::lockForUpdate()->find(order.id)` — FOR UPDATE в транзакции | реализовано (T-027) |
     * | 13c | версия снимка `(updated_at, event.created_at, event.id)` не новее → история += факты (FR-HIS-02), 200 stale | реализовано (T-028); факты истории — T-030 |
     * | 13d | текущий статус терминальный и новый другой → 200 rejected | реализовано (T-029) |
     * | 13e | полная перезапись заказа и позиций, версия, snapshot, факты истории и откат | реализовано (T-028/T-030) |
     * | 13f | outbox-уведомление при допустимой смене статуса | реализовано (T-032) |
     * | 13g | webhook_events.outcome=applied, processed_at; commit | реализовано (T-028) |
     * | 14 | dispatch уведомления после коммита → 200 processed | реализовано (T-032; механика job — T-034) |
     *
     * @throws \App\Exceptions\Webhook\WebhookUnavailable блокировка не получена за
     *         order_lock_timeout либо исчерпаны попытки транзакции (503)
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
                    // (FR-ORD-08), processed_at = NULL до финализации
                    // исхода (шаги 13c/13g — applySnapshotToOrder).
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

                    return $this->applySnapshotToOrder($event, $snapshot, $order, $webhook);
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
     *        применяемый снимок — null, финализация исхода и метки —
     *        шаги 13c/13g (finalizeJournalOutcome, T-028)
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
     * Шаги 13c–13g §7: сравнение версий, полная перезапись заказа
     * и позиций, финализация исхода журнала — внутри уже открытой
     * транзакции журнала под блокировкой заказа (см. applyUnderOrderLock /
     * runApplicationTransaction). Реализация T-028.
     *
     * 13c (FR-ORD-02): первый снимок (заказа нет) применяется всегда —
     * заказ создаётся из любого статуса (FR-ORD-06); для существующего
     * заказа применяется только строго большая версия кортежа
     * `(updated_at, event.created_at, event.id)` — равная и старая
     * не меняют заказ, исход журнала `stale` (FR-ORD-08). При этом
     * достоверные факты истории из устаревшего снимка принимаются
     * (FR-HIS-02): заказ не меняется, но известные факты дополняются —
     * потеря/опоздание события не оставляет «дыр» (S-06: created-факт
     * восстанавливается из stale order.created). Факта отката нет —
     * статус не меняется (решение T-030, см. DECISIONS.md).
     *
     * 13d (FR-ORD-09, Q35): при текущем терминальном статусе заказа
     * (`delivered`/`cancelled` — config marketplace.terminal_statuses)
     * свежий снимок с ДРУГИМ статусом отклоняется — включая переходы
     * между самими терминальными статусами (консервативное допущение
     * Q35): заказ/история/письма не меняются, исход журнала `rejected`
     * с причиной `terminal_status` и warning; тот же терминальный
     * статус проходит дальше в 13e — обновление данных/причины
     * допустимо (S-31). Проверка идёт ПОСЛЕ 13c: устаревший снимок при
     * терминальном заказе — `stale`, не `rejected` (порядок §7).
     *
     * 13e (FR-ORD-04/05): полная перезапись — все поля заказа из снимка,
     * включая явные null (shipment-тройка, paid_at, cancelled_at,
     * cancel_reason, phone); статус — из `order.status` снимка, а не из
     * `type` события; позиции заменяются целиком с сохранением порядка
     * (position 1..N). Версия и сырой снимок сохраняются на записи;
     * в историю пишутся факты снимка и, при откате статуса, факт
     * коррекции (FR-HIS-02/03).
     *
     * 13f (FR-MAIL-01/02, T-032): применённый снимок, меняющий статус
     * на paid/shipped/cancelled (правило триггера — NotificationTrigger,
     * без фильтра по event.type), создаёт outbox-запись `pending`
     * в ЭТОЙ же транзакции; дубль dedupe-ключа — молчаливый пропуск
     * (INSERT IGNORE-семантика FR-MAIL-02). 13g: исход журнала
     * финализируется (`applied` c вставки 13a + processed_at; для
     * stale — исход `stale`), commit — с транзакцией контура.
     *
     * @param Order|null $order строка заказа, заблокированная FOR UPDATE
     *        на шаге 13b; null — первый снимок (заказ будет создан)
     */
    private function applySnapshotToOrder(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        ?Order $order,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        if (
            $order !== null
            && ! $this->versions->isNewer(
                new Version($snapshot->updatedAt, $event->createdAt, $event->id),
                new Version($order->source_updated_at, $order->version_event_created_at, $order->version_event_id),
            )
        ) {
            // Шаг 13c §7 + FR-HIS-02: заказ не меняется, но достоверные
            // факты устаревшего снимка принимаются в историю (без
            // факта отката — статус не меняется). Заказ существует —
            // FK истории удовлетворён.
            $this->insertHistoryFacts($event, $snapshot, previousStatus: null);

            $this->finalizeJournalOutcome($event->id, EventOutcome::STALE);

            return WebhookProcessingResult::STALE;
        }

        // Шаг 13d §7 (FR-ORD-09, Q35): терминальный заказ закрыт для
        // переходов — свежий снимок с другим статусом отклоняется; тот
        // же терминальный статус (обновление данных/причины) проходит
        // в 13e. Проверка после 13c: версия раньше терминальности.
        if (
            $order !== null
            && $order->status->isTerminal()
            && $snapshot->status !== $order->status
        ) {
            Log::warning('Свежий снимок отклонён (terminal_status): переход из терминального статуса заказа запрещён (FR-ORD-09, допущение Q35).', [
                'reason' => self::REJECT_REASON_TERMINAL_STATUS,
                'event_id' => $event->id,
                'order_id' => $snapshot->id,
                'order_status' => $order->status->value,
                'incoming_status' => $snapshot->status->value,
            ]);

            $this->finalizeJournalOutcome(
                $event->id,
                EventOutcome::REJECTED,
                rejectReason: self::REJECT_REASON_TERMINAL_STATUS,
            );

            return WebhookProcessingResult::REJECTED;
        }

        // Шаг 13e §7: prev — статус до перезаписи (null для первого
        // снимка: заказа не было, откатать нечего, FR-ORD-06).
        $previousStatus = $order?->status;

        $order ??= new Order();
        $order->fill($this->orderAttributes($event, $snapshot, $this->rawOrderSnapshot($webhook)));
        $order->save();

        $this->replaceItems($order, $snapshot);

        // Запись заказа/позиций обязательна раньше фактов: FK истории
        // ссылается на orders.id (первый снимок — строка только что
        // создана).
        $this->insertHistoryFacts($event, $snapshot, $previousStatus);

        // Шаг 13f §7 (FR-MAIL-01/02): outbox-уведомление при допустимой
        // смене статуса — в той же транзакции, что и применение снимка.
        $this->createOutboxNotification($event, $snapshot, $previousStatus);

        $this->finalizeJournalOutcome($event->id, EventOutcome::APPLIED);

        return WebhookProcessingResult::PROCESSED;
    }

    /**
     * Создание outbox-уведомления (шаг 13f §7, FR-MAIL-01/02):
     * `pending`-запись в транзакции применения, если смена статуса
     * проходит правило триггера (NotificationTrigger — тип из нового
     * статуса, без фильтра по event.type, откат Q28 без
     * письма-исправления); иначе — no-op. Запись-дубль dedupe-ключа
     * молча пропускается (UNIQUE dedupe_key, «INSERT IGNORE: дубль →
     * письмо не создаётся» FR-MAIL-02) — уникальность уведомлений
     * держит БД, повторный shipped с тем же треком второго письма
     * не порождает (S-11).
     *
     * Перехват точного UniqueConstraintViolationException (а не
     * insertOrIgnore) — по образцу журнала (insertJournalRow): глотать
     * прочие ошибки строки (NOT NULL, усечение) нельзя; единственное
     * уникальное ограничение таблицы — dedupe_key (PK автоинкрементный),
     * поэтому нарушение ключа однозначно означает дубль.
     *
     * Ключ — трек-номер СОБЫТИЯ-триггера (FR-MAIL-02); сверка с треком
     * текущего снимка перед отправкой — задача T-034. recipient/subject
     * не заполняются: тело письма строится по текущему состоянию заказа
     * на момент отправки (§6.5), а не по снимку триггера.
     *
     * Dispatch (шаг 14 §7) регистрируется здесь же, внутри транзакции,
     * через DB::afterCommit: фактическая постановка происходит после
     * commit создавшей записи транзакции (в тестах под RefreshDatabase —
     * коммит транзакции приложения, в бою — корневой; менеджер
     * транзакций БД сам не исполнит колбэк на откат — задание не
     * увидит несуществующую запись). Постановка изолирована от ответа:
     * по §7 шаг 14 неудачная постановка (Redis недоступен) не валит
     * HTTP — запись остаётся pending, её переставит sweeper
     * (FR-MAIL-06, T-035); у задания дополнительно afterCommit = true
     * (конструктор SendOrderNotification) — защита от прямой
     * постановки внутри чужой транзакции.
     */
    private function createOutboxNotification(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        ?OrderStatus $previousStatus,
    ): void {
        $type = $this->notifications->typeFor($previousStatus, $snapshot->status);

        if ($type === null) {
            return;
        }

        try {
            $notification = OrderNotification::query()->create([
                'order_id' => $snapshot->id,
                'type' => $type,
                'dedupe_key' => NotificationDedupeKey::for(
                    $type,
                    $snapshot->id,
                    $snapshot->shipment?->trackingNumber,
                ),
                'status' => NotificationStatus::PENDING,
                'attempts' => 0,
                'source_event_id' => $event->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        DB::afterCommit(function () use ($notification): void {
            try {
                SendOrderNotification::dispatch($notification->id);
            } catch (\Throwable $exception) {
                Log::warning('Постановка уведомления в очередь после commit не удалась (dispatch_failed): sweeper переставит pending-запись (FR-MAIL-06).', [
                    'reason' => 'dispatch_failed',
                    'notification_id' => $notification->id,
                    'order_id' => $notification->order_id,
                    'source_event_id' => $notification->source_event_id,
                    'error' => $exception::class,
                    // Урезанное сообщение различает причины (connection
                    // refused / timeout / auth) — NFR-06 запрещает секрет,
                    // подпись и payload, но не диагностику транспорта
                    // (ревью T-032, P3-1).
                    'error_message' => mb_substr($exception->getMessage(), 0, 200),
                ]);
            }
        });
    }

    /**
     * Идемпотентная запись фактов истории (FR-HIS-02/03, шаги 13c/13e
     * §7): достоверные факты снимка + факт отката, если применённый
     * снимок откатывает статус (previousStatus ≠ null только в ветке
     * применения). Выполняется в транзакции вызывающей ветки под
     * блокировкой заказа; `at` нормализуется в UTC до вставки — биндинг
     * форматирует момент в таймзоне инстанса (та же ловушка, что и
     * даты заказа в T-028).
     *
     * Вставка — пакетный insertOrIgnore: UNIQUE(order_id, status, at)
     * делает уже известный факт no-op (пропускается только конфликтующая
     * строка, остальные строки пакета вставляются). Отличие от
     * try-catch-паттерна журнала (T-026) сознательное: для журнала
     * конфликт ключа означает ПЕРЕХОД на другую ветку (повторная
     * доставка), здесь же пропуск известных фактов — штатный поток
     * каждого снимка после первого, а FR-HIS-04 прямо санкционирует
     * INSERT IGNORE; значений, способных нарушить NOT NULL/усечение,
     * в строках нет (типизированные DTO). Обновлений существующих
     * фактов нет — история только пополняется.
     *
     * Пакет может содержать и одинаковые кортежи (status, at): откат
     * в статус, чей факт уже выведен из родного поля снимка (коррекция
     * shipped → paid с paid_at == updated_at). INSERT IGNORE пропускает
     * и внутрибатчевый дубликат — строка одна и удовлетворяет обоим
     * прочтениям (факт снимка / факт коррекции), потери нет (ревью
     * T-030, P3-2).
     *
     * @param OrderStatus|null $previousStatus статус заказа до
     *        применения (null — первый снимок или stale-ветка)
     */
    private function insertHistoryFacts(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        ?OrderStatus $previousStatus,
    ): void {
        $facts = $this->history->factsFromSnapshot($snapshot);

        $rollbackFact = $previousStatus === null
            ? null
            : $this->history->rollbackFact($previousStatus, $snapshot);

        if ($rollbackFact !== null) {
            $facts[] = $rollbackFact;
        }

        OrderStatusHistory::query()->insertOrIgnore(array_map(
            static fn(HistoryFact $fact): array => [
                'order_id' => $snapshot->id,
                'status' => $fact->status->value,
                'at' => $fact->at->utc(),
                'source_event_id' => $event->id,
            ],
            $facts,
        ));
    }

    /**
     * Финализация исхода события в журнале (шаги 13c/13d/13g §7): строка
     * вставлена на 13a с outcome=applied и processed_at=NULL (T-026);
     * здесь фиксируется фактический исход обработки, причина отказа
     * (reject_reason — для rejected, шаг 13d) и момент её завершения.
     * Дубль эти поля не перезаписывает (FR-ORD-01).
     */
    private function finalizeJournalOutcome(string $eventId, EventOutcome $outcome, ?string $rejectReason = null): void
    {
        WebhookEvent::query()
            ->whereKey($eventId)
            ->update([
                'outcome' => $outcome,
                'reject_reason' => $rejectReason,
                'processed_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * Атрибуты заказа из снимка (13e §7): полная перезапись всех полей —
     * MarketHub присылает снимок целиком, явные null сбрасывают прежние
     * значения (T6/FR-ORD-04). placed_at — created_at MarketHub (§6.3);
     * даты MarketHub нормализуются в UTC до записи (FR-ORD-03: в БД —
     * DATETIME в UTC, Eloquent сохраняет «настенное» время инстанса);
     * системные created_at/updated_at Eloquent ставит сам и они не
     * взаимозаменяемы с датами маркетплейса.
     *
     * @param array<int|string, mixed> $rawSnapshot сырой data.order из тела
     * @return array<string, mixed>
     */
    private function orderAttributes(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        array $rawSnapshot,
    ): array {
        return [
            'id' => $snapshot->id,
            'number' => $snapshot->number,
            'status' => $snapshot->status,
            'currency' => $snapshot->currency,
            'customer_email' => $snapshot->customer->email,
            'customer_email_normalized' => mb_strtolower(trim($snapshot->customer->email)),
            'customer_name' => $snapshot->customer->name,
            'customer_phone' => $snapshot->customer->phone,
            'delivery_price' => $snapshot->deliveryPrice,
            'discount' => $snapshot->discount,
            'total' => $snapshot->total,
            'shipment_carrier' => $snapshot->shipment?->carrier,
            'shipment_tracking_number' => $snapshot->shipment?->trackingNumber,
            'shipment_tracking_url' => $snapshot->shipment?->trackingUrl,
            'placed_at' => $snapshot->createdAt->utc(),
            'source_updated_at' => $snapshot->updatedAt->utc(),
            'paid_at' => $snapshot->paidAt?->utc(),
            'cancelled_at' => $snapshot->cancelledAt?->utc(),
            'cancel_reason' => $snapshot->cancelReason,
            'version_event_created_at' => $event->createdAt->utc(),
            'version_event_id' => $event->id,
            'snapshot' => $rawSnapshot,
        ];
    }

    /**
     * Сырой `data.order` из тела события: столбец `orders.snapshot` хранит
     * последний применённый снимок как прислал MarketHub — включая поля,
     * которых ещё нет в DTO/валидации («для отладки и совместимости при
     * новых полях», §6.3 ТЗ), которые проекция DTO потеряла бы.
     *
     * Недостижимые ветки защиты: парсер (шаги 5–9 §7, T-021) уже
     * гарантировал валидный JSON с объектом data.order для известного
     * типа — сюда попадают только события, прошедшие parse().
     *
     * @return array<int|string, mixed>
     */
    private function rawOrderSnapshot(IncomingWebhook $webhook): array
    {
        $decoded = json_decode($webhook->rawBody, true, flags: JSON_THROW_ON_ERROR);

        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $order = is_array($data) ? ($data['order'] ?? null) : null;

        if (! is_array($order)) {
            throw new \LogicException('Сырой data.order недоступен: парсер гарантировал его наличие для известного типа.');
        }

        return $order;
    }

    /**
     * Полная замена позиций заказа (FR-ORD-04): delete + insert в той же
     * транзакции под блокировкой строки — атомарно; порядок позиций
     * снимка сохраняется как `position` 1..N. Снимок всегда полный,
     * «частичное обновление» позиций протоколом не существует, поэтому
     * diff/upsert не нужен (решение T-028 — см. DECISIONS.md).
     */
    private function replaceItems(Order $order, OrderSnapshot $snapshot): void
    {
        $order->items()->delete();

        foreach ($snapshot->items as $index => $item) {
            $order->items()->create([
                'position' => $index + 1,
                'sku' => $item->sku,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'price' => $item->price,
            ]);
        }
    }
}
