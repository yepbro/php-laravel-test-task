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
import { computed, ref, watch } from 'vue';

/**
 * Фильтры списка (FR-ADM-01): статус (опции — вспомогательные props
 * страницы с русскими подписями, FR-ADM-05) и email покупателя.
 * Изменения отдаёт родителю событием; владелец состояния и его
 * синхронизации с query-string /admin/orders — composable useOrdersList
 * (T-039), этот компонент представления не знает про URL.
 *
 * «Все статусы» — sentinel-пункт SelectItem со значением "all"
 * (T-040, перенос ревью T-039): reka-ui запрещает пустое значение у
 * пункта списка, а sentinel даёт пользователю явный способ снять
 * фильтр статуса. Sentinel не проходит guard isOrderStatus, поэтому
 * отображается через плейсхолдер значения, а в состояние родителя
 * канонически превращается в `status: undefined` (параметр выпадает
 * из query-string).
 */
const ALL_STATUSES = 'all';

const props = defineProps<{
    statusOptions: StatusOption[];
    modelValue: OrderListFilterValues;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: OrderListFilterValues): void;
}>();

const status = ref<OrderStatus | undefined>(props.modelValue.status);
const email = ref(props.modelValue.email ?? '');

const statusValue = computed(() => status.value ?? ALL_STATUSES);

const hasFilters = computed(
    () => status.value !== undefined || email.value.trim() !== '',
);

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
    // Sentinel "all" не проходит guard — статус снимается.
    const next = isOrderStatus(value) ? value : undefined;

    // Повторный выбор уже активного пункта ничего не меняет — без
    // лишнего запроса и молчаливого сброса страницы на первую
    // (отредактированный email применяется кнопкой «Применить»).
    if (next === status.value) {
        return;
    }

    status.value = next;
    emitCurrent();
}

/** Сброс обоих фильтров (строка состояния в URL вычищается родителем). */
function reset(): void {
    status.value = undefined;
    email.value = '';
    emitCurrent();
}
</script>

<template>
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="emitCurrent">
        <div class="flex w-full flex-col gap-2 sm:w-48">
            <Label for="order-filter-status">Статус</Label>
            <Select
                :model-value="statusValue"
                @update:model-value="onStatusChange"
            >
                <SelectTrigger id="order-filter-status" class="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL_STATUSES">Все статусы</SelectItem>
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

        <div class="flex w-full flex-1 flex-col gap-2 sm:w-auto">
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

        <div class="flex w-full gap-2 sm:w-auto">
            <Button type="submit" variant="outline">Применить</Button>
            <Button
                v-if="hasFilters"
                type="button"
                variant="ghost"
                @click="reset"
            >
                Сбросить
            </Button>
        </div>
    </form>
</template>
