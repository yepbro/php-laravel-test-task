/**
 * TS-типы по схемам контракта task/openapi.yaml (OpenAPI 3.1).
 * Источник: components/schemas — OrderListItem, Order, PaginationLinks,
 * PaginationMeta, OrderStatus, Amount. Контракт не меняется (T-013):
 * поля честные, включая nullable; «дополнительные поля добавлять
 * можно» из описания контракта на типы не распространяется — здесь
 * только объявленное.
 */

/** Схема OrderStatus: enum статусов заказа. */
export const ORDER_STATUSES = [
    'created',
    'paid',
    'shipped',
    'delivered',
    'cancelled',
] as const;

export type OrderStatus = (typeof ORDER_STATUSES)[number];

/** Type guard для значений статуса из API/URL. */
export function isOrderStatus(value: unknown): value is OrderStatus {
    return (
        typeof value === 'string' &&
        (ORDER_STATUSES as readonly string[]).includes(value)
    );
}

/**
 * Схема Amount: строка с двумя знаками после точки, например
 * `"1499.90"`. Шаблонный тип с произвольным числом разрядов в TS
 * невыразим — ограничиваемся строкой (деньги остаются строкой,
 * без float).
 */
export type Amount = string;

/** Схема OrderListItem.customer: покупатель в элементе списка. */
export interface OrderListItemCustomer {
    email: string;
    name: string;
}

/** Схема OrderListItem: элемент списка GET /api/orders. */
export interface OrderListItem {
    id: string;
    number: string;
    status: OrderStatus;
    customer: OrderListItemCustomer;
    total: Amount;
    currency: string;
    /** Дата оформления (ISO 8601 с offset). */
    created_at: string;
    /** Дата последнего обновления у маркетплейса (ISO 8601). */
    updated_at: string;
}

/** Схема Order.customer: покупатель в карточке (phone nullable). */
export interface OrderCustomer {
    email: string;
    name: string;
    phone: string | null;
}

/** Схема Order.items[]: позиция заказа. */
export interface OrderItem {
    sku: string;
    name: string;
    quantity: number;
    price: Amount;
    /** `price × quantity` — уже вычислено API. */
    subtotal: Amount;
}

/** Схема Order.shipment: null, пока заказ не передан в доставку. */
export interface OrderShipment {
    carrier: string;
    tracking_number: string;
    tracking_url: string;
}

/** Схема Order.history[]: факт статуса с моментом перехода. */
export interface OrderHistoryEntry {
    status: OrderStatus;
    at: string;
}

/** Схема Order: карточка GET /api/orders/{id}. */
export interface Order {
    id: string;
    number: string;
    status: OrderStatus;
    customer: OrderCustomer;
    currency: string;
    items: OrderItem[];
    delivery_price: Amount;
    discount: Amount;
    total: Amount;
    shipment: OrderShipment | null;
    paid_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    created_at: string;
    updated_at: string;
    /** Хронология статусов по данным маркетплейса. */
    history: OrderHistoryEntry[];
}

/** Схема PaginationLinks: first/last/prev/next, все nullable. */
export interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

/** Схема PaginationMeta: метаданные пагинации. */
export interface PaginationMeta {
    current_page: number;
    /**
     * Не входят в required контракта; стандартный пагинатор Laravel
     * присылает их всегда — тип отражает контракт, а не эмпирику.
     */
    from?: number | null;
    last_page: number;
    path?: string;
    per_page: number;
    to?: number | null;
    total: number;
}

/**
 * Обёртка ответа GET /api/orders: стандартный формат пагинированной
 * коллекции API Resources в Laravel. `links` ведут на /api/orders и для
 * навигации админки не используются (Q3-гибрид) — переходы строятся
 * по `meta` со ссылками на /admin/orders.
 */
export interface PaginatedOrders {
    data: OrderListItem[];
    links: PaginationLinks;
    meta: PaginationMeta;
}

/**
 * Обёртка ответа GET /api/orders/{id} (схема getOrder 200: объект
 * `required: [data]`) — JsonResource Laravel заворачивает единичный
 * ресурс в `data`, клиентский слой разворачивает её в `Order`.
 */
export interface OrderResponse {
    data: Order;
}
