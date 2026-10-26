<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Проверка «побочных эффектов нет» для HTTP-тестов вебхука (T-017).
 *
 * Отказы до начала обработки (плохая подпись/timestamp/JSON/ID/конверт,
 * превышение лимита тела, занятая блокировка заказа) не должны оставлять
 * следов ни в одной доменной таблице: событие не журналируется
 * (FR-ORD-08), заказ и его производные не создаются, outbox пуст.
 *
 * Отдельный трейт, а не метод Tests\TestCase: проверка относится только
 * к сценариям вебхука и не должна быть видна остальным наборам.
 */
trait AssertsNoWebhookSideEffects
{
    /**
     * Все пять доменных таблиц пусты: журнал событий, заказы, позиции,
     * история статусов, outbox-уведомления. Вызывается в тестах, где
     * до отправки запроса БД пуста (без подготовленных данных).
     */
    protected function assertNoWebhookSideEffects(): void
    {
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_status_history', 0);
        $this->assertDatabaseCount('order_notifications', 0);
    }
}
