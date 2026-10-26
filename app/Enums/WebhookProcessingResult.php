<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Результат обработки вебхука в HTTP-словаре (FR-WH-10, T-010).
 *
 * Пять значений тела успешного ответа: `processed`, `duplicate`, `stale`,
 * `ignored`, `rejected`. Шире журнала `webhook_events.outcome`
 * (EventOutcome): `applied` наружу отдаётся как `processed`, а
 * `duplicate` существует только в HTTP-ответе повторной доставке и в БД
 * не пишется (FR-ORD-01/FR-ORD-08).
 *
 * Возвращает Action обработки события; в HTTP его преобразует
 * WebhookResponseMapper (200 + поле `status`).
 */
enum WebhookProcessingResult: string
{
    /** Снимок применён (в БД — outcome `applied`). */
    case PROCESSED = 'processed';

    /** Повторная доставка уже известного event.id; состояние не менялось. */
    case DUPLICATE = 'duplicate';

    /** Версия снимка не новее сохранённой; состояние не менялось. */
    case STALE = 'stale';

    /** Неизвестный тип события или неизвестный статус; событие только сохранено. */
    case IGNORED = 'ignored';

    /** Свежий снимок отклонён из терминального статуса (Q35). */
    case REJECTED = 'rejected';

    /**
     * HTTP-результат по журнальному исходу события (FR-ORD-08 → FR-WH-10).
     *
     * `duplicate` намеренно не имеет обратного соответствия: он возникает
     * до фиксации исхода новой записи — при идемпотентной повторной
     * доставке (T-026), а не переводом из EventOutcome.
     */
    public static function fromOutcome(EventOutcome $outcome): self
    {
        return match ($outcome) {
            EventOutcome::APPLIED => self::PROCESSED,
            EventOutcome::STALE => self::STALE,
            EventOutcome::IGNORED => self::IGNORED,
            EventOutcome::REJECTED => self::REJECTED,
        };
    }
}
