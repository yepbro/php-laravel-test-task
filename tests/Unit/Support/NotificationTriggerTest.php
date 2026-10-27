<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Support\NotificationTrigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Правило триггера outbox-уведомлений (T-032) — §6.5 A-22/A-28 +
 * §7 шаг 13f: письмо создаётся, только если применённый снимок ДЕЙСТВИТЕЛЬНО
 * меняет статус заказа на paid/shipped/cancelled, и переход не является
 * откатом (Q28: письмо-исправление не отправляется).
 *
 * Полная матрица переходов «предыдущий статус × новый статус» (6 × 5 = 30;
 * prev = null — первый снимок, создание заказа). Тип письма определяется
 * НОВЫМ статусом (не event.type — совпадение типа события и статуса ТЗ
 * не вводит); у created/delivered писем нет. Поведенческие сценарии
 * HTTP-уровня (S-01/S-06/S-07/S-11/S-31/S-37) — feature-наборы T-017/T-019;
 * здесь — чистая функция, интеграция — ProcessMarketplaceEventTest (T-032).
 *
 * Ячейки prev = delivered/cancelled с другим статусом в конвейере
 * недостижимы: такие переходы отклоняются раньше (FR-ORD-09, шаг 13d,
 * T-029); матрица фиксирует контракт функции на случай изменения списка
 * терминальных статусов в конфиге (риск R-2).
 */
class NotificationTriggerTest extends TestCase
{
    private NotificationTrigger $trigger;

    protected function setUp(): void
    {
        $this->trigger = new NotificationTrigger();
    }

