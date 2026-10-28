/**
 * Клиентский слой заказов (T-013): типизированные запросы к методам
 * контракта task/openapi.yaml — GET /api/orders и GET /api/orders/{id}
 * (Q3-гибрид, FR-ADM-05: данные заказов браузер запрашивает сам).
 *
 * Пути захардкожены намеренно: они зафиксированы контрактом openapi,
 * а генерируемые Wayfinder-функции (resources/js/routes) — gitignored
 * артефакт vite dev/build, зависимости от него в коде быть не должно
 * (решение — DECISIONS-log.md, «Задача 013»).
 */
import { ApiError, requestJson } from '@/lib/api/client';
import { isValidAmount } from '@/lib/money';
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

/** База админ-списка: сюда указывают ссылки фильтров/пагинации (FR-ADM-01). */
const ADMIN_ORDERS_URL = '/admin/orders';

/** Дефолт страницы (зеркало ListOrdersRequest::DEFAULT_PAGE). */
export const DEFAULT_PAGE = 1;

/** Потолок per_page контракта (FR-API-03; зеркало правила ListOrdersRequest). */
export const MAX_PER_PAGE = 100;

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

function positiveInt(value: string | null, max?: number): number | undefined {
    if (value === null || !/^\d+$/.test(value)) {
        return undefined;
    }

    const parsed = Number.parseInt(value, 10);

    if (parsed < 1) {
        return undefined;
    }

    return max === undefined || parsed <= max ? parsed : undefined;
}

/**
 * Маппинг query-string адреса админки (/admin/orders?…) → параметры
 * API (FR-ADM-01: состояние фильтров и страницы хранится в URL,
 * ссылки можно копировать). Читает полный набор параметров запроса
 * списка: status, email, page, per_page (T-039).
 *
 * Неверные значения отбрасываются: статус вне enum, нечисловая/нулевая
 * страница и per_page вне 1..100 не тянут за собой 422 от API при
 * первом же заходе — пользователь получает список с чистыми
 * параметрами. Потолок page — MAX_SAFE_INTEGER: длинные числовые
 * строки (за пределами целого Laravel) иначе проходят санитизацию
 * и дают безвыходный 422 «must be an integer» (находка ревью T-039).
 * Владелец правил остаётся сервер (контракт): свободный email из URL
 * валидируется именно API, и его 422 показывается клиентским
 * состоянием ошибки (см. useOrdersList / errors.ts).
 */
export function orderListParamsFromSearch(
    search: string,
): OrderListQueryParams {
    const urlSearch = new URLSearchParams(search);
    const status = urlSearch.get('status');
    const email = urlSearch.get('email')?.trim();

    return {
        status: isOrderStatus(status) ? status : undefined,
        email: email ? email : undefined,
        page: positiveInt(urlSearch.get('page'), Number.MAX_SAFE_INTEGER),
        perPage: positiveInt(urlSearch.get('per_page'), MAX_PER_PAGE),
    };
}

/**
 * Собирает URL админ-списка `/admin/orders?status=…&page=N` — цель
 * ссылок пагинации и адрес, заменяемый в history при изменении
 * фильтров/страницы (Q3-гибрид: навигация по этим ссылкам остаётся
 * на админке, данные грузит клиент; links из ответа API ведут на
 * /api/orders и не используются). Канонизация общая с запросом API
 * (orderListSearchParams): пустые и «едва дефолтные» значения
 * (page=1) в URL не попадают — адрес остаётся копируемым и чистым.
 */
export function adminOrdersUrl(params: OrderListQueryParams): string {
    const search = orderListSearchParams(params).toString();

    return search ? `${ADMIN_ORDERS_URL}?${search}` : ADMIN_ORDERS_URL;
}

/**
 * Собирает URL страницы админ-карточки `/admin/orders/{id}`; id
 * экранируется. Не путать с orderUrl — адресом API-метода контракта.
 */
export function adminOrderUrl(id: string): string {
    return `${ADMIN_ORDERS_URL}/${encodeURIComponent(id)}`;
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
 *
 * Суммы проверяются схемой Amount на границе транспортного слоя
 * (ревью T-041, P3-1): карточка считает по ним клиентской
 * арифметикой (money.ts), и битый формат честнее показать состоянием
 * ошибки запроса с «Повторить», чем уронить рендер страницы.
 */
export async function fetchOrder(id: string): Promise<Order> {
    const response = await requestJson<OrderResponse>(orderUrl(id));
    const order = response.data;
    const amounts = [
        order.total,
        order.delivery_price,
        order.discount,
        ...order.items.flatMap((item) => [item.price, item.subtotal]),
    ];

    if (!amounts.every(isValidAmount)) {
        throw new ApiError('parse', 'Некорректные суммы в ответе API');
    }

    return order;
}
