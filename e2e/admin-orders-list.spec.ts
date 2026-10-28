import { expect, test } from '@playwright/test';
import {
    DEMO_EMAIL,
    STATUS_LABELS,
    fetchOrdersList,
    tableRows,
} from './helpers/admin';

/**
 * Smoke-сценарии списка заказов (FR-ADM-01/FR-ADM-04, T-047): загрузка
 * страницы и данные клиентского запроса GET /api/orders, фильтры по
 * статусу и email, пагинация по meta, пустое состояние фильтра.
 */

test.describe('Админка: список заказов', () => {
    test('«/» перенаправляет на /admin/orders', async ({ page }) => {
        await page.goto('/');

        await expect(page).toHaveURL(/\/admin\/orders$/);
    });

    test('данные списка приходят запросом GET /api/orders', async ({
        page,
    }) => {
        const apiResponse = page.waitForResponse(
            (response) =>
                response.url().includes('/api/orders') &&
                response.request().method() === 'GET',
        );

        await page.goto('/admin/orders');

        const response = await apiResponse;
        expect(response.status()).toBe(200);

        const body = await response.json();

        await expect(
            page.getByRole('heading', { name: 'Заказы' }),
        ).toBeVisible();

        // Число строк и первая строка — из тела ответа API.
        const rows = tableRows(page);
        await expect(rows).toHaveCount(body.data.length);
        await expect(rows.first()).toContainText(body.data[0].number);
        await expect(rows.first()).toContainText(
            STATUS_LABELS[body.data[0].status] as string,
        );
        await expect(rows.first()).toContainText(body.data[0].customer.email);

        // Сводка пагинации — по meta того же ответа.
        await expect(
            page.getByText(
                `Показано ${body.meta.from}–${body.meta.to} из ${body.meta.total}`,
            ),
        ).toBeVisible();
    });

    test('фильтр по статусу оставляет только отменённые', async ({ page }) => {
        await page.goto('/admin/orders');
        await expect(tableRows(page).first()).toBeVisible();

        await page.locator('#order-filter-status').click();
        await page.getByRole('option', { name: 'Отменён' }).click();

        // Состояние фильтра — в query-string (копируемая ссылка).
        await expect(page).toHaveURL(/\/admin\/orders\?status=cancelled$/);

        const api = await fetchOrdersList(page.request, {
            status: 'cancelled',
        });

        expect(
            api.data.length,
            'демо-сид T-007 создаёт отменённые заказы',
        ).toBeGreaterThan(0);

        const rows = tableRows(page);
        await expect(rows).toHaveCount(api.data.length);
        await expect(rows.first()).toContainText(api.data[0].number);

        // Каждая строка — бейдж «Отменён».
        for (let index = 0; index < api.data.length; index += 1) {
            await expect(rows.nth(index).locator('td').nth(1)).toHaveText(
                'Отменён',
            );
        }
    });

    test('фильтр по email — точное совпадение без учёта регистра', async ({
        page,
    }) => {
        await page.goto('/admin/orders');
        await expect(tableRows(page).first()).toBeVisible();

        // Ввод в верхнем регистре: нормализует API (FR-API-02).
        await page
            .locator('#order-filter-email')
            .fill(DEMO_EMAIL.toUpperCase());
        await page.getByRole('button', { name: 'Применить' }).click();

        await expect(page).toHaveURL(
            new RegExp(
                `/admin/orders\\?email=${DEMO_EMAIL.toUpperCase().replace('@', '%40')}$`,
            ),
        );

        const api = await fetchOrdersList(page.request, {
            email: DEMO_EMAIL.toUpperCase(),
        });

        expect(
            api.data.length,
            'демо-сид T-007 создаёт заказы с email из пула DEMO_EMAILS',
        ).toBeGreaterThan(0);

        const rows = tableRows(page);
        await expect(rows).toHaveCount(api.data.length);

        // Отображается оригинальный регистр email из ответа API.
        for (let index = 0; index < api.data.length; index += 1) {
            await expect(rows.nth(index)).toContainText(
                new RegExp(api.data[index].customer.email, 'i'),
            );
        }
    });

    test('пагинация: «Вперёд» ведёт на вторую страницу по meta', async ({
        page,
    }) => {
        const first = await fetchOrdersList(page.request);

        expect(
            first.meta.last_page,
            'демо-сид T-007 гарантирует ≥ 21 заказа (2 страницы по 20)',
        ).toBeGreaterThanOrEqual(2);

        await page.goto('/admin/orders');
        await expect(tableRows(page)).toHaveCount(first.data.length);

        await page.getByRole('link', { name: 'Следующая страница' }).click();

        await expect(page).toHaveURL(/\/admin\/orders\?page=2$/);

        const second = await fetchOrdersList(page.request, { page: 2 });

        expect(second.data[0].id).not.toBe(first.data[0].id);
        await expect(tableRows(page)).toHaveCount(second.data.length);
        await expect(tableRows(page).first()).toContainText(
            second.data[0].number,
        );
        await expect(
            page.getByText(
                `Показано ${second.meta.from}–${second.meta.to} из ${second.meta.total}`,
            ),
        ).toBeVisible();

        // Текущая страница подсвечена внутри навигации пагинации.
        await expect(
            page
                .getByRole('navigation', { name: 'Пагинация заказов' })
                .locator('[aria-current="page"]'),
        ).toHaveText('2');

        // Ссылка «Назад» — канонический адрес первой страницы.
        await expect(
            page.getByRole('link', { name: 'Предыдущая страница' }),
        ).toHaveAttribute('href', '/admin/orders');
    });

    test('пустое состояние фильтра — «Ничего не найдено»', async ({ page }) => {
        await page.goto('/admin/orders');
        await expect(tableRows(page).first()).toBeVisible();

        await page
            .locator('#order-filter-email')
            .fill('no-such-user-e2e@example.invalid');
        await page.getByRole('button', { name: 'Применить' }).click();

        await expect(page).toHaveURL(
            /\/admin\/orders\?email=no-such-user-e2e%40example\.invalid$/,
        );
        await expect(
            page.getByText('Ничего не найдено', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'По заданным условиям заказы не найдены. Попробуйте изменить или сбросить фильтры.',
            ),
        ).toBeVisible();

        // Сброс возвращает полный список и чистит query-string.
        await page.getByRole('button', { name: 'Сбросить фильтры' }).click();

        await expect(page).toHaveURL(/\/admin\/orders$/);
        await expect(tableRows(page).first()).toBeVisible();
    });
});