    /** @param non-empty-string $caseName */
    #[DataProvider('transitionProvider')]
    public function test_transition_matrix_matches_trigger_rules(
        string $caseName,
        ?OrderStatus $previousStatus,
        OrderStatus $newStatus,
        ?NotificationType $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->trigger->typeFor($previousStatus, $newStatus),
            'Матрица триггера §6.5/13f, переход «' . $caseName . '» нарушен.',
        );
    }

    /**
     * Полная матрица переходов (§6.5 триггеры + Q28 + первый снимок):
     * prev = null — создание заказа из первого снимка (S-06/S-07);
     * prev = delivered/cancelled с другим статусом — в конвейере
     * недостижимо (шаг 13d), ожидания зафиксированы по контракту
     * функции (ранги: created=0, paid=1, shipped=2, delivered=3,
     * cancelled=4; письмо есть только у paid/shipped/cancelled).
     *
     * @return iterable<non-empty-string, array{non-empty-string, OrderStatus|null, OrderStatus, NotificationType|null}>
     */
    public static function transitionProvider(): iterable
    {
        return [
            // Первый снимок (создание заказа): тип — из статуса создания.
            'первый снимок → created — письма нет' => ['первый снимок → created', null, OrderStatus::CREATED, null],
            'первый снимок → paid — paid-письмо' => ['первый снимок → paid', null, OrderStatus::PAID, NotificationType::PAID],
            'первый снимок → shipped — shipped-письмо' => ['первый снимок → shipped', null, OrderStatus::SHIPPED, NotificationType::SHIPPED],
            'первый снимок → delivered — письма нет' => ['первый снимок → delivered', null, OrderStatus::DELIVERED, null],
            'первый снимок → cancelled — cancelled-письмо' => ['первый снимок → cancelled', null, OrderStatus::CANCELLED, NotificationType::CANCELLED],

            // Из created: прогресс во все письма; delivered — без письма.
            'created → created — нет смены' => ['created → created', OrderStatus::CREATED, OrderStatus::CREATED, null],
            'created → paid — прогресс' => ['created → paid', OrderStatus::CREATED, OrderStatus::PAID, NotificationType::PAID],
            'created → shipped — прогресс' => ['created → shipped', OrderStatus::CREATED, OrderStatus::SHIPPED, NotificationType::SHIPPED],
            'created → delivered — без письма' => ['created → delivered', OrderStatus::CREATED, OrderStatus::DELIVERED, null],
            'created → cancelled — переход в терминальный' => ['created → cancelled', OrderStatus::CREATED, OrderStatus::CANCELLED, NotificationType::CANCELLED],

            // Из paid: прогресс вперёд; откат в created — Q28.
            'paid → created — откат (Q28)' => ['paid → created', OrderStatus::PAID, OrderStatus::CREATED, null],
            'paid → paid — нет смены' => ['paid → paid', OrderStatus::PAID, OrderStatus::PAID, null],
            'paid → shipped — прогресс' => ['paid → shipped', OrderStatus::PAID, OrderStatus::SHIPPED, NotificationType::SHIPPED],
            'paid → delivered — без письма' => ['paid → delivered', OrderStatus::PAID, OrderStatus::DELIVERED, null],
            'paid → cancelled — переход в терминальный' => ['paid → cancelled', OrderStatus::PAID, OrderStatus::CANCELLED, NotificationType::CANCELLED],

            // Из shipped: вперёд — только cancelled с письмом; откаты
            // в paid/created — письмо-исправление запрещено (Q28, S-37).
            'shipped → created — откат (Q28)' => ['shipped → created', OrderStatus::SHIPPED, OrderStatus::CREATED, null],
            'shipped → paid — откат, письма-исправления нет (S-37)' => ['shipped → paid', OrderStatus::SHIPPED, OrderStatus::PAID, null],
            'shipped → shipped — нет смены' => ['shipped → shipped', OrderStatus::SHIPPED, OrderStatus::SHIPPED, null],
            'shipped → delivered — без письма' => ['shipped → delivered', OrderStatus::SHIPPED, OrderStatus::DELIVERED, null],
            'shipped → cancelled — переход в терминальный' => ['shipped → cancelled', OrderStatus::SHIPPED, OrderStatus::CANCELLED, NotificationType::CANCELLED],

            // Из delivered (в конвейере переходы закрыты шагом 13d):
            // тот же статус — нет смены; откаты — Q28; единственный
            // «прогресс» delivered → cancelled по рангам дал бы
            // cancelled-письмо, но до триггера не доходит (S-39).
            'delivered → created — откат (Q28)' => ['delivered → created', OrderStatus::DELIVERED, OrderStatus::CREATED, null],
            'delivered → paid — откат (Q28)' => ['delivered → paid', OrderStatus::DELIVERED, OrderStatus::PAID, null],
            'delivered → shipped — откат (Q28)' => ['delivered → shipped', OrderStatus::DELIVERED, OrderStatus::SHIPPED, null],
            'delivered → delivered — нет смены (S-31)' => ['delivered → delivered', OrderStatus::DELIVERED, OrderStatus::DELIVERED, null],
            'delivered → cancelled — по рангам не откат (до триггера не доходит, 13d)' => ['delivered → cancelled', OrderStatus::DELIVERED, OrderStatus::CANCELLED, NotificationType::CANCELLED],

            // Из cancelled — терминальный: любые переходы закрыты шагом 13d;
            // тот же статус — обновление данных без письма (S-31).
            'cancelled → created — откат (Q28, закрыт 13d)' => ['cancelled → created', OrderStatus::CANCELLED, OrderStatus::CREATED, null],
            'cancelled → paid — откат (Q28, закрыт 13d)' => ['cancelled → paid', OrderStatus::CANCELLED, OrderStatus::PAID, null],
            'cancelled → shipped — откат (Q28, закрыт 13d)' => ['cancelled → shipped', OrderStatus::CANCELLED, OrderStatus::SHIPPED, null],
            'cancelled → delivered — откат (Q28, закрыт 13d)' => ['cancelled → delivered', OrderStatus::CANCELLED, OrderStatus::DELIVERED, null],
            'cancelled → cancelled — нет смены' => ['cancelled → cancelled', OrderStatus::CANCELLED, OrderStatus::CANCELLED, null],
        ];
    }

    /**
     * Защита полноты: провайдер покрывает все комбинации
     * 6 предыдущих состояний × 5 статусов без дублей (сверка пар
     * с декартовым произведением — паттерн NotificationPolicyTest).
     */
    public function test_transition_provider_covers_full_cross_product(): void
    {
        $providerCases = iterator_to_array(self::transitionProvider(), true);

        $this->assertCount(6 * count(OrderStatus::cases()), $providerCases);

        $covered = array_map(
            static fn(array $case): string => $case[0],
            $providerCases,
        );

        $crossProduct = [];
        $previousNames = ['первый снимок', 'created', 'paid', 'shipped', 'delivered', 'cancelled'];
        foreach ($previousNames as $prevName) {
            foreach (OrderStatus::cases() as $status) {
                $crossProduct[] = sprintf('%s → %s', $prevName, $status->value);
            }
        }

        sort($covered);
        sort($crossProduct);

        $this->assertSame($crossProduct, $covered, 'Каждый переход матрицы покрыт ровно один раз.');
    }
}
