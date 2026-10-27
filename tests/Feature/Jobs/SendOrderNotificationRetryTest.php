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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
 * и sweeper (SendOrderNotificationDeliveryTest,
 * SweepPendingNotificationsCommandTest, зелёные).
 *
 * Терминальная ветвь failed() (исчерпание попыток очереди, новый
 * инстанс задания — переход только sending → failed) и ветка «lock
 * заказа занят» (§7 шаг 2: повтор по политике очереди без изменения
 * записи) покрыты здесь же (T-034, по ревью P2-2).
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
     * Терминальная ветвь failed() (FR-MAIL-05, §7): очередь исчерпала
     * попытки задания, Laravel вызывает failed() на НОВОМ инстансе —
     * запись перезагружается по notificationId и переводится в
     * `failed` с последней ошибкой (мутации handle() там недоступны —
     * правило queue-jobs скилла laravel-best-practices).
     */
    public function test_failed_callback_transitions_sending_notification_to_failed(): void
    {
        $notification = $this->createPaidPendingNotification();
        OrderNotification::query()
            ->whereKey($notification->id)
            ->update(['status' => NotificationStatus::SENDING->value]);

        (new SendOrderNotification($notification->id))
            ->failed(new TransportException('SMTP недоступен: таймаут соединения'));

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::FAILED, $fresh->status, 'failed() завершает зависший sending.');
        $this->assertSame('SMTP недоступен: таймаут соединения', $fresh->last_error);
        $this->assertNull($fresh->sent_at);
    }

    /**
     * failed() не портит записи, которые очередь достались «рано» или
     * «поздно»: `pending` (сбой до захвата — письмо ещё не пыталось
     * отправиться, его переставит sweeper, FR-MAIL-06; переход
     * pending → failed запрещён картой T-012) и терминальный `sent`
     * (доставлено — результат не перезаписывается).
     */
    public function test_failed_callback_leaves_pending_and_sent_records_untouched(): void
    {
        $pending = $this->createPaidPendingNotification();
        $sent = OrderNotification::factory()->sent()->create();

        $job = new SendOrderNotification($pending->id);
        $job->failed(new TransportException('SMTP недоступен'));

        (new SendOrderNotification($sent->id))
            ->failed(new TransportException('SMTP недоступен'));

        $this->assertSame(NotificationStatus::PENDING, $pending->fresh()->status, 'pending остаётся sweeper\'у.');
        $this->assertNull($pending->fresh()->last_error);
        $this->assertSame(NotificationStatus::SENT, $sent->fresh()->status, 'Терминальный sent не перезаписывается.');
        $this->assertNotNull($sent->fresh()->sent_at);
    }

    /**
     * §7 шаг 2: блокировка заказа занята дольше order_lock_timeout —
     * задание повторяется по политике очереди, запись и письмо не
     * затрагиваются (попытки отправки не выполнялось). Таймаут обнулён
     * конфигом — отказ мгновенный (паттерн OrderLockTest, T-027);
     * предупреждение с машинным токеном — NFR-06.
     */
    public function test_busy_order_lock_reschedules_job_without_touching_notification(): void
    {
        Mail::fake();
        config(['marketplace.order_lock_timeout' => 0]);

        $notification = $this->createPaidPendingNotification();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($notification): bool {
                $this->assertStringContainsString('order_busy_retry_scheduled', $message);

                return $context['reason'] === 'order_busy_retry_scheduled'
                    && $context['notification_id'] === $notification->id;
            });

        $lock = Cache::lock('order:' . $notification->order_id, 10);
        $this->assertTrue($lock->get(), 'Тестовая подготовка: блокировка заказа занята тестом.');

        try {
            (new SendOrderNotification($notification->id))->handle();
        } finally {
            $lock->release();
        }

        Mail::assertNothingSent();

        $fresh = $notification->fresh();
        $this->assertSame(NotificationStatus::PENDING, $fresh->status, 'Занятая блокировка не меняет состояние записи.');
        $this->assertSame(0, $fresh->attempts, 'Попытка отправки не выполнялась.');
        $this->assertNull($fresh->sent_at);
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
