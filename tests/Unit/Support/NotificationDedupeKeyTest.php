<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\NotificationType;
use App\Support\NotificationDedupeKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ключ дедупликации писем (T-012) — FR-MAIL-02/A-24: `paid` и
 * `cancelled` уникальны в рамках заказа, `shipped` — в рамках пары
 * «заказ + трек-номер» (повторная отправка тем же треком невозможна,
 * другим — уходит, S-11/S-38). Реализация существует с T-012 —
 * фиксирующие тесты зелёные; T-016 добавил края: попарная различность
 * ключей всех типов одного заказа и невозможность коллизии ключа
 * shipped без трека с «настоящим» ключом.
 */
class NotificationDedupeKeyTest extends TestCase
{
    /** @param non-empty-string $orderId */
    #[DataProvider('fixedFormatProvider')]
    public function test_fixed_format_for_paid_and_cancelled(NotificationType $type, string $orderId, string $expected): void
    {
        $this->assertSame($expected, NotificationDedupeKey::for($type, $orderId));
    }

    /**
     * @return iterable<string, array{NotificationType, non-empty-string, non-empty-string}>
     */
    public static function fixedFormatProvider(): iterable
    {
        return [
            'paid для конкретного заказа' => [NotificationType::PAID, 'ord_7Q2KX9M4', 'ord_7Q2KX9M4:paid'],

            'cancelled для конкретного заказа' => [NotificationType::CANCELLED, 'ord_7Q2KX9M4', 'ord_7Q2KX9M4:cancelled'],

            'paid разных заказов дают разные ключи' => [NotificationType::PAID, 'ord_3HV8PW2C', 'ord_3HV8PW2C:paid'],
        ];
    }

    public function test_shipped_uses_tracking_number(): void
    {
        $this->assertSame(
            'ord_7Q2KX9M4:shipped:1005482913',
            NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_7Q2KX9M4', '1005482913'),
        );
    }

    public function test_shipped_with_another_tracking_differs(): void
    {
        $first = NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_7Q2KX9M4', '1005482913');
        $second = NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_7Q2KX9M4', '1005482914');

        $this->assertNotSame($first, $second);
    }

    /**
     * NULL-трек допустим только в нестандартной комбинации «письмо есть,
     * shipment нет» (закрывается skip-веткой FR-MAIL-08): пустой хвост не
     * совпадает ни с одним «настоящим» ключом с треком.
     */
    public function test_shipped_without_tracking_keeps_empty_tail(): void
    {
        $this->assertSame(
            'ord_7Q2KX9M4:shipped:',
            NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_7Q2KX9M4', null),
        );
    }

    public function test_shipped_null_and_empty_tracking_produce_same_key(): void
    {
        $nullTracking = NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_1', null);
        $emptyTracking = NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_1', '');

        $this->assertSame($nullTracking, $emptyTracking);
    }

    /**
     * T-016. Все три типа писем одного заказа дают попарно различные
     * ключи: дедупликация одного типа не задевает другие (S-09/S-10 —
     * paid и cancelled одного заказа существуют независимо).
     */
    public function test_all_types_for_same_order_have_distinct_keys(): void
    {
        $paid = NotificationDedupeKey::for(NotificationType::PAID, 'ord_7Q2KX9M4');
        $shipped = NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_7Q2KX9M4', '1005482913');
        $cancelled = NotificationDedupeKey::for(NotificationType::CANCELLED, 'ord_7Q2KX9M4');

        $this->assertCount(3, array_unique([$paid, $shipped, $cancelled], SORT_STRING));
    }

    /**
     * T-016. Ключ shipped без трек-номера (пустой хвост) не совпадает
     * ни с одним «настоящим» ключом с треком — пустой хвост не может
     * коллизировать с фактической отправкой (докблок NotificationDedupeKey).
     */
    public function test_shipped_without_tracking_never_collides_with_real_tracking_key(): void
    {
        $withoutTracking = NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_7Q2KX9M4', null);
        $withTracking = NotificationDedupeKey::for(NotificationType::SHIPPED, 'ord_7Q2KX9M4', '1005482913');

        $this->assertNotSame($withTracking, $withoutTracking);
    }
}
