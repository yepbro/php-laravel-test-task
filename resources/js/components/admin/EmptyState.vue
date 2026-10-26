<script setup lang="ts">
/**
 * Пустое состояние (FR-ADM-04): «заказов нет», «ничего не найдено»,
 * «заказ не найден» — единый каркас с заголовком, описанием и слотом
 * для действий (например, «Сбросить фильтры» — T-040).
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-10 text-center text-muted-foreground"
        role="status"
    >
        <p class="font-medium text-foreground">{{ title }}</p>
        <p v-if="description" class="text-sm">{{ description }}</p>
        <slot />
    </div>
</template>
