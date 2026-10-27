/**
 * Форматирование дат и денег для админки: локаль ru-RU, часовой пояс
 * браузера (§6.7 ТЗ). API отдаёт UTC (ISO 8601 со смещением) — момент
 * времени сохраняется, отображается локально.
 */

let formatter: Intl.DateTimeFormat | null = null;

function getFormatter(): Intl.DateTimeFormat {
    formatter ??= new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return formatter;
}

/** `2026-10-22T06:40:03+00:00` → «22 окт. 2026 г., 09:40» (локальная зона). */
export function formatDateTime(iso: string): string {
    return getFormatter().format(new Date(iso));
}

const moneyFormatters = new Map<string, Intl.NumberFormat | null>();

/**
 * Форматтер суммы по валюте (кэш): контракт допускает любую валюту
 * из трёх заглавных букв, не только ISO 4217 — Intl принимает любой
 * корректный по форме код, но на случай экзотики здесь же кладётся
 * null для запасного вывода.
 *
 * Дробность зафиксирована двумя знаками, а не берётся из конвенции
 * валюты (JPY — 0, KWD/BHD — 3): схема Amount контракта всегда
 * хранит два знака, и отображение не должно терять или выдумывать
 * копейки относительно данных API.
 */
function getMoneyFormatter(currency: string): Intl.NumberFormat | null {
    let formatter = moneyFormatters.get(currency);

    if (formatter === undefined) {
        try {
            formatter = new Intl.NumberFormat('ru-RU', {
                style: 'currency',
                currency,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        } catch {
            formatter = null;
        }

        moneyFormatters.set(currency, formatter);
    }

    return formatter;
}

/**
 * `('1499.90', 'USD')` → «1 499,90 $» (`('999.00', 'RUB')` → «999,00 ₽»).
 * Для неизвестного Intl кода — запасной вывод «1499.90 XYZ» без
 * переформатирования: сумма приходит из API строкой с двумя знаками
 * (схема Amount) и остаётся строкой.
 */
export function formatMoney(amount: string, currency: string): string {
    const formatter = getMoneyFormatter(currency);

    if (formatter === null) {
        return `${amount} ${currency}`;
    }

    return formatter.format(Number(amount));
}
