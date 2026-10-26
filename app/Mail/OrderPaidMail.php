<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо «Заказ оплачен» (§6.5 ТЗ; каркас T-012, тексты — T-033).
 *
 * Данные берутся из ТЕКУЩЕГО состояния заказа на момент отправки, а не
 * из снимка события-триггера (§6.5): если после `paid` пришёл свежий
 * `order.updated`, состав в письме актуальный. Получателя задаёт
 * задание отправки (T-034) через `Mail::to(...)`, отправитель —
 * глобальный `config/mail.php` (`no-reply@shop.local` / «Магазин», Q27).
 */
final class OrderPaidMail extends Mailable
{
    public function __construct(
        public readonly Order $order,
    ) {}

    /**
     * Тема дословно из §6.5: `Заказ №{number} оплачен`; «№» — U+2116.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Заказ №{$this->order->number} оплачен",
        );
    }

    /**
     * Простой текст (`text/plain; charset=UTF-8`), без HTML (§6.5).
     * Полный состав письма (позиции, доставка, скидка, итого) — T-033.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.order-paid',
        );
    }
}
