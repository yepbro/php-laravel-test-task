import type { APIRequestContext, Locator, Page } from '@playwright/test';

/**
 * Помощники браузерных smoke-тестов админки (T-047).
 *
 * Данные не готовятся и не мутируются: тесты работают на demo-наборе
 * сидера T-007 живого стенда и сверяют отрисованный UI с ответами тех
 * же методов контракта task/openapi.yaml, которыми пользуется админка
 * (Q3-гибрид, FR-ADM-05) — предсказуемые якоря демо-сида (пул email,
 * статусы паттерном, ≥ 21 заказа для второй страницы) плюс
 * самосогласованность UI ↔ API делают сценарии устойчивыми к любым
 * дополнительным заказам на стенде.
 */

/** Русские подписи статусов — зеркало карты StatusBadge.vue (T-040). */
export const STATUS_LABELS: Record<string, string> = {
    created: 'Создан',
    paid: 'Оплачен',
    shipped: 'Отправлен',
    delivered: 'Доставлен',
    cancelled: 'Отменён',
};

/** Email демо-пула сидера T-007: заказы с ним гарантированно есть. */
export const DEMO_EMAIL = 'boris@shop.test';

/** Схема OrderListItem контракта — только читаемые спеками поля. */
export interface ApiOrderListItem {
    id: string;
    number: string;
    status: string;
    customer: { email: string; name: string };
    total: string;
    currency: string;
    created_at: string;
    updated_at: string;
}

/** Схема Order контракта (карточка GET /api/orders/{id}). */
export interface ApiOrder {
    id: string;
    number: string;
    status: string;
    customer: { email: string; name: string; phone: string | null };
    currency: string;
    items: Array<{
        sku: string;
        name: string;
        quantity: number;
        price: string;
        subtotal: string;
    }>;
    delivery_price: string;
    discount: string;
    total: string;
    shipment: {
        carrier: string;
        tracking_number: string;
        tracking_url: string;
    } | null;
    paid_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    created_at: string;
    updated_at: string;
    history: Array<{ status: string; at: string }>;
}

/** meta пагинатора Laravel (схема listOrders контракта). */
export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
}

export interface PaginatedOrders {
    data: ApiOrderListItem[];
    meta: PaginationMeta;
}

export type OrdersListQuery = {
    status?: string;
    email?: string;
    page?: number;
    perPage?: number;
};

/** GET /api/orders — тем же методом контракта, что и админка. */
export async function fetchOrdersList(
    request: APIRequestContext,
    query: OrdersListQuery = {},
): Promise<PaginatedOrders> {
    const search = new URLSearchParams();

    if (query.status !== undefined) {
        search.set('status', query.status);
    }

    if (query.email !== undefined) {
        search.set('email', query.email);
    }

    if (query.page !== undefined && query.page > 1) {
        search.set('page', String(query.page));
    }

    if (query.perPage !== undefined) {
        search.set('per_page', String(query.perPage));
    }

    const suffix = search.toString() === '' ? '' : `?${search.toString()}`;
    const response = await request.get(`/api/orders${suffix}`);

    if (!response.ok()) {
        throw new Error(
            `GET /api/orders${suffix} → ${response.status()}: ${await response.text()}`,
        );
    }

    return (await response.json()) as PaginatedOrders;
}

/** GET /api/orders/{id} — карточка, развёрнутая из обёртки { data }. */
export async function fetchOrder(
    request: APIRequestContext,
    id: string,
): Promise<ApiOrder> {
    const response = await request.get(`/api/orders/${id}`);

    if (!response.ok()) {
        throw new Error(
            `GET /api/orders/${id} → ${response.status()}: ${await response.text()}`,
        );
    }

    return ((await response.json()) as { data: ApiOrder }).data;
}

/**
 * «Богатый» заказ для сценария карточки: ≥ 2 позиций, ≥ 3 фактов
 * истории, есть отправка и дата оплаты. Демо-сид создаёт такие в
 * статусах delivered/shipped (T-007), но кандидат выбирается по
 * фактическим данным API, а не по предположениям о сидах.
 */
export async function pickRichOrder(
    request: APIRequestContext,
): Promise<ApiOrder> {
    for (const status of ['delivered', 'shipped'] as const) {
        const candidates = await fetchOrdersList(request, {
            status,
            perPage: 100,
        });

        for (const item of candidates.data) {
            const order = await fetchOrder(request, item.id);

            if (
                order.items.length >= 2 &&
                order.history.length >= 3 &&
                order.shipment !== null &&
                order.paid_at !== null
            ) {
                return order;
            }
        }
    }

    throw new Error(
        'На стенде нет заказа с ≥2 позициями, ≥3 фактами истории, отправкой и датой оплаты — ожидается demo-набор T-007.',
    );
}

/** Зеркало lib/format.ts: Intl ru-RU по валюте, два знака; для кода,
 * которого Intl не знает, — тот же запасной вывод `${amount} ${currency}`
 * (клиент не падает на экзотических валютах контракта — и зеркало не
 * должно, ревью T-047 P3-1). */
export function formatMoney(amount: string, currency: string): string {
    try {
        return new Intl.NumberFormat('ru-RU', {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(amount));
    } catch {
        return `${amount} ${currency}`;
    }
}

/** Зеркало lib/money.ts sumAmounts: целые копейки, без float. */
export function sumAmounts(amounts: readonly string[]): string {
    const cents = amounts.reduce((sum, amount) => {
        const match = /^(-?)(\d+)\.(\d{2})$/.exec(amount);

        if (match === null) {
            throw new Error(`Некорректная сумма из API: "${amount}"`);
        }

        const value = Number(match[2]) * 100 + Number(match[3]);

        return sum + (match[1] === '-' ? -value : value);
    }, 0);
    const sign = cents < 0 ? '-' : '';
    const absolute = Math.abs(cents);

    return `${sign}${Math.trunc(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

/** Строки таблицы списка (десктоп от sm — дефолтный viewport 1280×720). */
export function tableRows(page: Page): Locator {
    return page.locator('table tbody tr');
}

/** Значение строки разбивки сумм карточки по подписи («Итого»…). */
export function summaryValue(page: Page, label: string): Locator {
    return page.locator(`div:has(> dt:text-is("${label}")) > dd`);
}
