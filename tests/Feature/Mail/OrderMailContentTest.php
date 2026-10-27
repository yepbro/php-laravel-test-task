<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-019. Содержимое трёх писем покупателю — §6.5 ТЗ (тела — задача T-033,
 * красное до неё): суммы дословно как в снимке + код валюты (A-26),
 * состав/доставка/скидка/итого, служба/трек/ссылка, причина отмены и
 * строка возврата только при paid_at ≠ null, «Причина не указана» (Q26).
 *
 * Тема письма проверяется дословно отдельно (OrderMailSubjectTest,
 * зелёный T-012) и здесь не дублируется. Письмо — простой текст: у
 * Mailable нет HTML-представления (только textView), кириллица
 * round-trip подтверждает UTF-8.
 *
 * Красная фаза: blade-каркасы T-012 рендерят только приветствие и факт
 * статуса — блоки содержимого отсутствуют, каждый тест падает на
 * отсутствующей строке и зеленеет с T-033 без правки тестов.
 */
class OrderMailContentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Пример «paid» из §6.5 дословно: строки состава и сумм повторяют
     * образец ТЗ (покупатель, номер, позиции, доставка, скидка, итого).
     * Проверка — построчная (каждая строка примера присутствует дословно,
     * пробелы вокруг строк не важны): дополнительные строки сверх примера
     * (подпись, завершающая фраза) ТЗ не запрещены и тестом не
     * блокируются (решение по итогам ревью T-019, P3-2).
     */
    public function test_paid_letter_reproduces_spec_example_lines(): void
    {
        $order = $this->createSpecExampleOrder();
        $mail = new OrderPaidMail($order);

        $content = $mail->render();
        $lines = $this->contentLines($content);

        foreach ([
            'Здравствуйте, Анна Смирнова!',
            'Ваш заказ №MH-1048213 оплачен.',
            'Состав заказа:',
            '1. TSHIRT-BLK-M — Футболка базовая, чёрная, M: 3 × 1290.00 = 3870.00 RUB',
            '2. CAP-NVY — Бейсболка "Classic" / синяя: 1 × 890.50 = 890.50 RUB',
            'Доставка: 349.00 RUB',
            'Скидка: 200.00 RUB',
            'Итого: 4909.50 RUB',
        ] as $expectedLine) {
            $this->assertContains($expectedLine, $lines, "Строка примера §6.5 отсутствует: {$expectedLine}");
        }
        $this->assertPlainTextOnly($mail);
    }

    /**
     * Суммы выводятся дословно как в снимке + код валюты заказа (A-26):
     * другой набор сумм и валюта USD, стоимость позиции = quantity ×
     * price без float (S-01-арифметика).
     */
    public function test_paid_letter_amounts_follow_snapshot_verbatim_with_currency(): void
    {
        $order = Order::factory()
            ->paid()
            ->create([
                'currency' => 'USD',
                'delivery_price' => '12.34',
                'discount' => '5.00',
                'total' => '100.00',
            ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'position' => 1,
            'sku' => 'SKU-USD-1',
            'name' => 'Товар за доллары',
            'quantity' => 2,
            'price' => '7.77',
        ]);

        $content = (new OrderPaidMail($order))->render();

        $this->assertContains('1. SKU-USD-1 — Товар за доллары: 2 × 7.77 = 15.54 USD', $this->contentLines($content));
        $this->assertContains('Доставка: 12.34 USD', $this->contentLines($content));
        $this->assertContains('Скидка: 5.00 USD', $this->contentLines($content));
        $this->assertContains('Итого: 100.00 USD', $this->contentLines($content));
    }

    /**
     * `shipped`: служба доставки, трек-номер и ссылка на отслеживание —
     * из текущего состояния заказа (согласованность с dedupe_key
     * отправки — FR-MAIL-02/S-38).
     */
    public function test_shipped_letter_lists_carrier_tracking_and_url(): void
    {
        $order = Order::factory()->shipped()->create([
            'shipment_carrier' => 'СДЭК',
            'shipment_tracking_number' => '1098765432',
            'shipment_tracking_url' => 'https://track.example.com/1098765432',
        ]);

        $mail = new OrderShippedMail($order);
        $content = $mail->render();

        foreach (['СДЭК', '1098765432', 'https://track.example.com/1098765432'] as $expected) {
            $this->assertStringContainsString($expected, $content);
        }
        $this->assertPlainTextOnly($mail);
    }

    /**
     * `cancelled` оплаченного заказа: причина дословно из заказа и фраза
     * возврата «Деньги вернутся в течение N дней.» при paid_at ≠ null
     * (N = NOTIFICATIONS_REFUND_DAYS, §6.5).
     */
    public function test_cancelled_letter_of_paid_order_includes_reason_and_refund_line(): void
    {
        $order = Order::factory()
            ->paid()
            ->cancelled()
            ->create([
                'cancel_reason' => 'Заказ отменён покупателем',
                'paid_at' => '2026-10-21 10:05:47',
            ]);

        $mail = new OrderCancelledMail($order);
        $content = $mail->render();

        $this->assertStringContainsString('Заказ отменён покупателем', $content);
        $this->assertStringContainsString('Деньги вернутся в течение 10 дней.', $content);
        $this->assertPlainTextOnly($mail);
    }

    /**
     * `cancelled` неоплаченного заказа: строки возврата нет (S-09),
     * отсутствующая причина заменяется на «Причина не указана» (Q26).
     */
    public function test_cancelled_letter_of_unpaid_order_has_no_refund_line_and_default_reason(): void
    {
        $order = Order::factory()->cancelled()->create(['cancel_reason' => null]);

        $mail = new OrderCancelledMail($order);
        $content = $mail->render();

        $this->assertStringContainsString('Причина не указана', $content);
        $this->assertStringNotContainsString('Деньги вернутся', $content);
        $this->assertPlainTextOnly($mail);
    }

    /**
     * Заказ, дословно воспроизводящий пример §6.5: покупатель, номер,
     * позиции (3 футболки + бейсболка), доставка/скидка/итого.
     */
    private function createSpecExampleOrder(): Order
    {
        $order = Order::factory()
            ->paid()
            ->create([
                'id' => 'ord_MAILSPEC1',
                'number' => 'MH-1048213',
                'customer_name' => 'Анна Смирнова',
                'customer_email' => 'anna@shop.test',
                'currency' => 'RUB',
                'delivery_price' => '349.00',
                'discount' => '200.00',
                'total' => '4909.50',
            ]);

        OrderItem::factory()->createMany([
            [
                'order_id' => $order->id,
                'position' => 1,
                'sku' => 'TSHIRT-BLK-M',
                'name' => 'Футболка базовая, чёрная, M',
                'quantity' => 3,
                'price' => '1290.00',
            ],
            [
                'order_id' => $order->id,
                'position' => 2,
                'sku' => 'CAP-NVY',
                'name' => 'Бейсболка "Classic" / синяя',
                'quantity' => 1,
                'price' => '890.50',
            ],
        ]);

        return $order->fresh();
    }

    /**
     * Непустые строки письма без концевых пробелов — для дословного
     * сравнения строк состава/сумм.
     *
     * @return list<string>
     */
    private function contentLines(string $content): array
    {
        $lines = array_map(
            static fn(string $line): string => rtrim($line),
            explode("\n", str_replace("\r\n", "\n", $content)),
        );

        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /**
     * Письмо — только plain text: HTML-представление отсутствует
     * (§6.5: `text/plain; charset=UTF-8`, без HTML).
     */
    private function assertPlainTextOnly(\Illuminate\Mail\Mailable $mail): void
    {
        $this->assertNull($mail->view, 'HTML-представление не задаётся (только plain text).');
        $this->assertNotNull($mail->textView, 'Текстовое представление обязательно.');
    }
}
