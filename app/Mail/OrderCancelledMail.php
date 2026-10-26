<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо «Заказ отменён» (§6.5 ТЗ; каркас T-012, тексты — T-033).
 *
 * Данные берутся из ТЕКУЩЕГО состояния заказа на момент отправки
 * (§6.5). Фраза «Деньги вернутся в течение N дней.» добавляется только
 * при `paid_at ≠ null` — реализация T-033 (N = NOTIFICATIONS_REFUND_DAYS).
 * Отправитель — глобальный `config/mail.php` (`no-reply@shop.local` /
 * «Магазин», Q27).
 */
final class OrderCancelledMail extends Mailable
{
    public function __construct(
        public readonly Order $order,
    ) {}

    /**
     * Тема дословно из §6.5: `Заказ №{number} отменён`; «№» — U+2116.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Заказ №{$this->order->number} отменён",
        );
    }

    /**
     * Простой текст (`text/plain; charset=UTF-8`), без HTML (§6.5).
     * Причина отмены и условная фраза возврата — T-033.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.order-cancelled',
        );
    }
}
