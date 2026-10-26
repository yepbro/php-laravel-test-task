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
 * списка. Уточнение вариантов (отдельный «success» для delivered) —
 * T-040/T-041.
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

const props = defineProps<{
    status: OrderStatus;
}>();

const label = computed(() => STATUS_LABELS[props.status]);
const variant = computed(() => STATUS_VARIANTS[props.status]);
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
