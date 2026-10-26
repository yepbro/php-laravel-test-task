<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/types';

/**
 * Каркас пагинации списка (FR-ADM-01, Q3-гибрид): строится по `meta`
 * ответа /api/orders; `links` из API ведут на /api/orders и для
 * навигации не используются. Пока это кнопки с событием `change`;
 * полноценные ссылки вида /admin/orders?page=N (с сохранением
 * фильтров) появятся вместе с синхронизацией query-string — T-039/T-040.
 */
defineProps<{
    meta: PaginationMeta;
    disabled?: boolean;
}>();
const emit = defineEmits<{
    (e: 'change', page: number): void;
}>();
</script>

<template>
    <nav
        class="flex flex-wrap items-center justify-between gap-3"
        aria-label="Пагинация заказов"
    >
        <p class="text-sm text-muted-foreground">
            Страница {{ meta.current_page }} из {{ meta.last_page }} · всего:
            {{ meta.total }}
        </p>
        <div class="flex gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="disabled || meta.current_page <= 1"
                @click="emit('change', meta.current_page - 1)"
            >
                Назад
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="disabled || meta.current_page >= meta.last_page"
                @click="emit('change', meta.current_page + 1)"
            >
                Вперёд
            </Button>
        </div>
    </nav>
</template>
