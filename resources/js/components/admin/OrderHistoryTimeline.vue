<script setup lang="ts">
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { formatDateTime } from '@/lib/format';
import type { OrderHistoryEntry } from '@/types';
import { computed } from 'vue';

/**
 * Таймлайн истории статусов карточки (FR-ADM-02): вертикальная линия
 * с точками переходов. Порядок — хронологический, как отдаёт API
 * (FR-HIS-04: `at ASC`); подписи статусов — карта StatusBadge
 * (клиентский словарь, карточка не получает вспомогательных props —
 * T-041). Последний факт — текущий статус заказа, его точка
 * выделена токеном primary.
 */
const props = defineProps<{
    history: OrderHistoryEntry[];
}>();

const lastIndex = computed(() => props.history.length - 1);
</script>

<template>
    <ol class="flex flex-col">
        <li
            v-for="(entry, index) in history"
            :key="`${entry.status}-${entry.at}`"
            class="relative flex flex-col gap-1 border-l-2 pb-5 pl-6 last:pb-0"
        >
            <span
                aria-hidden="true"
                class="absolute top-1 -left-1 size-2.5 rounded-full ring-4 ring-card"
                :class="
                    index === lastIndex
                        ? 'bg-primary'
                        : 'bg-muted-foreground/40'
                "
            >
            </span>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <StatusBadge :status="entry.status" />
                <time
                    :datetime="entry.at"
                    class="text-sm text-muted-foreground"
                >
                    {{ formatDateTime(entry.at) }}
                </time>
            </div>
        </li>
    </ol>
</template>
