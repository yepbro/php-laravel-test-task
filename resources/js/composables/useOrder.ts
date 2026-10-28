/**
 * Composable карточки заказа (T-013/T-041, Q3-гибрид): состояние +
 * загрузка данных клиентским запросом GET /api/orders/{id}. 404 от API —
 * клиентское состояние «заказ не найден» (FR-ADM-04), а не серверная
 * страница ошибки.
 *
 * Гонки закрыты sequence-счётчиком по образцу T-039: актуален только
 * последний запрос (кнопка «Повторить» при ошибке сети, быстрые
 * переходы) — устаревшие ответы/ошибки отбрасываются, `loading`
 * гасится только актуальным.
 */
import type { ApiError } from '@/lib/api/client';
import { toApiError } from '@/lib/api/client';
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

    /** Счётчик гонок: принимается ответ только последнего запроса. */
    let requestSeq = 0;

    async function load(id: string): Promise<void> {
        const seq = ++requestSeq;

        loading.value = true;
        error.value = null;

        try {
            const fetched = await fetchOrder(id);

            if (seq !== requestSeq) {
                return;
            }

            order.value = fetched;
        } catch (cause) {
            if (seq !== requestSeq) {
                return;
            }

            order.value = null;
            error.value = toApiError(cause);
        } finally {
            if (seq === requestSeq) {
                loading.value = false;
            }
        }
    }

    return { order, loading, error, notFound, load };
}
