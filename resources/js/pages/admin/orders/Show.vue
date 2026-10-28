<script setup lang="ts">
import EmptyState from '@/components/admin/EmptyState.vue';
import OrderHistoryTimeline from '@/components/admin/OrderHistoryTimeline.vue';
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
import { adminOrdersUrl } from '@/lib/api/orders';
import { formatDateTime, formatMoney } from '@/lib/format';
import { sumAmounts } from '@/lib/money';
import type { AdminOrderShowProps } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, watch } from 'vue';

/**
 * Страница карточки заказа (FR-ADM-02/FR-ADM-04, Q3-гибрид): Inertia
 * отдаёт только id из URL, данные приходят клиентским запросом
 * GET /api/orders/{id}; 404 — клиентское состояние «заказ не найден»
 * (FR-ADM-04). Приоритет состояний — по образцу списка (T-040):
 * ошибка сети/сервера → загрузка → «не найден» → данные.
 *
 * Полный UI (T-041): шапка с номером и статусом, покупатель, позиции
 * (таблица от sm, карточки на мобиле) с разбивкой сумм — сумма позиций
 * считается клиентом из items[].subtotal целыми копейками (lib/money,
 * без float: контрактом итог по позициям не отдаётся), отправка,
 * даты оплаты/отмены с причиной, история статусов таймлайном.
 * Суммы — Intl ru-RU по валюте заказа, даты — в поясе браузера
 * (lib/format). Блок писем (FR-ADM-03, P2) не реализован: уведомлений
 * нет в контракте /api/orders/{id}, а эндпоинты вне контракта
 * запрещены (FR-API-11) — решение в DECISIONS-log.md, задача 041.
 */
const props = defineProps<AdminOrderShowProps>();

const { order, loading, error, notFound, load } = useOrder();

const pageTitle = computed(() => {
    if (order.value !== null) {
        return `Заказ №${order.value.number}`;
    }

    return notFound.value ? 'Заказ не найден' : 'Заказ';
});

/** Сумма позиций для разбивки сумм (FR-ADM-02): «позиции … итого». */
const itemsSubtotal = computed(() => {
    if (order.value === null) {
        return '0.00';
    }

    return sumAmounts(order.value.items.map((item) => item.subtotal));
});

/**
 * Ссылка отслеживания — внешние данные MarketHub: гиперссылкой
 * становится только http(s)-адрес, прочие схемы (например,
 * javascript:) отображаются текстом.
 */
const safeTrackingUrl = computed(() => {
    const shipment = order.value?.shipment;

    if (shipment === undefined || shipment === null) {
        return null;
    }

    return /^https?:\/\//i.test(shipment.tracking_url)
        ? shipment.tracking_url
        : null;
});

const linkClass =
    'rounded-xs underline-offset-4 hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring';

onMounted(() => {
    void load(props.id);
});

// Защитный watch по образцу T-039: если Inertia когда-нибудь сохранит
// компонент при смене адреса (preserveState/prefetch), страница
// перезагрузит данные по новому id — sequence-счётчик useOrder уже
// отбросит гонку со старым запросом (ревью T-041, P2-2).
watch(
    () => props.id,
    (id) => {
        void load(id);
    },
);
</script>

