{{--
    Текст письма «Заказ оплачен» — §6.5 ТЗ (реализовано в T-033).

    Формат дословно по примеру §6.5: строки позиций
    `{position}. {sku} — {name}: {quantity} × {price} = {subtotal} {currency}`,
    суммы — как в снимке + код валюты (A-26); стоимость позиции считает
    Money::subtotal — единая точка денежной арифметики (T-022).

    Значения выводятся без HTML-экранирования ({!! !!}): письмо —
    plain text (`text/plain; charset=UTF-8`, без HTML), HTML-entity в
    нём — мусор (легитимные кавычки в названии товара `{{ }}` превратил
    бы в &quot; и сломал бы дословный формат §6.5).
--}}

@use('App\Support\Money')

Здравствуйте, {!! $order->customer_name !!}!

Ваш заказ №{!! $order->number !!} оплачен.

Состав заказа:
@foreach ($order->items as $item)
{!! $item->position !!}. {!! $item->sku !!} — {!! $item->name !!}: {!! $item->quantity !!} × {!! $item->price !!} = {!! Money::subtotal($item->price, $item->quantity) !!} {!! $order->currency !!}
@endforeach

Доставка: {!! $order->delivery_price !!} {!! $order->currency !!}
Скидка: {!! $order->discount !!} {!! $order->currency !!}
Итого: {!! $order->total !!} {!! $order->currency !!}
