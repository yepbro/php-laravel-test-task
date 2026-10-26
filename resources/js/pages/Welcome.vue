<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome" />

    <div
        class="flex min-h-screen flex-col items-center justify-center gap-4 bg-background p-6 text-foreground"
    >
        <AppLogo />
    </div>
</template>
