{{--
    Текст письма «Заказ отменён» — §6.5 ТЗ (реализовано в T-033).

    Причина null или пустая строка → «Причина не указана» (Q26;
    парсер пропускает пустые строки — nullableString, пустое значение
    доходит до письма). Строка возврата — только для оплаченных
    заказов (`paid_at ≠ null`, S-08/S-09): «Деньги вернутся в течение
    N дней.», N — срок из `marketplace.notifications.refund_days`
    (NOTIFICATIONS_REFUND_DAYS, по умолчанию 10 — фраза §6.5 дословна
    при значении по умолчанию).

    Значения выводятся без HTML-экранирования ({!! !!}): письмо —
    plain text (`text/plain; charset=UTF-8`, без HTML), HTML-entity в
    нём — мусор (обоснование — order-paid).
--}}
@php($reason = $order->cancel_reason !== null && trim($order->cancel_reason) !== '' ? $order->cancel_reason : 'Причина не указана')
Здравствуйте, {!! $order->customer_name !!}!

Ваш заказ №{!! $order->number !!} отменён.

Причина отмены: {!! $reason !!}
@if ($order->paid_at !== null)
Деньги вернутся в течение {!! (string) config('marketplace.notifications.refund_days') !!} дней.
@endif
