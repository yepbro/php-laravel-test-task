<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/types';
import { computed } from 'vue';

/**
 * Пагинация списка (FR-ADM-01, Q3-гибрид): строится по `meta` ответа
 * /api/orders; `links` из API ведут на /api/orders и для навигации не
 * используются. «Назад/Вперёд» — настоящие ссылки на админ-адрес
 * `/admin/orders?…&page=N` (копируемые, открываются в новой вкладке),
 * поэтому переход происходит без перезагрузки: клик перехватывается
 * только для обычного левого нажатия и отдаётся родителю событием
 * `change` (T-039). На время загрузки ссылки остаются в DOM (не
 * заменяются кнопками): фокус и контекстное меню «открыть в новой
 * вкладке» не пропадают, вёрстка не дёргается — клик просто
 * игнорируется (aria-disabled, находка ревью T-039). На границе
 * диапазона, когда цели нет вовсе, — disabled-кнопка. Полный UI
 * (номера страниц, «первая/последняя») — T-040.
 */
const props = defineProps<{
    meta: PaginationMeta;
    urlFor: (page: number) => string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'change', page: number): void;
}>();

const prevPage = computed(() =>
    props.meta.current_page > 1 ? props.meta.current_page - 1 : null,
);

const nextPage = computed(() =>
    props.meta.current_page < props.meta.last_page
        ? props.meta.current_page + 1
        : null,
);

const linkClass = computed(() =>
    props.disabled ? 'pointer-events-none opacity-50' : undefined,
);

function onClick(page: number, event: MouseEvent): void {
    if (
        event.button !== 0 ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.altKey
    ) {
        // Модификаторы/средняя кнопка — пусть браузер сам откроет ссылку.
        return;
    }

    event.preventDefault();

    if (!props.disabled) {
        emit('change', page);
    }
}
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
                v-if="prevPage !== null"
                as-child
                variant="outline"
                size="sm"
                :class="linkClass"
            >
                <a
                    :href="urlFor(prevPage)"
                    rel="prev"
                    aria-label="Предыдущая страница"
                    :aria-disabled="disabled ? 'true' : undefined"
                    @click="onClick(prevPage, $event)"
                >
                    Назад
                </a>
            </Button>
            <Button v-else variant="outline" size="sm" disabled> Назад </Button>
            <Button
                v-if="nextPage !== null"
                as-child
                variant="outline"
                size="sm"
                :class="linkClass"
            >
                <a
                    :href="urlFor(nextPage)"
                    rel="next"
                    aria-label="Следующая страница"
                    :aria-disabled="disabled ? 'true' : undefined"
                    @click="onClick(nextPage, $event)"
                >
                    Вперёд
                </a>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                Вперёд
            </Button>
        </div>
    </nav>
</template>
