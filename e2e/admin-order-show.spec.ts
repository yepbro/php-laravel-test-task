import { expect, test } from '@playwright/test';
import {
    STATUS_LABELS,
    formatMoney,
    pickRichOrder,
    sumAmounts,
    summaryValue,
} from './helpers/admin';

/**
 * Smoke-сценарии карточки заказа (FR-ADM-02/FR-ADM-04, T-047): данные
 * приходят клиентским запросом GET /api/orders/{id} — проверяются
 * позиции, разбивка сумм, отправка и история статусов; несуществующий
 * id — клиентское состояние «Заказ не найден» по 404 от API.
 */

test.describe('Админка: карточка заказа', () => {
    test('позиции, суммы и история — из ответа GET /api/orders/{id}', async ({
        page,
        request,
    }) => {
        const order = await pickRichOrder(request);

        const apiResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith(`/api/orders/${order.id}`) &&
                response.request().method() === 'GET',
        );

        await page.goto(`/admin/orders/${order.id}`);

        expect((await apiResponse).status()).toBe(200);

        // Шапка: номер и бейдж текущего статуса (родитель заголовка).
        const orderHeader = page
            .getByRole('heading', { name: `Заказ №${order.number}` })
            .locator('..');

        await expect(orderHeader).toContainText(
            STATUS_LABELS[order.status] as string,
        );

        // Покупатель: email, имя, телефон или его отсутствие.
        await expect(page.getByText(order.customer.email)).toBeVisible();
        await expect(
            page.getByText(order.customer.name, { exact: true }),
        ).toBeVisible();
        await expect(summaryValue(page, 'Телефон')).toHaveText(
            order.customer.phone ?? 'Телефон не указан',
        );

        // Позиции: построчно из items ответа.
        const itemRows = page.locator('table tbody tr');
        await expect(itemRows).toHaveCount(order.items.length);

        const [first, second] = order.items;

        await expect(itemRows.nth(0).locator('td').nth(0)).toHaveText(
            first.sku,
        );
        await expect(itemRows.nth(0).locator('td').nth(1)).toHaveText(
            first.name,
        );
        await expect(itemRows.nth(0).locator('td').nth(2)).toHaveText(
            String(first.quantity),
        );
        await expect(itemRows.nth(0).locator('td').nth(3)).toHaveText(
            formatMoney(first.price, order.currency),
        );
        await expect(itemRows.nth(0).locator('td').nth(4)).toHaveText(
            formatMoney(first.subtotal, order.currency),
        );
        await expect(itemRows.nth(1).locator('td').nth(0)).toHaveText(
            second.sku,
        );

        // Разбивка сумм: сумма позиций считается клиентом, остальное —
        // из ответа; формат тот же, что в UI (Intl ru-RU).
        await expect(summaryValue(page, 'Позиции')).toHaveText(
            formatMoney(
                sumAmounts(order.items.map((item) => item.subtotal)),
                order.currency,
            ),
        );
        await expect(summaryValue(page, 'Доставка')).toHaveText(
            formatMoney(order.delivery_price, order.currency),
        );
        await expect(summaryValue(page, 'Скидка')).toHaveText(
            formatMoney(order.discount, order.currency),
        );
        await expect(summaryValue(page, 'Итого')).toHaveText(
            formatMoney(order.total, order.currency),
        );

        // Отправка: «богатый» заказ гарантированно с shipment.
        const shipment = order.shipment as NonNullable<typeof order.shipment>;

        await expect(
            page.getByText(shipment.carrier, { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText(shipment.tracking_number, { exact: true }),
        ).toBeVisible();

        // История статусов: таймлайн в порядке ответа, каждый факт —
        // подпись статуса и точный момент (атрибут datetime).
        const entries = page.locator('ol > li');
        await expect(entries).toHaveCount(order.history.length);

        for (let index = 0; index < order.history.length; index += 1) {
            const entry = order.history[index];

            await expect(entries.nth(index)).toContainText(
                STATUS_LABELS[entry.status] as string,
            );
            await expect(entries.nth(index).locator('time')).toHaveAttribute(
                'datetime',
                entry.at,
            );
        }

        // Дата оплаты — точный момент из ответа (в строке «Оплачен»
        // карточки дат: тот же момент есть и в таймлайне истории).
        await expect(
            summaryValue(page, 'Оплачен').locator('time'),
        ).toHaveAttribute('datetime', order.paid_at as string);
    });

    test('несуществующий id — «Заказ не найден» по 404 от API', async ({
        page,
    }) => {
        const missingId = 'ord_e2e-not-found';

        const apiResponse = page.waitForResponse((response) =>
            response.url().endsWith(`/api/orders/${missingId}`),
        );

        await page.goto(`/admin/orders/${missingId}`);

        expect((await apiResponse).status()).toBe(404);

        await expect(
            page.getByText('Заказ не найден', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText('Возможно, заказ удалён или ссылка неверна.'),
        ).toBeVisible();

        // Из пустого состояния можно вернуться к списку (ссылка-кнопка
        // «К списку заказов» — не путать с хлебной крошкой «← К списку
        // заказов» вверху страницы).
        await page
            .getByRole('link', { name: 'К списку заказов', exact: true })
            .click();

        await expect(page).toHaveURL(/\/admin\/orders$/);
    });
});
