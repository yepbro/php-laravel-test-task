<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статус заказа MarketHub.
 *
 * Строковые значения совпадают с колонками `orders.status` и
 * `order_status_history.status` (VARCHAR(16)) и со значениями API/снимков
 * вебхуков (§6.3, §6.6 ТЗ plan/TZ-markethub-orders-service-v1.4.md).
 */
enum OrderStatus: string
{
    /** Заказ оформлен. */
    case CREATED = 'created';

    /** Заказ оплачен. */
    case PAID = 'paid';

    /** Заказ передан в доставку. */
    case SHIPPED = 'shipped';

    /** Заказ доставлен (терминальный, Q35). */
    case DELIVERED = 'delivered';

    /** Заказ отменён (терминальный, Q35). */
    case CANCELLED = 'cancelled';

    /**
     * Ранг статуса для сортировки истории статусов (FR-HIS-03/FR-HIS-04, §6.4 ТЗ).
     *
     * ТЗ фиксирует ранги только для не-терминальных статусов:
     * `created = 0, paid = 1, shipped = 2`. Ранги `delivered` (3) и
     * `cancelled` (4) выбраны по смыслу сортировки истории:
     * прогресс `shipped → delivered` не должен считаться откатом,
     * а при равном моменте `at` порядок записей детерминирован:
     * created, paid, shipped, delivered, cancelled.
     *
     * ВАЖНО: ранг используется только в механике истории статусов —
     * сортировка при равном `at` (FR-HIS-04) и обнаружение отката
     * для записи факта в историю (FR-HIS-03) — и НЕ является
     * универсальным правилом допустимости переходов. Переходы
     * статусов определяются FR-ORD-05/FR-ORD-09 (терминальные
     * статусы — Q35) и реализуются в сервисах применения снимков,
     * а не в этом enum.
     */
    public function historyRank(): int
    {
        return match ($this) {
            self::CREATED => 0,
            self::PAID => 1,
            self::SHIPPED => 2,
            self::DELIVERED => 3,
            self::CANCELLED => 4,
        };
    }

    /**
     * Является ли статус терминальным (Q35/FR-ORD-09, §16.2 ТЗ).
     *
     * Из `delivered` и `cancelled` откаты запрещены, включая переходы
     * между самими терминальными статусами (`delivered → cancelled`
     * и обратно — консервативное допущение Q35); обновление данных при
     * том же терминальном статусе разрешено.
     *
     * Единственное место истины — `config('marketplace.terminal_statuses')`
     * (обязательство задачи T-004, закрыто в T-009): набор задаётся
     * конфигом, чтобы правило Q35 можно было менять без правки кода
     * (риск R-2 ТЗ). Список заполняется строковыми значениями case'ов
     * этого enum'а (см. config/marketplace.php).
     */
    public function isTerminal(): bool
    {
        /** @var list<string> $terminalStatuses */
        $terminalStatuses = config('marketplace.terminal_statuses', []);

        return in_array($this->value, $terminalStatuses, true);
    }
}
