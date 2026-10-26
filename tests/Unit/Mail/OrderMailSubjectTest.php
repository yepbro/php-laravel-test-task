<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Темы писем покупателю (T-012) — дословно из §6.5 ТЗ: символ «№» —
 * именно U+2116 (NUMBER SIGN), `{number}` — поле `number` заказа
 * (например, `MH-1048213`). Подстановка number — не бизнес-логика,
 * поэтому реализована в каркасе честно; тела писем — T-033.
 */
class OrderMailSubjectTest extends TestCase
{
    private const NUMBER = 'MH-1048213';

    private const NO_SIGN = "\u{2116}";

    #[DataProvider('subjectProvider')]
    public function test_subject_is_verbatim_from_spec(Mailable $mail, string $expectedSubject): void
    {
        $subject = $mail->envelope()->subject;

        $this->assertSame($expectedSubject, $subject);
        $this->assertStringContainsString(self::NO_SIGN, $subject);
    }

    /**
     * @return iterable<string, array{Mailable, non-empty-string}>
     */
    public static function subjectProvider(): iterable
    {
        return [
            'paid: Заказ №… оплачен' => [
                new OrderPaidMail(self::order(self::NUMBER)),
                'Заказ №' . self::NUMBER . ' оплачен',
            ],

            'shipped: Заказ №… передан в доставку' => [
                new OrderShippedMail(self::order(self::NUMBER)),
                'Заказ №' . self::NUMBER . ' передан в доставку',
            ],

            'cancelled: Заказ №… отменён' => [
                new OrderCancelledMail(self::order(self::NUMBER)),
                'Заказ №' . self::NUMBER . ' отменён',
            ],
        ];
    }

    public function test_subject_substitutes_order_number(): void
    {
        $mail = new OrderPaidMail(self::order('MH-0000042'));

        $this->assertSame('Заказ №MH-0000042 оплачен', $mail->envelope()->subject);
    }

    /**
     * Тема не содержит суррогатных замен «№» (обычный «#» или «N»):
     * в строке присутствует именно U+2116.
     */
    public function test_subject_uses_real_numero_character(): void
    {
        $subject = (new OrderPaidMail(self::order(self::NUMBER)))->envelope()->subject;

        $this->assertStringContainsString(self::NO_SIGN, $subject);
        $this->assertStringNotContainsString('#', $subject);
    }

    /**
     * Несохранённая модель: теме достаточно поля number из снимка.
     */
    private static function order(string $number): Order
    {
        $order = new Order();
        $order->forceFill(['number' => $number]);

        return $order;
    }
}
