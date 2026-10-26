/**
 * Клиентский слой заказов (T-013): типизированные запросы к методам
 * контракта task/openapi.yaml — GET /api/orders и GET /api/orders/{id}
 * (Q3-гибрид, FR-ADM-05: данные заказов браузер запрашивает сам).
 *
 * Пути захардкожены намеренно: они зафиксированы контрактом openapi,
 * а генерируемые Wayfinder-функции (resources/js/routes) — gitignored
 * артефакт vite dev/build, зависимости от него в коде быть не должно
 * (решение — DECISIONS.md, «Задача 013»).
 */
import { requestJson } from '@/lib/api/client';
import type {
    Order,
    OrderResponse,
    OrderStatus,
    PaginatedOrders,
} from '@/types';
import { isOrderStatus } from '@/types';

/** Параметры GET /api/orders (query-параметры контракта). */
export interface OrderListQueryParams {
    status?: OrderStatus;
    email?: string;
    page?: number;
    perPage?: number;
}

/** Пользовательские фильтры списка (без пагинации). */
export interface OrderListFilterValues {
    status?: OrderStatus;
    email?: string;
}

const ORDERS_URL = '/api/orders';

/**
 * Маппинг параметров → query-string API: undefined/null/пустые
 * значения не попадают в URL («пустой фильтр игнорируется»,
 * FR-API-02), perPage становится per_page.
 */
export function orderListSearchParams(
    params: OrderListQueryParams,
): URLSearchParams {
    const search = new URLSearchParams();

    if (params.status !== undefined) {
        search.set('status', params.status);
    }

    const email = params.email?.trim();

    if (email) {
        search.set('email', email);
    }

    if (params.page !== undefined && params.page > 1) {
        search.set('page', String(params.page));
    }

    if (params.perPage !== undefined) {
        search.set('per_page', String(params.perPage));
    }

    return search;
}

/** Собирает URL запроса списка: /api/orders?status=paid&page=2 */
export function orderListUrl(params: OrderListQueryParams): string {
    const search = orderListSearchParams(params).toString();

    return search ? `${ORDERS_URL}?${search}` : ORDERS_URL;
}

/** Собирает URL запроса карточки; id экранируется. */
export function orderUrl(id: string): string {
    return `${ORDERS_URL}/${encodeURIComponent(id)}`;
}

function positiveInt(value: string | null): number | undefined {
    if (value === null || !/^\d+$/.test(value)) {
        return undefined;
    }

    const parsed = Number.parseInt(value, 10);

    return parsed >= 1 ? parsed : undefined;
}

/**
 * Маппинг query-string адреса админки (/admin/orders?…) → параметры
 * API (FR-ADM-01: состояние фильтров и страницы хранится в URL).
 *
 * `per_page` сознательно не читается: пользовательский размер страницы
 * в ТЗ не описан (в URL админки — статус/email/страница), список
 * грузится с серверным дефолтом `defaultPerPage`; маппинг
 * `perPage → per_page` для запросов к API — в orderListSearchParams.
 *
 * Неверные значения отбрасываются: статус вне enum и нечисловая
 * страница в URL не тянут за собой 422 от API при первом же заходе —
 * пользователь получает список с чистыми параметрами. Полная
 * синхронизация URL ↔ состояние фильтров — T-039.
 */
export function orderListParamsFromSearch(
    search: string,
): OrderListQueryParams {
    const urlSearch = new URLSearchParams(search);
    const status = urlSearch.get('status');
    const email = urlSearch.get('email')?.trim();
    const page = positiveInt(urlSearch.get('page'));

    return {
        status: isOrderStatus(status) ? status : undefined,
        email: email ? email : undefined,
        page,
    };
}

/** GET /api/orders — список заказов (сортировка/фильтры/пагинация — API). */
export async function fetchOrders(
    params: OrderListQueryParams,
): Promise<PaginatedOrders> {
    return requestJson<PaginatedOrders>(orderListUrl(params));
}

/**
 * GET /api/orders/{id} — карточка заказа; 404 → ApiError('not-found').
 * Ответ приходит в обёртке `{ data: Order }` (схема getOrder 200) —
 * возвращается развёрнутый заказ.
 */
export async function fetchOrder(id: string): Promise<Order> {
    const response = await requestJson<OrderResponse>(orderUrl(id));

    return response.data;
}
