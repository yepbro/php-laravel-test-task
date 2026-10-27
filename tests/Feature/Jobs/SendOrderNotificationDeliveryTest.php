<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Jobs\SendOrderNotification;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T-019. Доставка письма по outbox-записи (§7 «SendOrderNotification»,
 * шаги 2–5; механика — заглушка T-034, красное до неё).
 *
 * Гарантии: письмо уходит ровно один раз и только через задание (не в
 * HTTP-запросе), получатель — `customer_email` ТЕКУЩЕГО состояния заказа
 * (§6.5), запись фиксирует результат (`sent`/`sent_at`/`recipient`/
 * `subject`); каждая недопустимая ячейка матрицы §6.5 — `skipped
 * (superseded)` с освобождением dedupe_key (FR-MAIL-03, S-10);
 * невалидный email — `skipped (invalid_recipient)` (FR-MAIL-07);
 * shipped без shipment — `skipped (no_shipment)` (FR-MAIL-08).
 *
 * Не дублируется: темы писем (OrderMailSubjectTest), тексты тел
 * (OrderMailContentTest, T-033), матрица policy как таковая
 * (NotificationPolicyTest, T-031) — здесь проверяется ПРИМЕНЕНИЕ правил
 * заданием отправки; идемпотентность шага 1 (не-pending — тихий выход) —
 * SendOrderNotificationSkeletonTest.
 */
class SendOrderNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * Штатная отправка: письмо уходит один раз по назначанию, запись
     * переходит в `sent` с `sent_at`, получателем и дословной темой
     * §6.5; dedupe_key успешной записи не освобождается (его сбрасывает
     * только skip, FR-MAIL-03).
     */
    public function test_pending_notification_sends_letter_and_records_result(): void
    {
        Mail::fake();

        $notification = $this->createPendingNotification(
            Order::factory()->paid()->create(['number' => 'MH-1048213']),
            NotificationType::PAID,
        );
        $order = $notification->order;

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertSent(OrderPaidMail::class);
        Mail::assertSent(
            OrderPaidMail::class,
            static fn(OrderPaidMail $mail): bool => $mail->hasTo($order->customer_email)
                && $mail->envelope()->subject === 'Заказ №MH-1048213 оплачен',
        );

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::SENT, $fresh->status);
        $this->assertSame(1, $fresh->attempts, 'Успешная отправка с первой попытки.');
        $this->assertTrue(
            $fresh->sent_at?->equalTo(CarbonImmutable::parse(self::FROZEN_NOW)) ?? false,
            'sent_at — момент успешной отправки.',
        );
        $this->assertSame($order->customer_email, $fresh->recipient);
        $this->assertSame('Заказ №MH-1048213 оплачен', $fresh->subject);
        $this->assertNull($fresh->skip_reason);
        $this->assertNull($fresh->last_error);
        $this->assertSame($notification->dedupe_key, $fresh->dedupe_key, 'Успешная отправка не освобождает dedupe_key.');
    }

    /**
     * §6.5: данные письма — из ТЕКУЩЕГО состояния заказа, а не из
     * момента создания outbox-записи: после постановки адрес изменился —
     * письмо уходит на новый.
     */
    public function test_recipient_is_taken_from_current_order_state(): void
    {
        Mail::fake();

        $order = Order::factory()->paid()->create();
        $notification = $this->createPendingNotification($order, NotificationType::PAID);

        $order->forceFill([
            'customer_email' => 'updated-address@shop.test',
            'customer_email_normalized' => 'updated-address@shop.test',
        ])->save();

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertSent(
            OrderPaidMail::class,
            static fn(OrderPaidMail $mail): bool => $mail->hasTo('updated-address@shop.test'),
        );
        $this->assertSame('updated-address@shop.test', $notification->fresh()->recipient);
    }

    /**
     * Каждая недопустимая ячейка матрицы §6.5/A-23 (9 из 15) на уровне
     * задания: письмо не отправляется, запись — `skipped (superseded)`,
     * dedupe_key освобождается (FR-MAIL-03, S-10 — статус успел
     * смениться между постановкой и отправкой).
     *
     * @param non-empty-string $caseName
     */
    #[DataProvider('disallowedMatrixProvider')]
    public function test_disallowed_combination_skips_letter_and_releases_dedupe_key(
        string $caseName,
        NotificationType $type,
        OrderStatus $currentStatus,
    ): void {
        Mail::fake();

        $order = $this->createOrderWithStatus($currentStatus);
        $notification = $this->createPendingNotification($order, $type);

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertNothingSent();

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::SKIPPED, $fresh->status, "Кейс «{$caseName}» — пропуск отправки.");
        $this->assertSame('superseded', $fresh->skip_reason);
        $this->assertNull($fresh->dedupe_key, 'Недопустимое письмо освобождает dedupe_key (FR-MAIL-03).');
        $this->assertNull($fresh->sent_at);
    }

    /**
     * FR-MAIL-07: невалидный email получателя — `skipped
     * (invalid_recipient)`, письмо не отправляется (warning в лог —
     * остаток, см. DECISIONS задачи 019).
     */
    public function test_invalid_recipient_email_is_skipped(): void
    {
        Mail::fake();

        $order = Order::factory()->paid()->create([
            'customer_email' => 'анна-смирнова-без-собаки',
        ]);
        $notification = $this->createPendingNotification($order, NotificationType::PAID);

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertNothingSent();

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::SKIPPED, $fresh->status);
        $this->assertSame('invalid_recipient', $fresh->skip_reason);
        $this->assertNull($fresh->dedupe_key);
    }

    /**
     * FR-MAIL-08: письмо shipped при отсутствии shipment в текущем
     * состоянии — `skipped (no_shipment)`.
     */
    public function test_shipped_letter_without_shipment_is_skipped(): void
    {
        Mail::fake();

        $order = Order::factory()->shipped()->withoutShipment()->create();
        $notification = $this->createPendingNotification($order, NotificationType::SHIPPED);

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertNothingSent();

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::SKIPPED, $fresh->status);
        $this->assertSame('no_shipment', $fresh->skip_reason);
        $this->assertNull($fresh->dedupe_key);
    }

    /**
     * Happy-path остальных типов (S-28): задание отправляет и shipped-,
     * и cancelled-письма (paid — тестами выше), получателю из текущего
     * состояния; запись завершается `sent`.
     */
    public function test_shipped_letter_is_sent(): void
    {
        Mail::fake();

        $order = Order::factory()->shipped()->create();
        $notification = $this->createPendingNotification($order, NotificationType::SHIPPED);

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertSent(
            OrderShippedMail::class,
            static fn(OrderShippedMail $mail): bool => $mail->hasTo($order->customer_email),
        );
        $this->assertSame(NotificationStatus::SENT, $notification->fresh()->status);
    }

    /**
     * Happy-path cancelled — см. тест выше (S-28).
     */
    public function test_cancelled_letter_is_sent(): void
    {
        Mail::fake();

        $order = Order::factory()->cancelled()->create();
        $notification = $this->createPendingNotification($order, NotificationType::CANCELLED);

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertSent(
            OrderCancelledMail::class,
            static fn(OrderCancelledMail $mail): bool => $mail->hasTo($order->customer_email),
        );
        $this->assertSame(NotificationStatus::SENT, $notification->fresh()->status);
    }

    /**
     * S-38 / FR-MAIL-02: перед отправкой shipped dedupe_key сверяется
     * с трек-номером ТЕКУЩЕГО состояния. Ключ постановки (старый трек)
     * отличается от текущего, целевой ключ свободен — ключ атомарно
     * обновляется на текущий трек, письмо уходит.
     */
    public function test_shipped_dedupe_key_follows_current_tracking_number(): void
    {
        Mail::fake();

        $order = Order::factory()->shipped('5550001111')->create();
        $notification = $this->createPendingNotification($order, NotificationType::SHIPPED);
        // Ключ, записанный при создании outbox (трек на тот момент).
        OrderNotification::query()
            ->whereKey($notification->id)
            ->update(['dedupe_key' => $order->id . ':shipped:1098765432']);

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertSent(OrderShippedMail::class);

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::SENT, $fresh->status);
        $this->assertSame(
            $order->id . ':shipped:5550001111',
            $fresh->dedupe_key,
            'Ключ отправки соответствует трек-номеру текущего состояния (S-38).',
        );
    }

    /**
     * S-38 / FR-MAIL-02: целевой ключ текущего трека уже занят другой
     * (отправленной) записью — уведомление помечается
     * `skipped (duplicate_tracking)`, письмо не уходит, ключ
     * освобождается (FR-MAIL-03).
     */
    public function test_shipped_with_existing_key_for_current_tracking_is_skipped_as_duplicate(): void
    {
        Mail::fake();

        $order = Order::factory()->shipped('5550001111')->create();
        // Отправленное письмо предыдущей отправки с текущим треком.
        OrderNotification::factory()
            ->forOrder($order)
            ->sent()
            ->create(['type' => NotificationType::SHIPPED]);

        $pending = OrderNotification::factory()
            ->forOrder($order)
            ->pending()
            ->create([
                'type' => NotificationType::SHIPPED,
                'dedupe_key' => $order->id . ':shipped:1098765432',
            ]);

        (new SendOrderNotification($pending->id))->handle();

        Mail::assertNothingSent();

        $fresh = $pending->fresh();
        $this->assertSame(NotificationStatus::SKIPPED, $fresh->status);
        $this->assertSame('duplicate_tracking', $fresh->skip_reason);
        $this->assertNull($fresh->dedupe_key);
    }

    /**
     * Недопустимые ячейки матрицы §6.5 (все 9; допустимые 6 покрыты
     * happy-path проверкой отправки и unit-матрицей T-031).
     *
     * @return iterable<string, array{non-empty-string, NotificationType, OrderStatus}>
     */
    public static function disallowedMatrixProvider(): iterable
    {
        return [
            'paid при created' => ['paid/created', NotificationType::PAID, OrderStatus::CREATED],
            'paid при cancelled' => ['paid/cancelled', NotificationType::PAID, OrderStatus::CANCELLED],

            'shipped при created' => ['shipped/created', NotificationType::SHIPPED, OrderStatus::CREATED],
            'shipped при paid' => ['shipped/paid', NotificationType::SHIPPED, OrderStatus::PAID],
            'shipped при cancelled' => ['shipped/cancelled', NotificationType::SHIPPED, OrderStatus::CANCELLED],

            'cancelled при created' => ['cancelled/created', NotificationType::CANCELLED, OrderStatus::CREATED],
            'cancelled при paid' => ['cancelled/paid', NotificationType::CANCELLED, OrderStatus::PAID],
            'cancelled при shipped' => ['cancelled/shipped', NotificationType::CANCELLED, OrderStatus::SHIPPED],
            'cancelled при delivered' => ['cancelled/delivered', NotificationType::CANCELLED, OrderStatus::DELIVERED],
        ];
    }

    private function createOrderWithStatus(OrderStatus $status): Order
    {
        $factory = match ($status) {
            OrderStatus::CREATED => Order::factory()->created(),
            OrderStatus::PAID => Order::factory()->paid(),
            OrderStatus::SHIPPED => Order::factory()->shipped(),
            OrderStatus::DELIVERED => Order::factory()->delivered(),
            OrderStatus::CANCELLED => Order::factory()->cancelled(),
        };

        return $factory->create();
    }

    private function createPendingNotification(Order $order, NotificationType $type): OrderNotification
    {
        return OrderNotification::factory()
            ->forOrder($order)
            ->pending()
            ->create(['type' => $type]);
    }
}
