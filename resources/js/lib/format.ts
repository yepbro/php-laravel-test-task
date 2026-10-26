/**
 * Форматирование дат для админки: часовой пояс браузера, ru-RU
 * (§6.7 ТЗ). API отдаёт UTC (ISO 8601 со смещением) — момент времени
 * сохраняется, отображается локально.
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
