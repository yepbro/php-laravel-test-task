import { defineConfig } from '@playwright/test';

/**
 * Браузерные smoke-тесты админки (T-047, FR-ADM-07): запуск целиком
 * внутри Sail-контейнера против локального стенда — из контейнера
 * laravel.test приложение доступно на http://localhost (тот же
 * `artisan serve` на :80). Запуск с хоста — задайте
 * PLAYWRIGHT_BASE_URL (http://localhost).
 *
 * Браузеры живут в var/playwright (PLAYWRIGHT_BROWSERS_PATH): каталог
 * примонтирован в контейнер вместе с проектом и переживает пересоздание
 * контейнера. Установка: `sail npm run e2e:install`, запуск — `sail npm
 * run e2e` / `sail composer e2e`; переменная окружения задаётся в самих
 * npm-скриптах — playwright-core читает её при своём импорте, до
 * выполнения тела этого конфига (см. docs/testing.md, раздел Playwright).
 */
export default defineConfig({
    testDir: './e2e',
    // Стенд и демо-данные общие: один воркер, без параллелизма —
    // тесты только читают UI, а один chromium не выжимает память
    // контейнера.
    workers: 1,
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost',
        locale: 'ru-RU',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    // Артефакты упавших прогонов — рядом с браузерами, вне Git.
    outputDir: 'var/playwright-artifacts',
    projects: [{ name: 'chromium' }],
});
