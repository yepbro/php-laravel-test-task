/**
 * Composable списка заказов (T-039, Q3-гибрид): состояние
 * (фильтры/страница/размер страницы) + загрузка данных клиентским
 * запросом GET /api/orders + синхронизация состояния с query-string
 * адреса /admin/orders (FR-ADM-01: ссылки можно копировать) + защита
 * от гонок запросов (актуален только последний).
 *
 * Источник истины — query-string админ-URL: первый рендер читает его,
 * каждое изменение фильтра/страницы заменяет URL через
 * history.replaceState — без серверного визита Inertia и без
 * перезагрузки (выбор механизма — DECISIONS-log.md, «Задача 039») — и
 * перезагружает данные. Inertia-навигация (ссылка шапки, back/forward,
 * перезагрузка) в v3 перемонтирует страницу (preserveState по
 * умолчанию false) — setup снова читает URL; защитный watch по
 * usePage().url покрывает и сценарий сохранения компонента.
 */
import type { ApiError } from '@/lib/api/client';
import { toApiError } from '@/lib/api/client';
import type {
    OrderListFilterValues,
    OrderListQueryParams,
} from '@/lib/api/orders';
import {
    DEFAULT_PAGE,
    adminOrdersUrl,
    fetchOrders,
    orderListParamsFromSearch,
} from '@/lib/api/orders';
import type { OrderListItem, PaginationMeta } from '@/types';
import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onMounted, ref, watch } from 'vue';

export interface UseOrdersListOptions {
    /**
     * Серверный дефолт размера страницы (props defaultPerPage ←
     * ListOrdersRequest::DEFAULT_PER_PAGE): per_page попадает в URL
     * только при отличии от него.
     */
    defaultPerPage: number;
}

export interface UseOrdersListReturn {
    orders: Ref<OrderListItem[] | null>;
    meta: Ref<PaginationMeta | null>;
    loading: Ref<boolean>;
    error: Ref<ApiError | null>;
    filters: Ref<OrderListFilterValues>;
    applyFilters: (next: OrderListFilterValues) => void;
    goToPage: (next: number) => void;
    reload: () => void;
    /** Копируемый href ссылки страницы N (пагинация по meta, FR-ADM-01). */
    pageUrl: (next: number) => string;
}

/** Служебное состояние, которое Inertia хранит в history.state. */
interface HistoryState {
    page?: unknown;
    [key: string]: unknown;
}

/**
 * Заменяет URL адреса, сохраняя служебное состояние Inertia:
 * `history.state.page` — её «память» для back/forward и восстановления.
 * Восстановимый page.url заодно актуализируется, чтобы Inertia не
 * «вспоминала» старый адрес без фильтров; шифрованное page
 * (ArrayBuffer, encryptHistory) пропускается как есть — в проекте
 * шифрование истории не включено.
 */
function replaceHistoryUrl(url: string): void {
    const state = window.history.state as HistoryState | null;
    const pageState = state?.page;
    const nextPageState =
        typeof pageState === 'object' &&
        pageState !== null &&
        'url' in pageState
            ? { ...pageState, url }
            : pageState;

    window.history.replaceState(
        state === null ? null : { ...state, page: nextPageState },
        '',
        url,
    );
}

export function useOrdersList(
    options: UseOrdersListOptions,
): UseOrdersListReturn {
    const inertiaPage = usePage();

    const initial = orderListParamsFromSearch(window.location.search);

    const orders: Ref<OrderListItem[] | null> = ref(null);
    const meta: Ref<PaginationMeta | null> = ref(null);
    const loading = ref(false);
    const error: Ref<ApiError | null> = ref(null);
    const filters: Ref<OrderListFilterValues> = ref({
        status: initial.status,
        email: initial.email,
    });
    const page = ref(initial.page ?? DEFAULT_PAGE);
    const perPage = ref(initial.perPage ?? options.defaultPerPage);

    /** Счётчик гонок: принимается ответ только последнего запроса. */
    let requestSeq = 0;

    function currentParams(): OrderListQueryParams {
        return {
            ...filters.value,
            page: page.value,
            perPage: perPage.value,
        };
    }

    async function load(params: OrderListQueryParams): Promise<void> {
        const seq = ++requestSeq;

        loading.value = true;
        error.value = null;

        try {
            const response = await fetchOrders(params);

            if (seq !== requestSeq) {
                return;
            }

            orders.value = response.data;
            meta.value = response.meta;
        } catch (cause) {
            if (seq !== requestSeq) {
                return;
            }

            error.value = toApiError(cause);
        } finally {
            if (seq === requestSeq) {
                loading.value = false;
            }
        }
    }

    /** Параметры для URL: канонические (дефолтный per_page опускается). */
    function canonicalParams(
        overrides?: Partial<OrderListQueryParams>,
    ): OrderListQueryParams {
        return {
            ...filters.value,
            page: page.value,
            perPage:
                perPage.value === options.defaultPerPage
                    ? undefined
                    : perPage.value,
            ...overrides,
        };
    }

    function replaceUrl(): void {
        replaceHistoryUrl(adminOrdersUrl(canonicalParams()));
    }

    function applyFilters(next: OrderListFilterValues): void {
        filters.value = {
            status: next.status,
            email: next.email?.trim() || undefined,
        };
        page.value = DEFAULT_PAGE;
        replaceUrl();
        void load(currentParams());
    }

    function goToPage(next: number): void {
        page.value = next;
        replaceUrl();
        void load(currentParams());
    }

    function reload(): void {
        void load(currentParams());
    }

    function pageUrl(next: number): string {
        return adminOrdersUrl(canonicalParams({ page: next }));
    }

    onMounted(() => {
        void load(currentParams());
    });

    // Защита от рассинхрона при Inertia-навигации с сохранением
    // компонента: адрес изменился — состояние перечитывается из URL.
    watch(
        () => inertiaPage.url,
        () => {
            const params = orderListParamsFromSearch(window.location.search);

            filters.value = { status: params.status, email: params.email };
            page.value = params.page ?? DEFAULT_PAGE;
            perPage.value = params.perPage ?? options.defaultPerPage;
            void load(currentParams());
        },
    );

    return {
        orders,
        meta,
        loading,
        error,
        filters,
        applyFilters,
        goToPage,
        reload,
        pageUrl,
    };
}
