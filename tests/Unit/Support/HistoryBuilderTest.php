<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Data\HistoryFact;
use App\Data\OrderCustomer;
use App\Data\OrderItemSnapshot;
use App\Data\OrderSnapshot;
use App\Enums\OrderStatus;
use App\Support\HistoryBuilder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Построение фактов истории статусов из снимка (T-016; красное до T-030).
 *
 * FR-HIS-01: момент перехода — «родное» поле снимка (created →
 * created_at, paid → paid_at, cancelled → cancelled_at), shipped/
 * delivered → updated_at; null у родного поля → updated_at.
 * FR-HIS-02: из КАЖДОГО валидного снимка (включая устаревший)
 * выводятся все достоверно следующие факты — потеря order.paid не
 * оставляет «дыр» (paid восстановится по paid_at более позднего
 * снимка, S-07). FR-HIS-03: применённый снимок-откат добавляет факт
 * «новый статус @ updated_at» — момент ручного исправления; прогресс
 * и тот же статус факта отката не дают. Идемпотентность вставки в БД
 * (FR-HIS-04, UNIQUE) — уровень потребителя; здесь — детерминизм
 * чистой функции.
 *
 * Порядок фактов в массиве не контрактен (сортировка истории —
 * FR-HIS-04 на уровне вывода), поэтому наборы сравниваются
 * без учёта порядка; детерминизм повторного вызова — отдельно.
 * Пока HistoryBuilder — заглушка T-030, все тесты падают с её
 * LogicException; после реализации становятся зелёными без правки
 * тестов. Моменты — реальные данные task/examples (FR-HIS-05).
 */
class HistoryBuilderTest extends TestCase
{
    /** «Родные» моменты ord_7Q2KX9M4 из примеров (записи как в файлах). */
    private const string ORDER_CREATED_AT = '2026-10-21T13:02:11+03:00';

    private const string ORDER_PAID_AT = '2026-10-21T13:05:47+03:00';

    private const string SHIPPED_UPDATED_AT = '2026-10-22T06:40:03+00:00';

    /** Моменты заказа ord_3HV8PW2C из order-cancelled.json. */
    private const string CANCELLED_ORDER_CREATED_AT = '2026-10-21T18:09:40+03:00';

    private const string CANCELLED_ORDER_PAID_AT = '2026-10-21T18:11:02+03:00';

    private const string CANCELLED_AT = '2026-10-21T20:47:15+03:00';

