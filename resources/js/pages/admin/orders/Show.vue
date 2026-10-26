<script setup lang="ts">
import EmptyState from '@/components/admin/EmptyState.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useOrder } from '@/composables/useOrder';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    apiErrorDescription,
    apiErrorFields,
    apiErrorTitle,
} from '@/lib/api/errors';
import { formatDateTime } from '@/lib/format';
import type { AdminOrderShowProps } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

/**
 * Страница-оболочка карточки заказа (T-013, Q3-гибрид): Inertia даёт
 * только id из URL, данные приходят клиентским запросом
 * GET /api/orders/{id}; 404 — клиентское состояние «заказ не найден»
 * (FR-ADM-04). Полная карточка (богатый таймлайн истории, блок писем) —
 * T-041; здесь честное минимальное отображение всех секций FR-ADM-02.
 */
const props = defineProps<AdminOrderShowProps>();

const { order, loading, error, notFound, load } = useOrder();

const pageTitle = computed(() =>
    order.value === null ? 'Заказ' : `Заказ №${order.value.number}`,
);

onMounted(() => {
    void load(props.id);
});
</script>

<template>
    <Head :title="pageTitle" />

    <AdminLayout>
        <div class="flex flex-col gap-6">
            <div>
                <Link
                    href="/admin/orders"
                    class="text-sm text-muted-foreground underline-offset-4 hover:text-primary hover:underline"
                >
                    ← К списку заказов
                </Link>
            </div>

            <div
                v-if="loading"
                class="flex flex-col gap-3"
                role="status"
                aria-label="Загрузка заказа"
            >
                <Skeleton class="h-8 w-64" />
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-40 w-full" />
            </div>

            <EmptyState
                v-else-if="notFound"
                title="Заказ не найден"
                description="Возможно, заказ удалён или ссылка неверна."
            >
                <Button as-child variant="outline" size="sm" class="mt-2">
                    <Link href="/admin/orders">К списку заказов</Link>
                </Button>
            </EmptyState>

            <Alert v-else-if="error" variant="destructive">
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
                        @click="load(props.id)"
                    >
                        Повторить
                    </Button>
                </AlertDescription>
            </Alert>

            <div v-else-if="order !== null" class="flex flex-col gap-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold">
                        Заказ №{{ order.number }}
                    </h1>
                    <StatusBadge :status="order.status" />
                    <span class="text-sm text-muted-foreground">
                        {{ order.id }} · оформлен
                        {{ formatDateTime(order.created_at) }}
                    </span>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Покупатель</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-1 text-sm">
                        <span>{{ order.customer.name }}</span>
                        <span class="text-muted-foreground">
                            {{ order.customer.email }}
                        </span>
                        <span class="text-muted-foreground">
                            {{ order.customer.phone ?? 'Телефон не указан' }}
                        </span>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Позиции</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-muted-foreground">
                                    <tr>
                                        <th
                                            scope="col"
                                            class="px-2 py-1 text-left font-medium"
                                        >
                                            SKU
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-2 py-1 text-left font-medium"
                                        >
                                            Название
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-2 py-1 text-right font-medium"
                                        >
                                            Кол-во
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-2 py-1 text-right font-medium"
                                        >
                                            Цена
                                        </th>
                                        <th
                                            scope="col"
                                            class="px-2 py-1 text-right font-medium"
                                        >
                                            Стоимость
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in order.items"
                                        :key="item.sku"
                                        class="border-t"
                                    >
                                        <td class="px-2 py-1 whitespace-nowrap">
                                            {{ item.sku }}
                                        </td>
                                        <td class="px-2 py-1">
                                            {{ item.name }}
                                        </td>
                                        <td class="px-2 py-1 text-right">
                                            {{ item.quantity }}
                                        </td>
                                        <td
                                            class="px-2 py-1 text-right whitespace-nowrap"
                                        >
                                            {{ item.price }}
                                        </td>
                                        <td
                                            class="px-2 py-1 text-right whitespace-nowrap"
                                        >
                                            {{ item.subtotal }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <dl class="flex flex-col gap-1 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Доставка</dt>
                                <dd>{{ order.delivery_price }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Скидка</dt>
                                <dd>{{ order.discount }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 font-medium">
                                <dt>Итого</dt>
                                <dd>{{ order.total }} {{ order.currency }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <div class="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Отправка</CardTitle>
                        </CardHeader>
                        <CardContent
                            class="flex flex-col gap-1 text-sm text-muted-foreground"
                        >
                            <template v-if="order.shipment !== null">
                                <span class="text-foreground">
                                    {{ order.shipment.carrier }}
                                </span>
                                <span>
                                    Трек-номер:
                                    {{ order.shipment.tracking_number }}
                                </span>
                                <a
                                    :href="order.shipment.tracking_url"
                                    class="text-primary underline-offset-4 hover:underline"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Отследить посылку
                                </a>
                            </template>
                            <span v-else>
                                Заказ ещё не передан в доставку.
                            </span>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Даты и история</CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-3 text-sm">
                            <dl class="flex flex-col gap-1">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        Оплачен
                                    </dt>
                                    <dd>
                                        {{
                                            order.paid_at
                                                ? formatDateTime(order.paid_at)
                                                : '—'
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-muted-foreground">
                                        Отменён
                                    </dt>
                                    <dd>
                                        {{
                                            order.cancelled_at
                                                ? formatDateTime(
                                                      order.cancelled_at,
                                                  )
                                                : '—'
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <p
                                v-if="order.cancelled_at !== null"
                                class="text-muted-foreground"
                            >
                                Причина:
                                {{ order.cancel_reason ?? 'не указана' }}
                            </p>

                            <!-- T-041: полноценный таймлайн истории -->
                            <ol class="flex flex-col gap-1">
                                <li
                                    v-for="entry in order.history"
                                    :key="`${entry.status}-${entry.at}`"
                                    class="flex items-center gap-2"
                                >
                                    <StatusBadge :status="entry.status" />
                                    <span class="text-muted-foreground">
                                        {{ formatDateTime(entry.at) }}
                                    </span>
                                </li>
                            </ol>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
