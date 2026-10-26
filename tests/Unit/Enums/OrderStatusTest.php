<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\OrderStatus;
use Tests\TestCase;
use ValueError;

class OrderStatusTest extends TestCase
{
    /**
     * Полный состав и порядок case'ов: значения фиксируются контрактом
     * колонок orders.status / order_status_history.status (§6.3 ТЗ) и API.
     */
    public function test_cases_match_database_and_api_values(): void
    {
        $this->assertSame(
            ['created', 'paid', 'shipped', 'delivered', 'cancelled'],
            array_map(static fn(OrderStatus $status): string => $status->value, OrderStatus::cases()),
        );
    }

    public function test_is_backed_by_string(): void
    {
        $this->assertSame('string', (new \ReflectionEnum(OrderStatus::class))->getBackingType()?->getName());
    }

    /**
     * Максимальное значение (cancelled, 9 символов) помещается в VARCHAR(16)
     * колонки orders.status / order_status_history.status.
     */
    public function test_values_fit_into_status_column(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertLessThanOrEqual(16, mb_strlen($status->value));
        }
    }

    public function test_from_maps_known_values_to_cases(): void
    {
        $this->assertSame(OrderStatus::CREATED, OrderStatus::from('created'));
        $this->assertSame(OrderStatus::PAID, OrderStatus::from('paid'));
        $this->assertSame(OrderStatus::SHIPPED, OrderStatus::from('shipped'));
        $this->assertSame(OrderStatus::DELIVERED, OrderStatus::from('delivered'));
        $this->assertSame(OrderStatus::CANCELLED, OrderStatus::from('cancelled'));
    }

    /**
     * Несуществующее значение не маскируется приведением типа:
     * from() обязан бросить ValueError (неизвестный статус обрабатывается
     * вызывающим кодом как ignored по FR-WH-07, а не молча конвертируется).
     */
    public function test_from_unknown_value_throws_value_error(): void
    {
        $this->expectException(ValueError::class);

        OrderStatus::from('refunded');
    }

    public function test_try_from_unknown_value_returns_null(): void
    {
        $this->assertNull(OrderStatus::tryFrom('refunded'));
        $this->assertNull(OrderStatus::tryFrom(''));
        $this->assertNull(OrderStatus::tryFrom('CREATED'));
    }

    /**
     * Ранги для сортировки истории: ТЗ фиксирует created=0, paid=1,
     * shipped=2 (FR-HIS-03); delivered=3 и cancelled=4 — терминальные,
     * выбраны по смыслу сортировки истории (см. PHPDoc OrderStatus::historyRank()).
     */
    public function test_history_ranks_follow_history_sorting_contract(): void
    {
        $this->assertSame(0, OrderStatus::CREATED->historyRank());
        $this->assertSame(1, OrderStatus::PAID->historyRank());
        $this->assertSame(2, OrderStatus::SHIPPED->historyRank());
        $this->assertSame(3, OrderStatus::DELIVERED->historyRank());
        $this->assertSame(4, OrderStatus::CANCELLED->historyRank());
    }

    /** Прогресс shipped → delivered не считается откатом (ранг не убывает). */
    public function test_history_ranks_do_not_decrease_along_normal_flow(): void
    {
        $this->assertGreaterThan(
            OrderStatus::SHIPPED->historyRank(),
            OrderStatus::DELIVERED->historyRank(),
            'shipped → delivered — прогресс, а не откат (FR-HIS-03)',
        );
    }

    /** Ранги уникальны: детерминированная сортировка при равном `at` (FR-HIS-04). */
    public function test_history_ranks_are_unique(): void
    {
        $ranks = array_map(
            static fn(OrderStatus $status): int => $status->historyRank(),
            OrderStatus::cases(),
        );

        $this->assertSame($ranks, array_values(array_unique($ranks)));
    }

    /**
     * Терминальные статусы по Q35/FR-ORD-09 — только delivered и cancelled
     * (при конфиге по умолчанию из config/marketplace.php).
     */
    public function test_terminal_statuses_are_delivered_and_cancelled(): void
    {
        $terminal = [];
        foreach (OrderStatus::cases() as $status) {
            if ($status->isTerminal()) {
                $terminal[] = $status;
            }
        }

        $this->assertSame([OrderStatus::DELIVERED, OrderStatus::CANCELLED], $terminal);
    }

    /**
     * Единое место истины (обязательство T-004, закрытое в T-009):
     * isTerminal() читает config('marketplace.terminal_statuses'),
     * а не дублирует набор case'ов — смена конфига меняет поведение
     * без правки enum'а (риск R-2 ТЗ).
     */
    public function test_is_terminal_follows_marketplace_config(): void
    {
        $original = config('marketplace.terminal_statuses');

        try {
            config(['marketplace.terminal_statuses' => ['cancelled']]);

            $this->assertFalse(OrderStatus::DELIVERED->isTerminal());
            $this->assertTrue(OrderStatus::CANCELLED->isTerminal());

            config(['marketplace.terminal_statuses' => []]);

            $this->assertFalse(OrderStatus::DELIVERED->isTerminal());
            $this->assertFalse(OrderStatus::CANCELLED->isTerminal());
        } finally {
            config(['marketplace.terminal_statuses' => $original]);
        }
    }

    /**
     * Значения конфига — строковые case-значения enum'а: посторонние
     * строки не опознаются как статусы (in_array со строгим сравнением).
     */
    public function test_is_terminal_ignores_unknown_config_values(): void
    {
        $original = config('marketplace.terminal_statuses');

        try {
            config(['marketplace.terminal_statuses' => ['refunded', 'CANCELLED']]);

            $this->assertFalse(OrderStatus::CANCELLED->isTerminal());
        } finally {
            config(['marketplace.terminal_statuses' => $original]);
        }
    }
}
