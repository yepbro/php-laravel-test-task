/**
 * Composable списка заказов (T-013): состояние + загрузка данных
 * клиентским запросом GET /api/orders (Q3-гибрид). Полная
 * синхронизация с query-string адреса и защита от гонок запросов
 * (последний запрос выигрывает / отмена устаревших) — T-039.
 */
import { ApiError } from '@/lib/api/client';
import type { OrderListQueryParams } from '@/lib/api/orders';
import { fetchOrders } from '@/lib/api/orders';
import type { OrderListItem, PaginationMeta } from '@/types';
import type { Ref } from 'vue';
import { ref } from 'vue';

export interface UseOrdersListReturn {
    orders: Ref<OrderListItem[] | null>;
    meta: Ref<PaginationMeta | null>;
    loading: Ref<boolean>;
    error: Ref<ApiError | null>;
    load: (params: OrderListQueryParams) => Promise<void>;
}

/** Приводит любое исключение к ApiError для единообразной обработки. */
export function toApiError(cause: unknown): ApiError {
    if (cause instanceof ApiError) {
        return cause;
    }

    return new ApiError('http', 'Неизвестная ошибка запроса', { cause });
}

export function useOrdersList(): UseOrdersListReturn {
    const orders: Ref<OrderListItem[] | null> = ref(null);
    const meta: Ref<PaginationMeta | null> = ref(null);
    const loading = ref(false);
    const error: Ref<ApiError | null> = ref(null);

    async function load(params: OrderListQueryParams): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            const response = await fetchOrders(params);
            orders.value = response.data;
            meta.value = response.meta;
        } catch (cause) {
            error.value = toApiError(cause);
        } finally {
            loading.value = false;
        }
    }

    return { orders, meta, loading, error, load };
}