<template>
    <Head :title="pageTitle" />

    <AdminLayout>
        <div class="flex flex-col gap-6">
            <div>
                <Link
                    :href="adminOrdersUrl({})"
                    class="text-sm text-muted-foreground"
                    :class="linkClass"
                >
                    ← К списку заказов
                </Link>
            </div>

            <Alert v-if="error && !notFound" variant="destructive">
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

            <div
                v-else-if="loading"
                class="flex flex-col gap-6"
                role="status"
                aria-label="Загрузка заказа"
            >
                <span class="sr-only">Загрузка заказа…</span>
                <div class="flex flex-col gap-2">
                    <Skeleton class="h-8 w-64" />
                    <Skeleton class="h-4 w-80" />
                </div>
                <Skeleton class="h-40 w-full" />
                <Skeleton class="h-64 w-full" />
                <Skeleton class="h-48 w-full" />
            </div>

            <EmptyState
                v-else-if="notFound"
                title="Заказ не найден"
                description="Возможно, заказ удалён или ссылка неверна."
            >
                <Button as-child variant="outline" size="sm" class="mt-2">
                    <Link :href="adminOrdersUrl({})">К списку заказов</Link>
                </Button>
            </EmptyState>

            <div v-else-if="order !== null" class="flex flex-col gap-6">
                <header class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            Заказ №{{ order.number }}
                        </h1>
                        <StatusBadge :status="order.status" />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ order.id }} · оформлен
                        {{ formatDateTime(order.created_at) }}
                    </p>
                </header>

                <div class="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Покупатель</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl class="flex flex-col gap-2 text-sm">
                                <div
                                    class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                >
                                    <dt class="text-muted-foreground">Имя</dt>
                                    <dd class="font-medium">
                                        {{ order.customer.name }}
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                >
                                    <dt class="text-muted-foreground">Email</dt>
                                    <dd class="break-all">
                                        {{ order.customer.email }}
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                >
                                    <dt class="text-muted-foreground">
                                        Телефон
                                    </dt>
                                    <dd>
                                        {{
                                            order.customer.phone ??
                                            'Телефон не указан'
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Даты и отмена</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl class="flex flex-col gap-2 text-sm">
                                <div
                                    class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                >
                                    <dt class="text-muted-foreground">
                                        Оплачен
                                    </dt>
                                    <dd>
                                        <time
                                            v-if="order.paid_at !== null"
                                            :datetime="order.paid_at"
                                            class="tabular-nums"
                                        >
                                            {{ formatDateTime(order.paid_at) }}
                                        </time>
                                        <template v-else>—</template>
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                >
                                    <dt class="text-muted-foreground">
                                        Отменён
                                    </dt>
                                    <dd>
                                        <time
                                            v-if="order.cancelled_at !== null"
                                            :datetime="order.cancelled_at"
                                            class="tabular-nums"
                                        >
                                            {{
                                                formatDateTime(
                                                    order.cancelled_at,
                                                )
                                            }}
                                        </time>
                                        <template v-else>—</template>
                                    </dd>
                                </div>
                                <div
                                    v-if="order.cancelled_at !== null"
                                    class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                >
                                    <dt class="text-muted-foreground">
                                        Причина отмены
                                    </dt>
                                    <dd>
                                        {{
                                            order.cancel_reason ??
                                            'Причина не указана'
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Позиции и суммы</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p
                            v-if="order.items.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            В заказе нет позиций.
                        </p>
                        <template v-else>
                            <!-- Десктоп (от sm): семантическая таблица. -->
                            <div
                                class="hidden overflow-x-auto rounded-lg border sm:block"
                            >
                                <table class="w-full text-sm">
                                    <caption class="sr-only">
                                        Состав заказа №{{
                                            order.number
                                        }}
                                    </caption>
                                    <thead
                                        class="bg-muted/50 text-muted-foreground"
                                    >
                                        <tr>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 text-left font-medium"
                                            >
                                                SKU
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 text-left font-medium"
                                            >
                                                Название
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 text-right font-medium"
                                            >
                                                Кол-во
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 text-right font-medium"
                                            >
                                                Цена
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 text-right font-medium"
                                            >
                                                Стоимость
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Ключ — индекс: поля-идентификатора
                                             у позиций контракта нет, одинаковые
                                             SKU в заказе допустимы (S-26);
                                             порядок стабилен — API отдаёт по
                                             position (ревью T-041, P2-1). -->
                                        <tr
                                            v-for="(item, index) in order.items"
                                            :key="index"
                                            class="border-t"
                                        >
                                            <td
                                                class="px-4 py-2 font-medium whitespace-nowrap"
                                            >
                                                {{ item.sku }}
                                            </td>
                                            <td class="px-4 py-2">
                                                {{ item.name }}
                                            </td>
                                            <td
                                                class="px-4 py-2 text-right tabular-nums"
                                            >
                                                {{ item.quantity }}
                                            </td>
                                            <td
                                                class="px-4 py-2 text-right whitespace-nowrap tabular-nums"
                                            >
                                                {{
                                                    formatMoney(
                                                        item.price,
                                                        order.currency,
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-4 py-2 text-right whitespace-nowrap tabular-nums"
                                            >
                                                {{
                                                    formatMoney(
                                                        item.subtotal,
                                                        order.currency,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Мобайл (до sm): карточки с подписями полей. -->
                            <ul class="flex flex-col gap-3 sm:hidden">
                                <li
                                    v-for="(item, index) in order.items"
                                    :key="index"
                                    class="flex flex-col gap-2 rounded-lg border p-4"
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <span class="font-medium break-all">
                                            {{ item.sku }}
                                        </span>
                                        <span
                                            class="font-medium whitespace-nowrap tabular-nums"
                                        >
                                            {{
                                                formatMoney(
                                                    item.subtotal,
                                                    order.currency,
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <span class="text-sm">{{ item.name }}</span>
                                    <span
                                        class="text-sm text-muted-foreground tabular-nums"
                                    >
                                        {{ item.quantity }} ×
                                        {{
                                            formatMoney(
                                                item.price,
                                                order.currency,
                                            )
                                        }}
                                    </span>
                                </li>
                            </ul>
                        </template>

                        <dl class="flex flex-col gap-1 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Позиции</dt>
                                <dd class="tabular-nums">
                                    {{
                                        formatMoney(
                                            itemsSubtotal,
                                            order.currency,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Доставка</dt>
                                <dd class="tabular-nums">
                                    {{
                                        formatMoney(
                                            order.delivery_price,
                                            order.currency,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Скидка</dt>
                                <dd class="tabular-nums">
                                    {{
                                        formatMoney(
                                            order.discount,
                                            order.currency,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div
                                class="flex justify-between gap-4 border-t pt-2 text-base font-medium"
                            >
                                <dt>Итого</dt>
                                <dd class="tabular-nums">
                                    {{
                                        formatMoney(order.total, order.currency)
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <div class="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Отправка</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <template v-if="order.shipment !== null">
                                <dl class="flex flex-col gap-2 text-sm">
                                    <div
                                        class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                    >
                                        <dt class="text-muted-foreground">
                                            Служба доставки
                                        </dt>
                                        <dd class="font-medium">
                                            {{ order.shipment.carrier }}
                                        </dd>
                                    </div>
                                    <div
                                        class="flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:gap-4"
                                    >
                                        <dt class="text-muted-foreground">
                                            Трек-номер
                                        </dt>
                                        <dd class="tabular-nums">
                                            {{ order.shipment.tracking_number }}
                                        </dd>
                                    </div>
                                </dl>
                                <a
                                    v-if="safeTrackingUrl !== null"
                                    :href="safeTrackingUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="mt-3 inline-flex text-sm text-primary"
                                    :class="linkClass"
                                >
                                    Отследить посылку
                                </a>
                                <p
                                    v-else
                                    class="mt-3 text-sm text-muted-foreground"
                                >
                                    {{ order.shipment.tracking_url }}
                                </p>
                            </template>
                            <p v-else class="text-sm text-muted-foreground">
                                Заказ ещё не передан в доставку.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>История статусов</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <OrderHistoryTimeline
                                v-if="order.history.length > 0"
                                :history="order.history"
                            />
                            <p v-else class="text-sm text-muted-foreground">
                                История статусов пуста.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
