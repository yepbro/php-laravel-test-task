<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Data\OrderSnapshot;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Support\HistoryBuilder;
use App\Support\Money;
use App\Support\NotificationPolicy;
use App\Support\SnapshotVersionComparator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Скелетные классы доменных правил (T-009): пока метод не реализован,
 * он обязан явно падать с LogicException и номером задачи-владельца —
 * заглушка не может изображать успешный результат (правило этапа 2).
 * Бизнес-тесты T-016 закрываются в T-022/T-023/T-030/T-031; владелец,
 * снявший заглушку, переворачивает его скелетный тест в том же
 * коммите (DECISIONS.md, задача 016): Money::subtotal (T-022),
 * SnapshotVersionComparator::isNewer (T-023), HistoryBuilder (T-030)
 * и NotificationPolicy::allows (T-031) реализованы — тесты ниже
 * закрепляют реальный результат вместо ожидания исключения.
 */
class DomainRuleSkeletonsTest extends TestCase
{
    /**
     * Перевёрнут с ожидания LogicException (T-023 реализован). Точки
     * переворота на примерах жизненного цикла task/examples: один
     * момент в разных записях (+03:00 против +00:00) — не новее (S-19),
     * свежий updated_at — новее; полный набор поведенческих случаев —
     * SnapshotVersionComparatorTest (T-016).
     */
    public function test_snapshot_version_comparator_is_implemented(): void
    {
        $comparator = new SnapshotVersionComparator();
        $stored = new \App\Data\Version(
            CarbonImmutable::parse('2026-10-21T10:02:11+00:00'),
            CarbonImmutable::parse('2026-10-21T10:02:12+00:00'),
            'evt_skeleton',
        );
        $sameMomentOtherOffset = new \App\Data\Version(
            CarbonImmutable::parse('2026-10-21T13:02:11+03:00'),
            $stored->eventCreatedAt,
            'evt_skeleton',
        );
        $newer = new \App\Data\Version(
            CarbonImmutable::parse('2026-10-21T13:03:30+03:00'),
            $stored->eventCreatedAt,
            'evt_skeleton',
        );

        $this->assertFalse($comparator->isNewer($sameMomentOtherOffset, $stored));
        $this->assertTrue($comparator->isNewer($newer, $stored));
    }

    /**
     * Перевёрнут с ожидания LogicException (T-022 реализован). Точка
     * переворота на примере письма §6.5 (3 × 1290.00 = 3870.00);
     * поведенческие случаи — MoneyTest (T-016).
     */
    public function test_money_subtotal_is_implemented(): void
    {
        $this->assertSame('3870.00', Money::subtotal('1290.00', 3));
    }

    /**
     * Перевёрнут с ожидания LogicException (T-030 реализован). Точка
     * переворота: created-снимок даёт единственный факт
     * `created @ created_at`; поведенческие случаи (fallback на
     * updated_at, восстановление пропущенного paid, FR-HIS-05) —
     * HistoryBuilderTest (T-016).
     */
    public function test_history_builder_facts_are_implemented(): void
    {
        $facts = (new HistoryBuilder())->factsFromSnapshot(self::minimalSnapshot());

        $this->assertCount(1, $facts);
        $this->assertSame(OrderStatus::CREATED, $facts[0]->status);
        $this->assertTrue(
            $facts[0]->at->equalTo(CarbonImmutable::parse('2026-10-21T10:02:11+00:00')),
            'Момент факта created — родное created_at снимка (FR-HIS-01).',
        );
    }

    /**
     * Перевёрнут с ожидания LogicException (T-030 реализован). Точка
     * переворота: убывание ранга — факт отката «новый статус @
     * updated_at» (FR-HIS-03), рост ранга — факта нет; полная матрица
     * прогресс/откат/терминальные — HistoryBuilderTest (T-016).
     */
    public function test_history_builder_rollback_fact_is_implemented(): void
    {
        $builder = new HistoryBuilder();

        $rollback = $builder->rollbackFact(
            OrderStatus::SHIPPED,
            self::minimalSnapshot(
                status: OrderStatus::PAID,
                updatedAt: CarbonImmutable::parse('2026-10-22T09:00:00+00:00'),
            ),
        );

        $this->assertNotNull($rollback);
        $this->assertSame(OrderStatus::PAID, $rollback->status);
        $this->assertTrue(
            $rollback->at->equalTo(CarbonImmutable::parse('2026-10-22T09:00:00+00:00')),
            'Момент отката — updated_at снимка исправления (FR-HIS-03).',
        );
        $this->assertNull(
            $builder->rollbackFact(OrderStatus::PAID, self::minimalSnapshot(status: OrderStatus::SHIPPED)),
            'Прогресс ранга (paid → shipped) — не откат, факта нет.',
        );
    }

    /**
     * Перевёрнут с ожидания LogicException (T-031 реализован). Точки
     * переворота: диагональ матрицы §6.5 — письмо допустимо в «своём»
     * статусе (paid@paid) и недопустимо после отмены (paid@cancelled);
     * полная матрица 3 × 5 — NotificationPolicyTest (T-016).
     */
    public function test_notification_policy_matrix_is_implemented(): void
    {
        $policy = new NotificationPolicy();

        $this->assertTrue($policy->allows(NotificationType::PAID, OrderStatus::PAID));
        $this->assertFalse($policy->allows(NotificationType::PAID, OrderStatus::CANCELLED));
        $this->assertTrue($policy->allows(NotificationType::CANCELLED, OrderStatus::CANCELLED));
    }

    /**
     * @param CarbonImmutable|null $updatedAt обновляемая часть снимка
     *        (по умолчанию совпадает с created_at, как в order-created)
     */
    private static function minimalSnapshot(
        OrderStatus $status = OrderStatus::CREATED,
        ?CarbonImmutable $updatedAt = null,
    ): OrderSnapshot {
        return new OrderSnapshot(
            id: 'ord_minimal',
            number: 'MH-0000001',
            status: $status,
            currency: 'RUB',
            customer: new \App\Data\OrderCustomer('buyer@example.com', 'Покупатель', null),
            items: [],
            deliveryPrice: '0.00',
            discount: '0.00',
            total: '0.00',
            shipment: null,
            paidAt: null,
            cancelledAt: null,
            cancelReason: null,
            createdAt: CarbonImmutable::parse('2026-10-21T10:02:11+00:00'),
            updatedAt: $updatedAt ?? CarbonImmutable::parse('2026-10-21T10:02:11+00:00'),
        );
    }
}
