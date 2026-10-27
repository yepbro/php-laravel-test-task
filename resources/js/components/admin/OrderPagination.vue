<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { PaginationMeta } from '@/types';
import { computed } from 'vue';

/**
 * Пагинация списка (FR-ADM-01, Q3-гибрид): строится по `meta` ответа
 * /api/orders; `links` из API ведут на /api/orders и для навигации не
 * используются. «Назад/Вперёд» и номера страниц — настоящие ссылки на
 * админ-адрес `/admin/orders?…&page=N` (копируемые, открываются в новой
 * вкладке), поэтому переход происходит без перезагрузки: клик
 * перехватывается только для обычного левого нажатия и отдаётся
 * родителю событием `change` (T-039). На время загрузки ссылки
 * остаются в DOM (не заменяются кнопками): фокус и контекстное меню
 * «открыть в новой вкладке» не пропадают, вёрстка не дёргается — клик
 * просто игнорируется (aria-disabled, находка ревью T-039). На границе
 * диапазона, когда цели нет вовсе, — disabled-кнопка.
 *
 * Номера страниц (T-040): первая и последняя всегда, вокруг текущей —
 * окно ±1, пропуски обозначаются многоточием; текущая страница —
 * не-ссылка с aria-current="page". Состояние «страница за пределами»
 * сюда не попадает — страница заменяет весь блок данных пустым
 * состоянием с переходом к первой странице (Index.vue, T-040).
 */
const props = defineProps<{
    meta: PaginationMeta;
    urlFor: (page: number) => string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'change', page: number): void;
}>();

/** Пункт ленты страниц: номер или многоточие-пропуск. */
type PageItem = number | 'gap';

const prevPage = computed(() =>
    props.meta.current_page > 1 ? props.meta.current_page - 1 : null,
);

const nextPage = computed(() =>
    props.meta.current_page < props.meta.last_page
        ? props.meta.current_page + 1
        : null,
);

const pages = computed<PageItem[]>(() => {
    const { current_page: current, last_page: last } = props.meta;
    const items: PageItem[] = [1];
    const windowStart = Math.max(2, current - 1);
    const windowEnd = Math.min(last - 1, current + 1);

    if (windowStart > 2) {
        items.push('gap');
    }

    for (let page = windowStart; page <= windowEnd; page += 1) {
        items.push(page);
    }

    if (windowEnd < last - 1) {
        items.push('gap');
    }

    if (last > 1) {
        items.push(last);
    }

    return items;
});

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
            Показано {{ meta.from }}–{{ meta.to }} из {{ meta.total }}
        </p>
        <div class="flex flex-wrap items-center gap-1">
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

            <template v-for="(item, index) in pages" :key="`${index}-${item}`">
                <span
                    v-if="item === 'gap'"
                    class="px-1 text-muted-foreground"
                    aria-hidden="true"
                >
                    …
                </span>
                <span
                    v-else-if="item === meta.current_page"
                    aria-current="page"
                    class="inline-flex size-8 items-center justify-center rounded-md bg-primary text-sm font-medium text-primary-foreground"
                >
                    {{ item }}
                </span>
                <Button
                    v-else
                    as-child
                    variant="outline"
                    size="icon-sm"
                    class="w-auto min-w-8 px-1"
                    :class="linkClass"
                >
                    <a
                        :href="urlFor(item)"
                        :aria-label="`Страница ${item}`"
                        :aria-disabled="disabled ? 'true' : undefined"
                        @click="onClick(item, $event)"
                    >
                        {{ item }}
                    </a>
                </Button>
            </template>

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
