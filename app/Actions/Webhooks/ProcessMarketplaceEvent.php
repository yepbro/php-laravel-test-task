<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Data\IncomingWebhook;
use App\Data\MarketplaceEvent;
use App\Data\OrderSnapshot;
use App\Enums\WebhookProcessingResult;
use App\Support\MarketplaceEventParser;

/**
 * Оркестрация обработки события вебхука — шаги 5–14 алгоритма §7 ТЗ
 * (каркас T-010, разбор тела — T-021).
 *
 * Единая точка последовательной обработки; HTTP-слой (контроллер) только
 * доставляет контекст доставки (IncomingWebhook) и преобразует результат
 * в ответ. Разбор/валидация тела (шаги 5–11 §7) — в MarketplaceEventParser
 * (T-021); транзакционная бизнес-логика реализуется задачами T-026
 * (журнал и идемпотентность), T-027 (блокировки), T-028 (применение
 * снимка), T-029 (терминальные статусы), T-030 (история), T-032
 * (outbox-уведомления).
 *
 * Правило этапа 2 сохраняется: до реализации транзакционных шагов
 * обработка события всегда завершается исключением — фиктивный
 * `200 processed` запрещён; невалидное тело завершается
 * MalformedPayload от парсера (→ 400/422, до журналирования).
 */
final class ProcessMarketplaceEvent
{
    public function __construct(
        private readonly MarketplaceEventParser $parser,
    ) {}

    /**
     * @throws \App\Exceptions\Webhook\MalformedPayload невалидное тело (400/422, T-021)
     * @throws \LogicException пока транзакционные шаги не реализованы (T-026…T-032)
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
     * Журналирование события неизвестного типа (шаг 8 §7): INSERT IGNORE
     * webhook_events(outcome=ignored, order_id=NULL); дубль → deliveries++
     * и 200 duplicate.
     *
     * @throws \LogicException пока не реализовано (задача T-026)
     */
    private function recordUnknownTypeEvent(
        MarketplaceEvent $event,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        throw new \LogicException('Журналирование событий неизвестного типа ещё не реализовано (задача T-026).');
    }

    /**
     * Журналирование события с неизвестным строковым статусом
     * (шаг 10 §7): INSERT IGNORE webhook_events(outcome=ignored,
     * order_id из конверта); дубль → 200 duplicate.
     *
     * @throws \LogicException пока не реализовано (задача T-026)
     */
    private function recordUnknownStatusEvent(
        MarketplaceEvent $event,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        throw new \LogicException('Журналирование событий неизвестного статуса ещё не реализовано (задача T-026).');
    }

    /**
     * Применение события под блокировкой заказа (шаги 12–14 §7):
     *
     * | Шаг | Действие | Задача |
     * |---|---|---|
     * | 12 | `Cache::lock("order:{id}", lease)->block(timeout)`; не взят → 503 | T-027 |
     * | 13a | идемпотентная вставка webhook_events; дубль → 200 duplicate | T-026 |
     * | 13b | `Order::lockForUpdate()->find(order.id)` | T-027 |
     * | 13c | версия снимка `(updated_at, event.created_at, event.id)` старше → 200 stale | T-023/T-028 |
     * | 13d | текущий статус терминальный и новый другой → 200 rejected | T-029 |
     * | 13e | полная перезапись заказа и позиций, версия и snapshot | T-028 |
     * | 13f | факты истории и outbox-уведомление при смене статуса | T-030/T-032 |
     * | 13g | webhook_events.outcome=applied; commit | T-026 |
     * | 14 | dispatch уведомления после коммита → 200 processed | T-032/T-034 |
     *
     * Всё — в одной БД-транзакции с retry при deadlock (FR-ORD-01/07).
     *
     * @throws \LogicException пока не реализовано (задачи T-027–T-032)
     */
    private function applyUnderOrderLock(
        MarketplaceEvent $event,
        OrderSnapshot $snapshot,
        IncomingWebhook $webhook,
    ): WebhookProcessingResult {
        throw new \LogicException('Применение события под блокировкой заказа ещё не реализовано (задачи T-027–T-032).');
    }
}
