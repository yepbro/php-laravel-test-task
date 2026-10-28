import { expect, test } from '@playwright/test';
import { fetchOrdersList, tableRows } from './helpers/admin';

/**
 * Smoke-сценарии состояния в query-string (FR-ADM-01, T-047): фильтры
 * и номер страницы живут в адресе /admin/orders — копируемая ссылка
 * открывает то же состояние, и оно переживает перезагрузку страницы.
 */

test.describe('Админка: состояние фильтров в query-string', () => {
    test('email-фильтр, применённый через UI, восстанавливается после перезагрузки', async ({
        page,
    }) => {
        await page.goto('/admin/orders');
        await expect(tableRows(page).first()).toBeVisible();

        await page.locator('#order-filter-email').fill('vera@shop.test');
        await page.getByRole('button', { name: 'Применить' }).click();

        await expect(page).toHaveURL(
            /\/admin\/orders\?email=vera%40shop\.test$/,
        );

        await page.reload();

        // Адрес, поле фильтра и данные — то же состояние.
        await expect(page).toHaveURL(
            /\/admin\/orders\?email=vera%40shop\.test$/,
        );
        await expect(page.locator('#order-filter-email')).toHaveValue(
            'vera@shop.test',
        );

        const api = await fetchOrdersList(page.request, {
            email: 'vera@shop.test',
        });

        expect(
            api.data.length,
            'демо-сид T-007 создаёт заказы с email из пула DEMO_EMAILS',
        ).toBeGreaterThan(0);
        await expect(tableRows(page)).toHaveCount(api.data.length);
        await expect(tableRows(page).first()).toContainText(api.data[0].number);
    });

    test('статус, выбранный в UI, восстанавливается после перезагрузки', async ({
        page,
    }) => {
        await page.goto('/admin/orders');
        await expect(tableRows(page).first()).toBeVisible();

        await page.locator('#order-filter-status').click();
        await page.getByRole('option', { name: 'Оплачен' }).click();

        await expect(page).toHaveURL(/\/admin\/orders\?status=paid$/);

        await page.reload();

        await expect(page).toHaveURL(/\/admin\/orders\?status=paid$/);
        await expect(page.locator('#order-filter-status')).toContainText(
            'Оплачен',
        );

        const api = await fetchOrdersList(page.request, { status: 'paid' });

        await expect(tableRows(page)).toHaveCount(api.data.length);
        await expect(tableRows(page).first()).toContainText('Оплачен');
    });

    test('копируемая ссылка со статусом, страницей и per_page открывает то же состояние', async ({
        page,
    }) => {
        const page1 = await fetchOrdersList(page.request, {
            status: 'cancelled',
            perPage: 5,
        });
        const page2 = await fetchOrdersList(page.request, {
            status: 'cancelled',
            perPage: 5,
            page: 2,
        });

        expect(
            page1.meta.last_page,
            'демо-сид T-007 создаёт ≥ 6 отменённых заказов (2 страницы по 5)',
        ).toBeGreaterThanOrEqual(2);

        await page.goto('/admin/orders?status=cancelled&per_page=5&page=2');

        const rows = tableRows(page);
        await expect(rows).toHaveCount(page2.data.length);
        await expect(rows.first()).toContainText(page2.data[0].number);
        await expect(rows.first()).toContainText('Отменён');
        await expect(
            page
                .getByRole('navigation', { name: 'Пагинация заказов' })
                .locator('[aria-current="page"]'),
        ).toHaveText('2');

        // Состояние переживает перезагрузку: адрес и данные те же
        // (порядок параметров браузер/Inertia нормализует — проверяются
        // сами значения).
        await page.reload();

        const params = new URL(page.url()).searchParams;

        expect(params.get('status')).toBe('cancelled');
        expect(params.get('per_page')).toBe('5');
        expect(params.get('page')).toBe('2');
        await expect(rows).toHaveCount(page2.data.length);
        await expect(rows.first()).toContainText(page2.data[0].number);
        await expect(page.locator('#order-filter-status')).toContainText(
            'Отменён',
        );
    });
});
