/**
 * Composable карточки заказа (T-013): состояние + загрузка данных
 * клиентским запросом GET /api/orders/{id} (Q3-гибрид). 404 от API —
 * клиентское состояние «заказ не найден» (FR-ADM-04), а не серверная
 * страница ошибки. Полный UI карточки — T-041.
 */
import { toApiError } from '@/composables/useOrdersList';
import type { ApiError } from '@/lib/api/client';
import { fetchOrder } from '@/lib/api/orders';
import type { Order } from '@/types';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';

export interface UseOrderReturn {
    order: Ref<Order | null>;
    loading: Ref<boolean>;
    error: Ref<ApiError | null>;
    notFound: ComputedRef<boolean>;
    load: (id: string) => Promise<void>;
}

export function useOrder(): UseOrderReturn {
    const order: Ref<Order | null> = ref(null);
    const loading = ref(false);
    const error: Ref<ApiError | null> = ref(null);

    const notFound = computed(() => error.value?.kind === 'not-found');

    async function load(id: string): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            order.value = await fetchOrder(id);
        } catch (cause) {
            order.value = null;
            error.value = toApiError(cause);
        } finally {
            loading.value = false;
        }
    }

    return { order, loading, error, notFound, load };
}
