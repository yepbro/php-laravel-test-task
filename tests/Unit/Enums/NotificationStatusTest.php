<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\NotificationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

class NotificationStatusTest extends TestCase
{
    /**
     * Полный состав case'ов: статусы уведомлений из §6.5 ТЗ, колонка
     * order_notifications.status (VARCHAR(16)).
     */
    public function test_cases_match_database_and_api_values(): void
    {
        $this->assertSame(
            ['pending', 'sending', 'sent', 'skipped', 'failed'],
            array_map(static fn(NotificationStatus $status): string => $status->value, NotificationStatus::cases()),
        );
    }

    public function test_is_backed_by_string(): void
    {
        $this->assertSame('string', (new \ReflectionEnum(NotificationStatus::class))->getBackingType()?->getName());
    }

    public function test_values_fit_into_status_column(): void
    {
        foreach (NotificationStatus::cases() as $status) {
            $this->assertLessThanOrEqual(16, mb_strlen($status->value));
        }
    }

    public function test_from_maps_known_values_to_cases(): void
    {
        $this->assertSame(NotificationStatus::PENDING, NotificationStatus::from('pending'));
        $this->assertSame(NotificationStatus::SENDING, NotificationStatus::from('sending'));
        $this->assertSame(NotificationStatus::SENT, NotificationStatus::from('sent'));
        $this->assertSame(NotificationStatus::SKIPPED, NotificationStatus::from('skipped'));
        $this->assertSame(NotificationStatus::FAILED, NotificationStatus::from('failed'));
    }

    /** Несуществующее значение не маскируется приведением типа. */
    public function test_from_unknown_value_throws_value_error(): void
    {
        $this->expectException(ValueError::class);

        NotificationStatus::from('delivered');
    }

    public function test_try_from_unknown_value_returns_null(): void
    {
        $this->assertNull(NotificationStatus::tryFrom('delivered'));
        $this->assertNull(NotificationStatus::tryFrom('duplicate'));
    }

    /**
     * Полная матрица переходов (T-012, FR-MAIL-02..05 + §7): допустимые
     * пары заданы явно, любая не перечисленная пара обязана быть
     * недопустимой — терминальные статусы не покидаются, «прыжки» мимо
     * захвата (pending → sent/failed) запрещены.
     *
     * @param non-empty-string $from
     * @param non-empty-string $to
     */
    #[DataProvider('allowedTransitionsProvider')]
    public function test_transition_is_allowed(string $from, string $to): void
    {
        $this->assertTrue(
            NotificationStatus::from($from)->canTransitionTo(NotificationStatus::from($to)),
            "Переход {$from} → {$to} должен быть допустим",
        );
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function allowedTransitionsProvider(): iterable
    {
        return [
            'pending → sending: атомарный захват воркером (FR-MAIL-04)' => ['pending', 'sending'],

            'pending → skipped: предотправочные проверки до захвата (§7 шаг 3)' => ['pending', 'skipped'],

            'sending → sent: письмо отправлено' => ['sending', 'sent'],

            'sending → pending: временная ошибка, retry с attempts++ (FR-MAIL-05)' => ['sending', 'pending'],

            'sending → failed: попытки исчерпаны (FR-MAIL-05)' => ['sending', 'failed'],

            'sending → skipped: коллизия dedupe-ключа при захвате (FR-MAIL-02)' => ['sending', 'skipped'],
        ];
    }

    /**
     * @param non-empty-string $from
     * @param non-empty-string $to
     */
    #[DataProvider('deniedTransitionsProvider')]
    public function test_transition_is_denied(string $from, string $to): void
    {
        $this->assertFalse(
            NotificationStatus::from($from)->canTransitionTo(NotificationStatus::from($to)),
            "Переход {$from} → {$to} должен быть запрещён",
        );
    }

    /**
     * Все недопустимые пары (полный дополнок к allowed-матрице 5×5).
     *
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function deniedTransitionsProvider(): iterable
    {
        return [
            // pending: только захват и skip; «успех» без захвата невозможен.
            'pending → pending — self-переход не нужен (статус не меняется)' => ['pending', 'pending'],
            'pending → sent — успех возможен только через захват sending' => ['pending', 'sent'],
            'pending → failed — попытка всегда проходит через sending' => ['pending', 'failed'],

            // sending: все завершения перечислены в allowed-наборе.
            'sending → sending — повторный захват одного письма запрещён' => ['sending', 'sending'],

            // Терминальные статусы (Q25: зависший sending не переотправляется,
            // но и завершённые записи не меняются), включая self-переходы.
            'sent → sent' => ['sent', 'sent'],
            'sent → pending' => ['sent', 'pending'],
            'sent → sending' => ['sent', 'sending'],
            'sent → skipped' => ['sent', 'skipped'],
            'sent → failed' => ['sent', 'failed'],
            'skipped → skipped' => ['skipped', 'skipped'],
            'skipped → pending' => ['skipped', 'pending'],
            'skipped → sending' => ['skipped', 'sending'],
            'skipped → sent' => ['skipped', 'sent'],
            'skipped → failed' => ['skipped', 'failed'],
            'failed → failed' => ['failed', 'failed'],
            'failed → pending' => ['failed', 'pending'],
            'failed → sending' => ['failed', 'sending'],
            'failed → sent' => ['failed', 'sent'],
            'failed → skipped' => ['failed', 'skipped'],
        ];
    }

    /**
     * Матрица полна: 25 пар = 6 допустимых + 19 недопустимых (DataProvider
     * выше), самопереходы не требуются нигде.
     */
    public function test_transition_matrix_covers_all_pairs(): void
    {
        $allowed = self::allowedTransitionsProvider();
        $denied = self::deniedTransitionsProvider();

        $this->assertCount(6, iterator_to_array($allowed, false));
        $this->assertCount(19, iterator_to_array($denied, false));
    }
}
