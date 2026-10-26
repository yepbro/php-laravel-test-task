<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Support\NotificationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Матрица допустимости писем по текущему статусу заказа (T-016;
 * красное до T-031) — §6.5/A-23 дословно:
 *
 * Письмо ↓ / статус → | created | paid | shipped | delivered | cancelled
 * --------------------|---------|------|---------|----------|----------
 * paid                |    ✗    |  ✓   |    ✓    |    ✓     |    ✗
 * shipped             |    ✗    |  ✗   |    ✓    |    ✓     |    ✗
 * cancelled           |    ✗    |  ✗   |    ✗    |    ✗     |    ✓
 *
 * Матрица проверяется непосредственно перед отправкой (FR-MAIL-03):
 * статус мог смениться после постановки в очередь. Письмо-исправление
 * при откате статуса не отправляется (Q28) — это правило ТРИГГЕРА
 * создания outbox (только переход статуса, T-032), а не матрицы
 * момента отправки: allows(paid, paid) = true не противоречит Q28 —
 * повторное письмо не будет создано триггером и дедупликацией.
 *
 * Пока NotificationPolicy::allows() — заглушка T-031, все кейсы
 * матрицы падают с её LogicException; после реализации становятся
 * зелёными без правки тестов.
 */
class NotificationPolicyTest extends TestCase
{
    private NotificationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new NotificationPolicy();
    }

    /** @param non-empty-string $caseName */
    #[DataProvider('matrixProvider')]
    public function test_matrix(string $caseName, NotificationType $type, OrderStatus $currentStatus, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->policy->allows($type, $currentStatus),
            'Матрица §6.5, кейс «' . $caseName . '» нарушен.',
        );
    }

    /**
     * Полное декартово произведение NotificationType × OrderStatus
     * (3 × 5 = 15) — «вся матрица допустимости из §6.5».
     *
     * @return iterable<string, array{non-empty-string, NotificationType, OrderStatus, bool}>
     */
    public static function matrixProvider(): iterable
    {
        return [
            'paid при created — недопустимо' => ['paid/created', NotificationType::PAID, OrderStatus::CREATED, false],
            'paid при paid — допустимо' => ['paid/paid', NotificationType::PAID, OrderStatus::PAID, true],
            'paid при shipped — допустимо' => ['paid/shipped', NotificationType::PAID, OrderStatus::SHIPPED, true],
            'paid при delivered — допустимо' => ['paid/delivered', NotificationType::PAID, OrderStatus::DELIVERED, true],
            'paid при cancelled — недопустимо' => ['paid/cancelled', NotificationType::PAID, OrderStatus::CANCELLED, false],

            'shipped при created — недопустимо' => ['shipped/created', NotificationType::SHIPPED, OrderStatus::CREATED, false],
            'shipped при paid — недопустимо' => ['shipped/paid', NotificationType::SHIPPED, OrderStatus::PAID, false],
            'shipped при shipped — допустимо' => ['shipped/shipped', NotificationType::SHIPPED, OrderStatus::SHIPPED, true],
            'shipped при delivered — допустимо' => ['shipped/delivered', NotificationType::SHIPPED, OrderStatus::DELIVERED, true],
            'shipped при cancelled — недопустимо' => ['shipped/cancelled', NotificationType::SHIPPED, OrderStatus::CANCELLED, false],

            'cancelled при created — недопустимо' => ['cancelled/created', NotificationType::CANCELLED, OrderStatus::CREATED, false],
            'cancelled при paid — недопустимо' => ['cancelled/paid', NotificationType::CANCELLED, OrderStatus::PAID, false],
            'cancelled при shipped — недопустимо' => ['cancelled/shipped', NotificationType::CANCELLED, OrderStatus::SHIPPED, false],
            'cancelled при delivered — недопустимо' => ['cancelled/delivered', NotificationType::CANCELLED, OrderStatus::DELIVERED, false],
            'cancelled при cancelled — допустимо' => ['cancelled/cancelled', NotificationType::CANCELLED, OrderStatus::CANCELLED, true],
        ];
    }

    /**
     * Защита полноты: провайдер покрывает ровно все комбинации
     * 3 типов × 5 статусов — без дублей и пропусков ячеек матрицы
     * (сверка множества пар с декартовым произведением).
     */
    public function test_matrix_provider_covers_full_cross_product(): void
    {
        $providerCases = iterator_to_array(self::matrixProvider(), true);

        $this->assertCount(
            count(NotificationType::cases()) * count(OrderStatus::cases()),
            $providerCases,
            'Матрица §6.5 должна покрывать все комбинации тип × статус.',
        );

        $covered = array_map(
            static fn(array $case): string => $case[1]->value . '/' . $case[2]->value,
            $providerCases,
        );
        sort($covered);

        $crossProduct = [];
        foreach (NotificationType::cases() as $type) {
            foreach (OrderStatus::cases() as $status) {
                $crossProduct[] = $type->value . '/' . $status->value;
            }
        }
        sort($crossProduct);

        $this->assertSame($crossProduct, $covered, 'Матрица §6.5: каждая ячейка тип × статус покрыта ровно один раз.');
    }
}
