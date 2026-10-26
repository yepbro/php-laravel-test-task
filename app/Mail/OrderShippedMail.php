<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо «Заказ передан в доставку» (§6.5 ТЗ; каркас T-012,
 * тексты — T-033).
 *
 * Данные берутся из ТЕКУЩЕГО состояния заказа на момент отправки
 * (§6.5): трек-номер в письме обязан совпадать с dedupe_key отправки
 * (FR-MAIL-02, S-38). Отправитель — глобальный `config/mail.php`
 * (`no-reply@shop.local` / «Магазин», Q27).
 */
final class OrderShippedMail extends Mailable
{
    public function __construct(
        public readonly Order $order,
    ) {}

    /**
     * Тема дословно из §6.5: `Заказ №{number} передан в доставку`;
     * «№» — U+2116.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Заказ №{$this->order->number} передан в доставку",
        );
    }

    /**
     * Простой текст (`text/plain; charset=UTF-8`), без HTML (§6.5).
     * Служба доставки, трек-номер и ссылка на отслеживание — T-033.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.order-shipped',
        );
    }
}
