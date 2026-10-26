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
 * Скелетные классы доменных правил (T-009): пока не реализованы, каждый
 * метод обязан явно падать с LogicException и номером задачи-владельца —
 * заглушка не может изображать успешный результат (правило этапа 2).
 * Бизнес-тесты появятся в T-016 и закрываются в T-022/T-023/T-030/T-031.
 */
class DomainRuleSkeletonsTest extends TestCase
{
    public function test_snapshot_version_comparator_throws_until_implemented(): void
    {
        $comparator = new SnapshotVersionComparator();
        $moment = CarbonImmutable::parse('2026-10-21T10:02:11+00:00');
        $version = new \App\Data\Version($moment, $moment, 'evt_skeleton');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-023');

        $comparator->isNewer($version, $version);
    }

    public function test_money_subtotal_throws_until_implemented(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-022');

        Money::subtotal('1290.00', 3);
    }

    public function test_history_builder_facts_throw_until_implemented(): void
    {
        $builder = new HistoryBuilder();
        $snapshot = self::minimalSnapshot();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-030');

        $builder->factsFromSnapshot($snapshot);
    }

    public function test_history_builder_rollback_fact_throws_until_implemented(): void
    {
        $builder = new HistoryBuilder();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-030');

        $builder->rollbackFact(OrderStatus::PAID, self::minimalSnapshot());
    }

    public function test_notification_policy_throws_until_implemented(): void
    {
        $policy = new NotificationPolicy();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-031');

        $policy->allows(NotificationType::PAID, OrderStatus::PAID);
    }

    private static function minimalSnapshot(): OrderSnapshot
    {
        return new OrderSnapshot(
            id: 'ord_minimal',
            number: 'MH-0000001',
            status: OrderStatus::CREATED,
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
            updatedAt: CarbonImmutable::parse('2026-10-21T10:02:11+00:00'),
        );
    }
}
