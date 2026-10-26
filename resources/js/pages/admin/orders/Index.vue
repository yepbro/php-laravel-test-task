<script setup lang="ts">
import EmptyState from '@/components/admin/EmptyState.vue';
import OrderFilters from '@/components/admin/OrderFilters.vue';
import OrderPagination from '@/components/admin/OrderPagination.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useOrdersList } from '@/composables/useOrdersList';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    apiErrorDescription,
    apiErrorFields,
    apiErrorTitle,
} from '@/lib/api/errors';
import type {
    OrderListFilterValues,
    OrderListQueryParams,
} from '@/lib/api/orders';
import { orderListParamsFromSearch } from '@/lib/api/orders';
import { formatDateTime } from '@/lib/format';
import type { AdminOrdersIndexProps } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';

/**
 * Страница-оболочка списка заказов (T-013, Q3-гибрид): Inertia отдаёт
 * только вспомогательные props (statusOptions, defaultPerPage), данные
 * заказов приходят клиентским запросом GET /api/orders. Полный UI
 * (рабочие фильтры с query-string, богатая таблица, ссылки пагинации) —
 * T-039/T-040; здесь честный минимальный запрос и отображение.
 */
const props = defineProps<AdminOrdersIndexProps>();

const { orders, meta, loading, error, load } = useOrdersList();

// Начальное состояние — из query-string адреса (FR-ADM-01: состояние
// фильтров и страницы — в URL, ссылки можно копировать). per_page из URL
// не читаем: пользовательский размер страницы в ТЗ не описан (в URL —
// только status/email/page), берём серверный дефолт.
const initialParams = orderListParamsFromSearch(window.location.search);

const filters: Ref<OrderListFilterValues> = ref({
    status: initialParams.status,
    email: initialParams.email,
});
const page = ref(initialParams.page ?? 1);
const perPage = props.defaultPerPage;

const hasFilters = computed(
    () =>
        filters.value.status !== undefined || filters.value.email !== undefined,
);

const queryParams = computed<OrderListQueryParams>(() => ({
    ...filters.value,
    page: page.value,
    perPage,
}));

const isInitialLoading = computed(() => loading.value && orders.value === null);

onMounted(() => {
    void load(queryParams.value);
});

function applyFilters(next: OrderListFilterValues): void {
    filters.value = next;
    page.value = 1;
    void load(queryParams.value);
}

function goToPage(next: number): void {
    page.value = next;
    void load(queryParams.value);
}
</script>

<template>
    <Head title="Заказы" />

    <AdminLayout>
        <div class="flex flex-col gap-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-semibold">Заказы</h1>
                <Spinner v-if="loading && !isInitialLoading" class="size-5" />
            </div>

            <OrderFilters
                :model-value="filters"
                :status-options="statusOptions"
                @update:model-value="applyFilters"
            />

            <Alert v-if="error" variant="destructive">
                <AlertTitle>{{ apiErrorTitle(error) }}</AlertTitle>
                <AlertDescription class="flex flex-col gap-2">
                    <span>{{ apiErrorDescription(error) }}</span>
                    <ul
                        v-if="apiErrorFields(error).length > 0"
                        class="list-disc pl-4"
                    >
                        <li v-for="field in apiErrorFields(error)" :key="field">
                            {{ field }}
                        </li>
                    </ul>
                    <Button
                        variant="outline"
                        size="sm"
                        class="w-fit"
                        :disabled="loading"
                        @click="load(queryParams)"
                    >
                        Повторить
                    </Button>
                </AlertDescription>
            </Alert>

            <div
                v-else-if="isInitialLoading"
                class="flex flex-col gap-3"
                role="status"
                aria-label="Загрузка списка заказов"
            >
                <Skeleton v-for="row in 5" :key="row" class="h-12 w-full" />
            </div>

            <EmptyState
                v-else-if="orders !== null && orders.length === 0"
                :title="hasFilters ? 'Ничего не найдено' : 'Заказов нет'"
                :description="
                    hasFilters
                        ? 'Попробуйте изменить параметры фильтра.'
                        : 'Заказы появятся после первых событий маркетплейса.'
                "
            />

            <div v-else-if="orders !== null" class="flex flex-col gap-6">
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-left font-medium"
                                >
                                    Номер
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-left font-medium"
                                >
                                    Статус
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-left font-medium"
                                >
                                    Покупатель
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-right font-medium"
                                >
                                    Сумма
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-2 text-left font-medium"
                                >
                                    Оформлен
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="order in orders"
                                :key="order.id"
                                class="border-t"
                            >
                                <td class="px-4 py-2">
                                    <Link
                                        :href="`/admin/orders/${order.id}`"
                                        class="font-medium underline-offset-4 hover:text-primary hover:underline"
                                    >
                                        {{ order.number }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2">
                                    <StatusBadge :status="order.status" />
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-col">
                                        <span>{{ order.customer.name }}</span>
                                        <span class="text-muted-foreground">
                                            {{ order.customer.email }}
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-2 text-right whitespace-nowrap"
                                >
                                    {{ order.total }} {{ order.currency }}
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ formatDateTime(order.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <OrderPagination
                    v-if="meta !== null"
                    :meta="meta"
                    :disabled="loading"
                    @change="goToPage"
                />
            </div>
        </div>
    </AdminLayout>
</template>
