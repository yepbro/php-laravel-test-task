<script setup lang="ts">
import type { BadgeVariants } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';
import type { OrderStatus } from '@/types';
import { computed } from 'vue';

/**
 * Бейдж статуса заказа: русская подпись + вариант по смыслу статуса.
 *
 * Подписи статусов — карта представлений бейджа: она нужна везде, где
 * статус приходит из API (карточка не получает вспомогательные props,
 * кроме id — T-013), тогда как опции фильтра списка приходят с сервера
 * (FR-ADM-05). Если наборы разойдутся, это заметно на одной странице
 * списка.
 *
 * «Доставлен» — финальное успешное состояние: выделяется поверх
 * `outline` цветом primary через дизайн-токены (T-040); отдельный
 * success-вариант в ui/badge не добавляется (vendored-компонент,
 * токенов успеха в теме нет). «Отправлен» остаётся нейтральным
 * outline — статусы различимы и подписью, и цветом.
 */
const STATUS_LABELS: Record<OrderStatus, string> = {
    created: 'Создан',
    paid: 'Оплачен',
    shipped: 'Отправлен',
    delivered: 'Доставлен',
    cancelled: 'Отменён',
};

const STATUS_VARIANTS: Record<
    OrderStatus,
    NonNullable<BadgeVariants['variant']>
> = {
    created: 'secondary',
    paid: 'default',
    shipped: 'outline',
    delivered: 'outline',
    cancelled: 'destructive',
};

const STATUS_CLASSES: Record<OrderStatus, string> = {
    created: '',
    paid: '',
    shipped: '',
    delivered: 'border-primary/40 text-primary',
    cancelled: '',
};

const props = defineProps<{
    status: OrderStatus;
}>();

const label = computed(() => STATUS_LABELS[props.status]);
const variant = computed(() => STATUS_VARIANTS[props.status]);
const classes = computed(() => STATUS_CLASSES[props.status]);
</script>

<template>
    <Badge :variant="variant" :class="classes">{{ label }}</Badge>
</template>
