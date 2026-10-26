<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статус отправки уведомления (transactional outbox).
 *
 * Строковые значения совпадают с колонкой `order_notifications.status`
 * (VARCHAR(16)) и статусами из §6.5 ТЗ plan/TZ-markethub-orders-service-v1.5.md.
 *
 * Допустимые переходы формализованы картой TRANSITIONS (T-012) по
 * FR-MAIL-02..05 и алгоритму §7:
 *
 * | Из \ В | pending | sending | sent | skipped | failed |
 * |--------|---------|---------|------|---------|--------|
 * | pending   | — | ✓ (захват FR-MAIL-04) | ✗ | ✓ (проверки до захвата, §7 шаг 3) | ✗ |
 * | sending   | ✓ (временная ошибка → retry, FR-MAIL-05) | — | ✓ | ✓ (коллизия dedupe-ключа, FR-MAIL-02) | ✓ (попытки исчерпаны, FR-MAIL-05) |
 * | sent      | ✗ | ✗ | — | ✗ | ✗ |
 * | skipped   | ✗ | ✗ | ✗ | — | ✗ |
 * | failed    | ✗ | ✗ | ✗ | ✗ | — |
 *
 * `sent`/`skipped`/`failed` — терминальные: повторная постановка
 * sweeper'ом (FR-MAIL-06) ничего не меняет, а зависший `sending`
 * сознательно не переотправляется (Q25: приоритет «не дублировать»).
 */
enum NotificationStatus: string
{
    /** Запись создана в outbox, ждёт постановки/отправки (FR-MAIL-01). */
    case PENDING = 'pending';

    /** Захвачено воркером перед отправкой (FR-MAIL-04). */
    case SENDING = 'sending';

    /** Письмо отправлено. */
    case SENT = 'sent';

    /** Отправка пропущена по правилам допустимости (FR-MAIL-03/07/08). */
    case SKIPPED = 'skipped';

    /** Попытки исчерпаны (FR-MAIL-05, backoff 10с/1м/5м/15м, максимум 5). */
    case FAILED = 'failed';

    /**
     * Карта допустимых переходов (данные, а не код — принцип §10 ТЗ:
     * правило легко тестировать и менять).
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['sending', 'skipped'],
        'sending' => ['sent', 'skipped', 'failed', 'pending'],
        'sent' => [],
        'skipped' => [],
        'failed' => [],
    ];

    /**
     * Допустим ли переход из этого статуса в $next (FR-MAIL-02..05).
     *
     * `pending → sending` — атомарный захват воркером (FR-MAIL-04);
     * `pending → skipped` — предотправочные проверки до захвата:
     * матрица допустимости, валидность email и наличие shipment
     * (§7 шаг 3, FR-MAIL-03/07/08); `sending → pending` — временная
     * ошибка SMTP с attempts++ и backoff (FR-MAIL-05); терминальные
     * статусы не покидаются.
     */
    public function canTransitionTo(self $next): bool
    {
        $allowed = self::TRANSITIONS[$this->value] ?? [];

        return in_array($next->value, $allowed, true);
    }
}
