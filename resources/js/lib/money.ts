/**
 * Клиентская арифметика сумм контракта (T-041). Схема Amount — строка
 * с двумя знаками (`^-?\d+\.\d{2}$`), и сложение через Number/float
 * теряло бы копейки; вместо этого суммы приводятся к целым копейкам
 * и складываются как integer (запас точности огромен: DECIMAL(12,2) —
 * до ~10^10 на позицию, в копейках — до ~10^12, предел
 * Number.MAX_SAFE_INTEGER — ~9×10^15).
 *
 * Серверный эталон той же арифметики — App\Support\Money (subtotal
 * позиций); здесь она нужна только для отображения разбивки сумм
 * карточки (FR-ADM-02: «позиции, доставка, скидка, итого»): API
 * отдаёт subtotal каждой позиции, но не их сумму.
 */

const AMOUNT_PATTERN = /^(-?)(\d+)\.(\d{2})$/;

/**
 * Соответствует ли строка схеме Amount контракта. Используется на
 * границе API-слоя (fetchOrder): битый формат сумм превращается
 * в ApiError('parse') и показывается состоянием ошибки карточки,
 * а не роняет рендер внутри computed (ревью T-041, P3-1).
 */
export function isValidAmount(value: string): boolean {
    return AMOUNT_PATTERN.test(value);
}

/** Приводит сумму контракта к целым копейкам. */
function toCents(amount: string): number {
    const match = AMOUNT_PATTERN.exec(amount);

    if (match === null) {
        // Формат гарантируется контрактом и пиннится контрактными
        // тестами (FR-API-10); сюда попасть можно только при битом
        // ответе API — это программная ошибка, молчаливый ноль скрыл
        // бы её от диагностики.
        throw new Error(`Некорректная сумма из API: "${amount}"`);
    }

    const [, sign, integral, fraction] = match;
    const cents = Number(integral) * 100 + Number(fraction);

    return sign === '-' ? -cents : cents;
}

/** Целые копейки → строка схемы Amount (`476050` → `"4760.50"`). */
function fromCents(cents: number): string {
    const sign = cents < 0 ? '-' : '';
    const absolute = Math.abs(cents);

    return `${sign}${Math.trunc(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

/** Суммирует суммы контракта без float: `["3870.00", "890.50"] → "4760.50"`. */
export function sumAmounts(amounts: readonly string[]): string {
    return fromCents(amounts.reduce((sum, amount) => sum + toCents(amount), 0));
}
