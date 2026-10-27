<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\SendOrderNotification;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * T-019. Повторные попытки отправки (FR-MAIL-05, S-21; механика —
 * заглушка T-034, красное до неё): временная ошибка SMTP оставляет
 * запись `pending` с attempts++, после 5-й попытки — `failed`;
 * восстановившийся SMTP до исчерпания лимита — `sent`.
 *
 * Backoff 10/60/300/900 с — свойство задания, зафиксировано зелёным
 * unit-тестом (SendOrderNotificationTest) и не дублируется. Зависший
 * `sending` не переотправляется автоматически — идемпотентность шага 1
 * и sweeper (SendOrderNotificationSkeletonTest,
 * SweepPendingNotificationsCommandTest, зелёные).
 *
 * Временная ошибка SMTP моделируется настоящим transport'ом, который
 * бросает Symfony TransportException — стандартное исключение
 * транспортов SMTP; перехват исключения в тесте допускает обе политики
 * задания (погасить и записать состояние либо перебросить в очередь для
 * повтора по backoff): контракт FR-MAIL-05 — состояние записи.
 */
class SendOrderNotificationRetryTest extends TestCase
{
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * FR-MAIL-05: однозначная временная ошибка отправки — письмо не
     * отправлено, запись возвращается в `pending`, attempts
     * инкрементируется (первая попытка).
     */
    public function test_temporary_smtp_failure_keeps_pending_and_counts_attempt(): void
    {
        $this->useFailingSmtpTransport();

        $notification = $this->createPaidPendingNotification();

        try {
            (new SendOrderNotification($notification->id))->handle();
        } catch (\Throwable) {
            // Повторное исключение очереди (release с backoff) допустим —
            // проверяется состояние записи, а не поведение исключения.
        }

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::PENDING, $fresh->status, 'Временная ошибка — не теряет запись из outbox.');
        $this->assertSame(1, $fresh->attempts, 'attempts++ на каждой попытке.');
        $this->assertNull($fresh->sent_at);
        $this->assertNull($fresh->skip_reason);
    }

    /**
     * FR-MAIL-05: лимит попыток — после 5-й временной ошибки запись
     * переходит в `failed` с последней ошибкой; письмо не отправлено.
     */
    public function test_attempt_limit_transitions_notification_to_failed(): void
    {
        $this->useFailingSmtpTransport();

        // Четыре попытки уже сделаны — пятая исчерпывает лимит.
        $notification = $this->createPaidPendingNotification(attempts: 4);

        try {
            (new SendOrderNotification($notification->id))->handle();
        } catch (\Throwable) {
            // См. тест выше: политика исключений задания свободна.
        }

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::FAILED, $fresh->status, 'После 5 попыток — failed.');
        $this->assertSame(5, $fresh->attempts);
        $this->assertNull($fresh->sent_at);
        $this->assertNotNull($fresh->last_error, 'Последняя ошибка фиксируется для диагностики.');
    }

    /**
     * S-21: SMTP временно недоступен (2 неудачные попытки), затем
     * восстановился до исчерпания лимита — отправка успешна: `sent`,
     * attempts учитывает все попытки, письмо уходит.
     */
    public function test_recovery_after_temporary_failures_still_sends_letter(): void
    {
        Mail::fake();

        $notification = $this->createPaidPendingNotification(attempts: 2);

        (new SendOrderNotification($notification->id))->handle();

        Mail::assertSent(OrderPaidMail::class);

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::SENT, $fresh->status);
        $this->assertSame(3, $fresh->attempts, 'Попытки суммируются по всем отправкам.');
        $this->assertTrue(
            $fresh->sent_at?->equalTo(CarbonImmutable::parse(self::FROZEN_NOW)) ?? false,
        );
    }

    private function createPaidPendingNotification(int $attempts = 0): OrderNotification
    {
        $order = Order::factory()->paid()->create();

        return OrderNotification::factory()
            ->forOrder($order)
            ->pending()
            ->create([
                'type' => NotificationType::PAID,
                'attempts' => $attempts,
            ]);
    }

    /**
     * Настоящий почтовый конвейер с transport'ом, имитирующим
     * недоступность SMTP (Symfony TransportException — каноническая
     * временная ошибка транспорта).
     */
    private function useFailingSmtpTransport(): void
    {
        $failingTransport = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('SMTP недоступен: соединение отклонено');
            }

            public function __toString(): string
            {
                return 'failing-smtp';
            }
        };

        config([
            'mail.mailers.failing-smtp' => ['transport' => 'failing-smtp'],
            'mail.default' => 'failing-smtp',
        ]);

        $this->app['mail.manager']->extend(
            'failing-smtp',
            static fn(): TransportInterface => $failingTransport,
        );
    }
}
