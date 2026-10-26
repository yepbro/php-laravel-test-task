/**
 * Stylelint config.
 *
 * Границы:
 * - `resources/css/app.css` — единственное место, где разрешены сырые цветовые
 *   литералы (hsl/hex в `:root` и `@theme`): это источник дизайн-токенов.
 * - Во всех остальных css-файлах цвета — только через `var(...)`.
 * - `!important` запрещён везде; исключение нужно явно зашумлять inline-комментарием.
 */
export default {
    extends: ['stylelint-config-standard', 'stylelint-config-tailwindcss'],
    plugins: ['stylelint-declaration-strict-value'],
    ignoreFiles: ['node_modules/**', 'vendor/**', 'public/**'],
    rules: {
        'alpha-value-notation': null,
        'color-function-alias-notation': null,
        'custom-property-empty-line-before': null,
        'declaration-block-no-redundant-longhand-properties': null,
        'font-family-name-quotes': null,
        'media-feature-range-notation': null,
        'selector-class-pattern': null,
        /*
         * Stylelint не понимает Tailwind-утилиты в прелюдии @apply
         * (например, opacity-модификаторы вида `outline-ring/50`),
         * поэтому core-правило выдаёт ложные срабатывания.
         */
        'at-rule-prelude-no-invalid': null,
        'declaration-no-important': true,
        'no-duplicate-selectors': true,
        'selector-max-specificity': '0,3,0',
        /*
         * Явно включаем стандартные правила, чтобы не зависеть от набора
         * по умолчанию в stylelint-config-standard.
         */
        'color-hex-length': 'short',
        'color-function-notation': 'modern',
        'no-descending-specificity': true,
        'scale-unlimited/declaration-strict-value': [
            [
                '/color$/',
                'fill',
                'stroke',
                'background',
                'background-color',
                'border',
                'border-color',
                'border-top-color',
                'border-right-color',
                'border-bottom-color',
                'border-left-color',
                'outline-color',
            ],
            {
                ignoreValues: [
                    'currentColor',
                    'inherit',
                    'initial',
                    'unset',
                    'none',
                    'transparent',
                    '/^var\\(/',
                ],
                disableFix: true,
            },
        ],
    },
    overrides: [
        {
            /*
             * Palette источник правды (шаблон shadcn/vue-starter-kit).
             * Сырые значения цветов и hsl-токены без `deg` тут легальны.
             */
            files: ['resources/css/app.css'],
            rules: {
                'scale-unlimited/declaration-strict-value': null,
                'hue-degree-notation': null,
            },
        },
    ],
};
