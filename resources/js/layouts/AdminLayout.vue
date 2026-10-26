<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Единая навигация админки: пока единственный раздел — список заказов
// (§6.7 ТЗ). Активность определяется по текущему URL Inertia.
const page = usePage();
const isOrdersActive = computed(() => page.url.startsWith('/admin/orders'));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="border-b">
            <div
                class="mx-auto flex w-full max-w-5xl items-center gap-3 px-4 py-3"
            >
                <AppLogo />
                <span
                    aria-hidden="true"
                    class="hidden h-6 w-px bg-border sm:block"
                />
                <span class="hidden text-sm text-muted-foreground sm:block">
                    Заказы MarketHub
                </span>
                <nav class="ml-auto" aria-label="Разделы админки">
                    <Link
                        href="/admin/orders"
                        class="rounded-md px-3 py-2 text-sm font-medium outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring"
                        :class="
                            isOrdersActive
                                ? 'text-primary'
                                : 'text-muted-foreground'
                        "
                        :aria-current="isOrdersActive ? 'page' : undefined"
                    >
                        Заказы
                    </Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-6">
            <slot />
        </main>
    </div>
</template>