    private HistoryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HistoryBuilder();
    }

    /**
     * FR-HIS-02: снимок в статусе created даёт единственный факт
     * created @ created_at (родное поле, не время получения вебхука).
     */
    public function test_created_snapshot_yields_created_fact_at_native_moment(): void
    {
        $snapshot = self::snapshot(OrderStatus::CREATED);

        $this->assertFacts(['created@2026-10-21T10:02:11+00:00'], $this->builder->factsFromSnapshot($snapshot));
    }

    /**
     * FR-HIS-01/02: paid-снимок с заполненным paid_at даёт created
     * (снимок полный) и paid @ paid_at — именно родное поле.
     */
    public function test_paid_snapshot_yields_created_and_paid_at_paid_at(): void
    {
        $snapshot = self::snapshot(OrderStatus::PAID, paidAt: CarbonImmutable::parse(self::ORDER_PAID_AT));

        $this->assertFacts(
            ['created@2026-10-21T10:02:11+00:00', 'paid@2026-10-21T10:05:47+00:00'],
            $this->builder->factsFromSnapshot($snapshot),
        );
    }

    /**
     * FR-HIS-01: если «родное» поле null, момент перехода — updated_at
     * снимка (fallback), а не пропуск факта.
     */
    public function test_paid_snapshot_without_paid_at_falls_back_to_updated_at(): void
    {
        $snapshot = self::snapshot(OrderStatus::PAID, updatedAt: CarbonImmutable::parse(self::ORDER_PAID_AT));

        $this->assertFacts(
            ['created@2026-10-21T10:02:11+00:00', 'paid@2026-10-21T10:05:47+00:00'],
            $this->builder->factsFromSnapshot($snapshot),
        );
    }

    /**
     * FR-HIS-02: отменённый оплаченный заказ (ord_3HV8PW2C,
     * order-cancelled.json) даёт полный след: created, paid, cancelled —
     * каждый по своему родному моменту.
     */
    public function test_cancelled_paid_snapshot_yields_full_trace(): void
    {
        $snapshot = self::snapshot(
            OrderStatus::CANCELLED,
            paidAt: CarbonImmutable::parse(self::CANCELLED_ORDER_PAID_AT),
            cancelledAt: CarbonImmutable::parse(self::CANCELLED_AT),
            createdAt: CarbonImmutable::parse(self::CANCELLED_ORDER_CREATED_AT),
            updatedAt: CarbonImmutable::parse(self::CANCELLED_AT),
        );

        $this->assertFacts(
            [
                'created@2026-10-21T15:09:40+00:00',
                'paid@2026-10-21T15:11:02+00:00',
                'cancelled@2026-10-21T17:47:15+00:00',
            ],
            $this->builder->factsFromSnapshot($snapshot),
        );
    }

    /** FR-HIS-01: cancelled без cancelled_at — момент отмены из updated_at. */
    public function test_cancelled_snapshot_without_cancelled_at_falls_back_to_updated_at(): void
    {
        $snapshot = self::snapshot(
            OrderStatus::CANCELLED,
            updatedAt: CarbonImmutable::parse(self::CANCELLED_AT),
            createdAt: CarbonImmutable::parse(self::CANCELLED_ORDER_CREATED_AT),
        );

        $this->assertFacts(
            ['created@2026-10-21T15:09:40+00:00', 'cancelled@2026-10-21T17:47:15+00:00'],
            $this->builder->factsFromSnapshot($snapshot),
        );
    }

    /**
     * S-07 / FR-HIS-02: order.paid потерян, первым пришёл order.shipped —
     * paid восстанавливается из paid_at снимка shipped, сам shipped
     * датируется updated_at (отдельного поля нет, T11).
     */
    public function test_shipped_snapshot_restores_missed_paid_fact(): void
    {
        $snapshot = self::snapshot(
            OrderStatus::SHIPPED,
            paidAt: CarbonImmutable::parse(self::ORDER_PAID_AT),
            updatedAt: CarbonImmutable::parse(self::SHIPPED_UPDATED_AT),
        );

        $this->assertFacts(
            [
                'created@2026-10-21T10:02:11+00:00',
                'paid@2026-10-21T10:05:47+00:00',
                'shipped@2026-10-22T06:40:03+00:00',
            ],
            $this->builder->factsFromSnapshot($snapshot),
        );
    }

    /**
     * FR-HIS-02: delivered датируется updated_at снимка; paid_at, если
     * заполнен, всё равно достраивает пропущенный paid.
     */
    public function test_delivered_snapshot_yields_delivered_at_updated_at(): void
    {
        $snapshot = self::snapshot(
            OrderStatus::DELIVERED,
            paidAt: CarbonImmutable::parse(self::ORDER_PAID_AT),
            updatedAt: CarbonImmutable::parse(self::SHIPPED_UPDATED_AT),
        );

        $this->assertFacts(
            [
                'created@2026-10-21T10:02:11+00:00',
                'paid@2026-10-21T10:05:47+00:00',
                'delivered@2026-10-22T06:40:03+00:00',
            ],
            $this->builder->factsFromSnapshot($snapshot),
        );
    }

    /**
     * FR-HIS-05: пример истории из OpenAPI (created 13:02:11+03:00,
     * paid 13:05:47+03:00, shipped 2026-10-22T06:40:03+00:00)
     * воспроизводится на примерах task/examples — моменты совпадают
     * как моменты UTC независимо от записи смещения.
     *
     * Дедупликацию объединения выполняет сам тест — как это делал бы
     * потребитель при вставке (FR-HIS-04, UNIQUE (order_id, status, at)):
     * каждый снимок по FR-HIS-02 даёт ВСЕ следующие из него факты,
     * поэтому объединение содержит повторы (created×3, paid×2) —
     * история заказa — множество уникальных фактов.
     */
    public function test_openapi_history_example_is_reproduced_from_examples(): void
    {
        $created = $this->builder->factsFromSnapshot(self::snapshot(OrderStatus::CREATED));
        $paid = $this->builder->factsFromSnapshot(self::snapshot(
            OrderStatus::PAID,
            paidAt: CarbonImmutable::parse(self::ORDER_PAID_AT),
        ));
        $shipped = $this->builder->factsFromSnapshot(self::snapshot(
            OrderStatus::SHIPPED,
            paidAt: CarbonImmutable::parse(self::ORDER_PAID_AT),
            updatedAt: CarbonImmutable::parse(self::SHIPPED_UPDATED_AT),
        ));

        $union = array_values(array_unique(self::factKeys(array_merge($created, $paid, $shipped)), SORT_STRING));
        sort($union);

        $this->assertSame(
            [
                'created@2026-10-21T10:02:11+00:00',
                'paid@2026-10-21T10:05:47+00:00',
                'shipped@2026-10-22T06:40:03+00:00',
            ],
            $union,
        );
    }

    /**
     * Unit-гарантия идемпотентности: повторный вызов с тем же снимком
     * даёт тот же набор фактов в том же порядке (чистая функция;
     * идемпотентность вставки в БД обеспечивает UNIQUE(order_id, status,
     * at) — FR-HIS-04, уровень потребителя T-030).
     */
    public function test_facts_from_same_snapshot_are_deterministic(): void
    {
        $snapshot = self::snapshot(
            OrderStatus::SHIPPED,
            paidAt: CarbonImmutable::parse(self::ORDER_PAID_AT),
            updatedAt: CarbonImmutable::parse(self::SHIPPED_UPDATED_AT),
        );

        $first = self::factKeys($this->builder->factsFromSnapshot($snapshot));
        $second = self::factKeys($this->builder->factsFromSnapshot($snapshot));

        $this->assertSame($first, $second);
    }

    /**
     * FR-HIS-03: применённый снимок-откат добавляет факт «новый статус
     * @ updated_at» — момент ручного исправления (shipped отмечен по
     * ошибке, возврат в paid; Q28: состояние меняется, письмо-исправление
     * не отправляется).
     */
    #[DataProvider('rollbackProvider')]
    public function test_rollback_fact_records_new_status_at_snapshot_updated_at(
        OrderStatus $previousStatus,
        OrderStatus $incomingStatus,
    ): void {
        $incoming = self::snapshot($incomingStatus, updatedAt: CarbonImmutable::parse(self::SHIPPED_UPDATED_AT));

        $fact = $this->builder->rollbackFact($previousStatus, $incoming);

        $this->assertNotNull($fact);
        $this->assertSame($incomingStatus, $fact->status);
        $this->assertTrue(
            $fact->at->equalTo(CarbonImmutable::parse(self::SHIPPED_UPDATED_AT)),
            'Момент отката — updated_at снимка исправления',
        );
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function rollbackProvider(): iterable
    {
        return [
            'shipped → paid (ошибочная отметка отправки)' => [OrderStatus::SHIPPED, OrderStatus::PAID],
            'paid → created (полный откат к оформлению)' => [OrderStatus::PAID, OrderStatus::CREATED],
            'shipped → created' => [OrderStatus::SHIPPED, OrderStatus::CREATED],
        ];
    }

    /**
     * Прогресс и повтор того же статуса — не откат: факт ручного
     * исправления не создаётся (FR-HIS-03: откат — убывание ранга).
     */
    #[DataProvider('noRollbackProvider')]
    public function test_progress_or_same_status_gives_no_rollback_fact(
        OrderStatus $previousStatus,
        OrderStatus $incomingStatus,
    ): void {
        $incoming = self::snapshot($incomingStatus, updatedAt: CarbonImmutable::parse(self::SHIPPED_UPDATED_AT));

        $this->assertNull($this->builder->rollbackFact($previousStatus, $incoming));
    }

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus}>
     */
    public static function noRollbackProvider(): iterable
    {
        return [
            'paid → shipped (прогресс)' => [OrderStatus::PAID, OrderStatus::SHIPPED],
            'created → paid (прогресс)' => [OrderStatus::CREATED, OrderStatus::PAID],
            'shipped → delivered (прогресс, ранг растёт)' => [OrderStatus::SHIPPED, OrderStatus::DELIVERED],
            'shipped → cancelled (переход в терминальный — не откат)' => [OrderStatus::SHIPPED, OrderStatus::CANCELLED],
            'paid → cancelled (переход в терминальный — не откат)' => [OrderStatus::PAID, OrderStatus::CANCELLED],
            'paid → paid (тот же статус — обновление данных)' => [OrderStatus::PAID, OrderStatus::PAID],
            'shipped → shipped (тот же статус)' => [OrderStatus::SHIPPED, OrderStatus::SHIPPED],
        ];
    }

    /**
     * Сравнить набор фактов с ожидаемым без учёта порядка: порядок
     * выдачи factsFromSnapshot не контрактен (сортировка истории —
     * FR-HIS-04, уровень потребителя), поэтому обе стороны
     * сортируются. Дубликаты НЕ устраняются — их должен не выдавать
     * сам билдер для одного снимка.
     *
     * @param list<non-empty-string> $expected факты вида `status@момент UTC`
     * @param list<HistoryFact> $facts
     */
    private function assertFacts(array $expected, array $facts): void
    {
        $actual = self::factKeys($facts);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, 'Факты не совпали (сравнение без порядка).');
    }

    /**
     * @param list<HistoryFact> $facts
     * @return list<non-empty-string>
     */
    private static function factKeys(array $facts): array
    {
        return array_map(
            static fn(HistoryFact $fact): string => $fact->status->value . '@' . $fact->at->utc()->format('Y-m-d\TH:i:sP'),
            $facts,
        );
    }

    /**
     * Снимок ord_7Q2KX9M4 из примеров; изменяемые части — именованные
     * аргументы. Moments передаются как в файлах (смешанные смещения).
     */
    private static function snapshot(
        OrderStatus $status,
        ?CarbonImmutable $paidAt = null,
        ?CarbonImmutable $cancelledAt = null,
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $updatedAt = null,
    ): OrderSnapshot {
        $createdAt ??= CarbonImmutable::parse(self::ORDER_CREATED_AT);
        $updatedAt ??= $createdAt;

        return new OrderSnapshot(
            id: 'ord_7Q2KX9M4',
            number: 'MH-1048213',
            status: $status,
            currency: 'RUB',
            customer: new OrderCustomer('anna.smirnova@example.com', 'Анна Смирнова', '+79161234567'),
            items: [
                new OrderItemSnapshot('TSHIRT-BLK-M', 'Футболка базовая, чёрная, M', 3, '1290.00'),
                new OrderItemSnapshot('CAP-NVY', 'Бейсболка "Classic" / синяя', 1, '890.50'),
            ],
            deliveryPrice: '349.00',
            discount: '200.00',
            total: '4909.50',
            shipment: null,
            paidAt: $paidAt,
            cancelledAt: $cancelledAt,
            cancelReason: null,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }
}
