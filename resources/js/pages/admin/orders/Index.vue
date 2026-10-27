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
import { formatDateTime } from '@/lib/format';
import type { AdminOrdersIndexProps } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Страница списка заказов (Q3-гибрид, T-039): Inertia отдаёт только
 * вспомогательные props (statusOptions, defaultPerPage), данные
 * заказов приходят клиентским запросом GET /api/orders. Состояние
 * фильтров и страницы живёт в query-string адреса и синхронизируется
 * composable'ом (копируемые ссылки, защита от гонок, 422/сеть —
 * состояния ошибки). Вёрстка таблицы остаётся минимальной — полный
 * UI списка — T-040.
 */
const props = defineProps<AdminOrdersIndexProps>();

const {
    orders,
    meta,
    loading,
    error,
    filters,
    applyFilters,
    goToPage,
    reload,
    pageUrl,
} = useOrdersList({ defaultPerPage: props.defaultPerPage });

const hasFilters = computed(
    () =>
        filters.value.status !== undefined || filters.value.email !== undefined,
);

const isInitialLoading = computed(() => loading.value && orders.value === null);
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
                        @click="reload"
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
                    :url-for="pageUrl"
                    :disabled="loading"
                    @change="goToPage"
                />
            </div>
        </div>
    </AdminLayout>
</template>
