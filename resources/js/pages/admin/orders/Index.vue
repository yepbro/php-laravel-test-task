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
import { adminOrderUrl } from '@/lib/api/orders';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { AdminOrdersIndexProps } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Страница списка заказов (FR-ADM-01/FR-ADM-04, Q3-гибрид): Inertia
 * отдаёт только вспомогательные props (statusOptions, defaultPerPage),
 * данные заказов приходят клиентским запросом GET /api/orders —
 * состояние фильтров и страницы живёт в query-string адреса и
 * синхронизируется composable'ом (копируемые ссылки, защита от гонок,
 * 422/сеть — состояния ошибки; слой данных — T-039).
 *
 * Полный UI списка (T-040): таблица на десктопе и карточки на мобиле,
 * три пустых состояния — «заказов нет», «ничего не найдено» и «страница
 * за пределами списка» (перенос ревью T-039: пустая страница от API при
 * page > last_page), скелетон первичной загрузки и затемнение при
 * фоновых перезагрузках. Даты — в часовом поясе браузера, суммы —
 * Intl.NumberFormat ru-RU по валюте заказа (lib/format).
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

/**
 * «Страница за пределами списка» (S-24 от API: page > last_page —
 * 200 с пустым data). null — состояние не активно; вынесено в computed,
 * чтобы шаблон работал с конкретными числами без null-развилок.
 */
const beyondLastPage = computed(() => {
    const pageMeta = meta.value;

    if (pageMeta === null || orders.value === null) {
        return null;
    }

    if (pageMeta.current_page <= pageMeta.last_page) {
        return null;
    }

    return { current: pageMeta.current_page, last: pageMeta.last_page };
});

/** Сброс обоих фильтров (канонически выпадают из query-string). */
function resetFilters(): void {
    applyFilters({ status: undefined, email: undefined });
}
</script>

<template>
    <Head title="Заказы" />

    <AdminLayout>
        <div class="flex flex-col gap-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-semibold">Заказы</h1>
                <!-- Индикатор фоновой перезагрузки: старые данные видны. -->
                <span
                    v-if="loading && !isInitialLoading"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner class="size-4" aria-hidden="true" />
                    Обновление…
                </span>
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
                <span class="sr-only">Загрузка списка заказов…</span>
                <Skeleton class="h-10 w-full" />
                <Skeleton v-for="row in 6" :key="row" class="h-14 w-full" />
            </div>

            <EmptyState
                v-else-if="beyondLastPage !== null"
                title="Страница за пределами списка"
                :description="`Страницы ${beyondLastPage.current} не существует: всего страниц — ${beyondLastPage.last}. Возможно, список заказов изменился.`"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="loading"
                    @click="goToPage(1)"
                >
                    Перейти на первую страницу
                </Button>
            </EmptyState>

            <EmptyState
                v-else-if="orders !== null && orders.length === 0"
                :title="hasFilters ? 'Ничего не найдено' : 'Заказов нет'"
                :description="
                    hasFilters
                        ? 'По заданным условиям заказы не найдены. Попробуйте изменить или сбросить фильтры.'
                        : 'Заказы появятся после первых событий маркетплейса.'
                "
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    size="sm"
                    :disabled="loading"
                    @click="resetFilters"
                >
                    Сбросить фильтры
                </Button>
            </EmptyState>

            <div
                v-else-if="orders !== null"
                class="flex flex-col gap-6"
                :aria-busy="loading"
            >
                <div
                    class="flex flex-col gap-6 transition-opacity"
                    :class="loading ? 'opacity-60' : 'opacity-100'"
                >
                    <!-- Десктоп: семантическая таблица (от sm). -->
                    <div
                        class="hidden overflow-x-auto rounded-lg border sm:block"
                    >
                        <table class="w-full text-sm">
                            <caption class="sr-only">
                                Список заказов маркетплейса
                            </caption>
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
                                    class="border-t transition-colors hover:bg-accent/50"
                                >
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <Link
                                            :href="adminOrderUrl(order.id)"
                                            class="font-medium underline-offset-4 hover:text-primary hover:underline focus-visible:rounded-xs focus-visible:ring-[3px] focus-visible:ring-ring"
                                        >
                                            {{ order.number }}
                                        </Link>
                                    </td>
                                    <td class="px-4 py-2">
                                        <StatusBadge :status="order.status" />
                                    </td>
                                    <td class="px-4 py-2">
                                        <div class="flex flex-col">
                                            <span>{{
                                                order.customer.name
                                            }}</span>
                                            <span
                                                class="break-all text-muted-foreground"
                                            >
                                                {{ order.customer.email }}
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            formatMoney(
                                                order.total,
                                                order.currency,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-left whitespace-nowrap text-muted-foreground"
                                    >
                                        {{ formatDateTime(order.created_at) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Мобайл (до sm): карточки с видимыми подписями полей. -->
                    <ul class="flex flex-col gap-3 sm:hidden">
                        <li
                            v-for="order in orders"
                            :key="order.id"
                            class="flex flex-col gap-3 rounded-lg border p-4"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <Link
                                    :href="adminOrderUrl(order.id)"
                                    class="font-medium underline-offset-4 hover:text-primary hover:underline focus-visible:rounded-xs focus-visible:ring-[3px] focus-visible:ring-ring"
                                >
                                    {{ order.number }}
                                </Link>
                                <StatusBadge :status="order.status" />
                            </div>
                            <div class="flex flex-col">
                                <span>{{ order.customer.name }}</span>
                                <span
                                    class="text-sm break-all text-muted-foreground"
                                >
                                    {{ order.customer.email }}
                                </span>
                            </div>
                            <div
                                class="grid grid-cols-2 items-start gap-2 text-sm"
                            >
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-xs text-muted-foreground">
                                        Сумма
                                    </span>
                                    <span class="font-medium tabular-nums">
                                        {{
                                            formatMoney(
                                                order.total,
                                                order.currency,
                                            )
                                        }}
                                    </span>
                                </div>
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-xs text-muted-foreground">
                                        Оформлен
                                    </span>
                                    <span>
                                        {{ formatDateTime(order.created_at) }}
                                    </span>
                                </div>
                            </div>
                        </li>
                    </ul>
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
