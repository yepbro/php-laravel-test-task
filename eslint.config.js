import prettier from 'eslint-config-prettier/flat';
import vue from 'eslint-plugin-vue';

import {
    defineConfigWithVueTs,
    vueTsConfigs,
} from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            // Локальные артефакты Playwright: браузеры (var/playwright) и
            // артефакты упавших прогонов (var/playwright-artifacts) — T-047.
            'var',
            'bootstrap/ssr',
            'storage',
            'resources/js/components/ui/*',
            'resources/js/actions',
            'resources/js/routes',
            'resources/js/wayfinder',
        ],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
        },
    },
    prettier,
);
