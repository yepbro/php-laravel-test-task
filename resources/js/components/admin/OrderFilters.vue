<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { OrderListFilterValues } from '@/lib/api/orders';
import type { OrderStatus, StatusOption } from '@/types';
import { isOrderStatus } from '@/types';
import type { AcceptableValue } from 'reka-ui';
import { ref, watch } from 'vue';

/**
 * Каркас фильтров списка (FR-ADM-01): статус (опции — вспомогательные
 * props страницы с русскими подписями, FR-ADM-05) и email покупателя.
 * Изменения отдаёт родителю событием; синхронизация с query-string
 * адреса /admin/orders — T-039/T-040.
 */
const props = defineProps<{
    statusOptions: StatusOption[];
    modelValue: OrderListFilterValues;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: OrderListFilterValues): void;
}>();

const status = ref<OrderStatus | undefined>(props.modelValue.status);
const email = ref(props.modelValue.email ?? '');

watch(
    () => props.modelValue,
    (value) => {
        status.value = value.status;
        email.value = value.email ?? '';
    },
);

function emitCurrent(): void {
    emit('update:modelValue', {
        status: status.value,
        email: email.value.trim() || undefined,
    });
}

function onStatusChange(value: AcceptableValue): void {
    status.value = isOrderStatus(value) ? value : undefined;
    emitCurrent();
}
</script>

<template>
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="emitCurrent">
        <div class="flex w-full flex-col gap-2 sm:w-48">
            <Label for="order-filter-status">Статус</Label>
            <Select :model-value="status" @update:model-value="onStatusChange">
                <SelectTrigger id="order-filter-status" class="w-full">
                    <SelectValue placeholder="Все статусы" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in statusOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="flex w-full flex-1 flex-col gap-3 sm:w-auto">
            <Label for="order-filter-email">Email покупателя</Label>
            <Input
                id="order-filter-email"
                v-model="email"
                type="text"
                name="email"
                autocomplete="off"
                placeholder="anna.smirnova@example.com"
            />
        </div>

        <Button type="submit" variant="outline">Применить</Button>
    </form>
</template>
