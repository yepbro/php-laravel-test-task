<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Support\NotificationDedupeKey;
use App\Support\NotificationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Отправка письма покупателю по outbox-записи — алгоритм §7 ТЗ
 * «SendOrderNotification» (каркас T-012, механика — T-034).
 *
 * Задание ставится в очередь после коммита транзакции, создавшей
 * outbox-запись (`afterCommit`, FR-MAIL-01). Идентификатор — скалярный
 * int, а не модель: тело письма строится по ТЕКУЩЕМУ состоянию заказа
 * (§6.5), поэтому запись обязана загружаться в handle() заново;
 * сериализованная модель создавала бы иллюзию актуальности, а
 * SerializesModels под капотом всё равно восстановил бы её по ключу.
 *
 * Повторная постановка безопасна по построению: шаг 1 выходит на любом
 * не-pending статусе, а захват строки атомарен (FR-MAIL-04) — sweeper
 * (FR-MAIL-06) и первичный dispatch могут сосуществовать.
 *
 * Контур конкурентности — тот же, что у обработчика вебхука
 * (FR-MAIL-03): Redis-lock `order:{id}` берётся до проверок и держится
 * до фиксации результата SMTP-вызова, поэтому вебхук не может сменить
 * состояние заказа между проверкой и отправкой. Внутри — короткая
 * транзакция с `SELECT … FOR UPDATE` строки заказа (вторая линия
 * защиты, §7 шаг 3). Сеть (SMTP) выполняется вне транзакции БД —
 * транзакция не держит соединений дольше собственных операций
 * (правило T-027).
 */
final class SendOrderNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Причина пропуска: статус заказа вне матрицы допустимости §6.5 (FR-MAIL-03, S-10). */
    private const string SKIP_SUPERSEDED = 'superseded';

    /** Причина пропуска: невалидный email получателя (FR-MAIL-07). */
    private const string SKIP_INVALID_RECIPIENT = 'invalid_recipient';

    /** Причина пропуска: у shipped-письма нет shipment в текущем состоянии (FR-MAIL-08). */
    private const string SKIP_NO_SHIPMENT = 'no_shipment';

    /** Причина пропуска: dedupe-ключ текущего трека занят другой записью (FR-MAIL-02, S-38). */
    private const string SKIP_DUPLICATE_TRACKING = 'duplicate_tracking';

    /**
     * Максимум попыток выполнения задания (FR-MAIL-05: 5). Значение —
     * из config/marketplace.php (NOTIFICATIONS_TRIES), фиксируется в
     * конструкторе при создании задания в контексте приложения; им же
     * ограничиваются попытки ОТПРАВКИ ПИСЬМА в шаге 5 (§7).
     */
    public int $tries;

    public function __construct(
        public readonly int $notificationId,
    ) {
        $this->tries = max(1, (int) config('marketplace.notifications.max_attempts', 5));

        // Ставится в очередь только после коммита транзакции, создавшей
        // outbox-запись (FR-MAIL-01). Свойство объявлено в трейте Queueable
        // без типа и дефолта (bool|null); переобъявление в классе
        // несовместимо по типизации — назначаем в конструкторе.
        $this->afterCommit = true;
    }

    /**
     * Паузы между попытками очереди: 10 с, 1 мин, 5 мин, 15 мин
     * (FR-MAIL-05). Читается конфиг в момент повтора, а не при создании
     * задания — воркер всегда видит актуальные значения.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('marketplace.notifications.backoff', []);

        return $backoff;
    }

    /**
     * Выполнение задания по шагам §7. Временная ошибка SMTP гасится
     * здесь: запись сама возвращается в `pending` и задание повторно
     * ставится в очередь с задержкой backoff (§7 шаг 5, FR-MAIL-05) —
     * без проброса исключения, чтобы штатный повтор не репортился
     * как сбой (наблюдаемость — warning, NFR-06). Прочие исключения
     * пробрасываются: повторами управляет очередь, исчерпание попыток —
     * failed().
     */
    public function handle(): void
    {
        $notification = OrderNotification::query()->find($this->notificationId);

        // Шаг 1 §7: записи нет (удалена вместе с заказом) или уже не
        // pending (захвачена/завершена другим воркером) — тихий выход.
        if ($notification === null || $notification->status !== NotificationStatus::PENDING) {
            return;
        }

        // Шаг 2 §7: тот же Redis-lock `order:{id}`, что и обработчик
        // вебхука; lease (15 с) превышает SMTP timeout — lock не истечёт
        // во время отправки (FR-MAIL-03).
        $lock = Cache::lock(
            'order:' . $notification->order_id,
            (int) config('marketplace.order_lock_lease'),
        );

        try {
            try {
                $lock->block((int) config('marketplace.order_lock_timeout'));
            } catch (LockTimeoutException) {
                $this->rescheduleWhileOrderIsBusy($notification);

                return;
            }

            // Шаг 3 §7: повторная проверка допустимости по ТЕКУЩЕМУ
            // состоянию заказа под блокировкой; недопустимое письмо
            // помечается skipped с освобождением dedupe_key.
            $order = $this->preflight($notification);

            if ($order === null) {
                return;
            }

            // Шаг 4 §7: атомарный захват записи; 0 строк — другой
            // воркер уже отправляет это письмо (FR-MAIL-04).
            if (! $this->captureForSending($notification)) {
                return;
            }

            // Шаг 5 §7: отправка и фиксация результата под lock.
            $this->deliverAndRecord($notification, $order);
        } finally {
            // §7 шаг 5: «освободить lock в finally» — на все исходы.
            // Авария Redis в этом окне (заметка ревью T-027, P3-4: здесь
            // оно шире — SMTP) не должна заменять результат отправки:
            // отказ доброкачественный, владение истечёт по lease.
            $this->releaseLockQuietly($lock, $notification);
        }
    }

    /**
     * Терминальная ветвь исчерпания попыток очереди (FR-MAIL-05):
     * Laravel вызывает failed() на НОВОМ инстансе задания — мутации
     * handle() недоступны, запись перезагружается по notificationId
     * (правило queue-jobs скилла laravel-best-practices).
     *
     * В `failed` переводится только запись в `sending` (карта переходов
     * T-012): попытка всегда проходит через захват, `pending → failed`
     * запрещён. Запись в `pending` (сбой до захвата — БД, блокировка)
     * не теряется: sweeper (FR-MAIL-06, T-035) переставит её в очередь,
     * а счётчик attempts письма не обязан совпадать со счётчиком
     * попыток этого конкретного задания. Терминальные статусы
     * не перезаписываются.
     */
    public function failed(?Throwable $exception): void
    {
        $notification = OrderNotification::query()->find($this->notificationId);

        if ($notification === null || ! $notification->status->canTransitionTo(NotificationStatus::FAILED)) {
            return;
        }

        OrderNotification::query()
            ->whereKey($notification->id)
            ->update([
                'status' => NotificationStatus::FAILED,
                'last_error' => $exception === null ? null : mb_substr($exception->getMessage(), 0, 200),
            ]);

        Log::warning('Задание отправки уведомления исчерпало попытки очереди (notification_job_failed): письмо не отправлено.', [
            'reason' => 'notification_job_failed',
            'notification_id' => $notification->id,
            'order_id' => $notification->order_id,
            'attempts' => $notification->attempts,
            'error_message' => $exception === null ? null : $this->maskedForLog($exception->getMessage()),
        ]);
    }

    /**
     * Шаг 3 §7: предотправочные проверки в транзакции с блокировкой
     * строки заказа `FOR UPDATE` (вторая линия защиты после Redis-lock,
     * FR-MAIL-03/04). Все проверки — по ТЕКУЩЕМУ состоянию заказа,
     * загруженному здесь же: тело письма строится по нему (§6.5),
     * а не по снимку события-триггера.
     *
     * Порядок проверок: матрица допустимости (FR-MAIL-03) → email
     * (FR-MAIL-07) → shipment (FR-MAIL-08) → сверка dedupe-ключа с
     * текущим треком (FR-MAIL-02). Недопустимое письмо помечается
     * `skipped` с причиной и освобождением dedupe_key прямо в этой
     * транзакции.
     *
     * Возвращает заказ для отправки; null — отправка не требуется
     * (пропуск/невозможимая ветка).
     */
    private function preflight(OrderNotification $notification): ?Order
    {
        return DB::connection()->transaction(function () use ($notification): ?Order {
            $order = Order::query()->lockForUpdate()->find($notification->order_id);

            if ($order === null) {
                // Защитная ветка: FK order_notifications.order_id
                // ON DELETE CASCADE — записи без заказа не существует,
                // сюда можно попасть только между шагом 1 и транзакцией.
                return null;
            }

            $policy = new NotificationPolicy();

            if (! $policy->allows($notification->type, $order->status)) {
                $this->skip($notification, self::SKIP_SUPERSEDED);

                return null;
            }

            if (filter_var($order->customer_email, FILTER_VALIDATE_EMAIL) === false) {
                $this->skip($notification, self::SKIP_INVALID_RECIPIENT);

                return null;
            }

            if ($notification->type === NotificationType::SHIPPED && $order->shipment_tracking_number === null) {
                $this->skip($notification, self::SKIP_NO_SHIPMENT);

                return null;
            }

            if (! $this->syncDedupeKeyWithCurrentTracking($notification, $order)) {
                $this->skip($notification, self::SKIP_DUPLICATE_TRACKING);

                return null;
            }

            return $order;
        });
    }

    /**
     * Сверка dedupe-ключа shipped-письма с трек-номером ТЕКУЩЕГО
     * состояния (FR-MAIL-02, S-38): ключ мог быть записан по треку
     * события-триггера, а трек с тех пор изменился. Ключи `paid`/
     * `cancelled` от трека не зависят и сверяются только на занятость
     * при создании записи (T-032).
     *
     * Изменившийся ключ обновляется атомарно; если целевой ключ уже
     * занят другой записью — письмо пропускается (`duplicate_tracking`):
     * этот трек уже отправлялся. Перехват точного
     * UniqueConstraintViolationException — страховка гонки вставки
     * (по образцу T-032): PREFLIGHT выполняется под Redis-lock заказа,
     * но UNIQUE — последняя линия защиты БД.
     */
    private function syncDedupeKeyWithCurrentTracking(OrderNotification $notification, Order $order): bool
    {
        if ($notification->type !== NotificationType::SHIPPED) {
            return true;
        }

        $targetKey = NotificationDedupeKey::for(
            $notification->type,
            $order->id,
            $order->shipment_tracking_number,
        );

        if ($notification->dedupe_key === $targetKey) {
            return true;
        }

        $keyTaken = OrderNotification::query()
            ->where('dedupe_key', $targetKey)
            ->whereKeyNot($notification->id)
            ->exists();

        if ($keyTaken) {
            return false;
        }

        try {
            OrderNotification::query()
                ->whereKey($notification->id)
                ->update(['dedupe_key' => $targetKey]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Шаг 4 §7: атомарный захват записи —
     * `UPDATE … SET status='sending' WHERE id=? AND status='pending'`;
     * 0 затронутых строк — выход (FR-MAIL-04: два воркера не отправят
     * одно письмо; распределённый lock не заменяет захват строки БД —
     * нужны оба механизма).
     *
     * После захвата запись в `sending` эксклюзивна (конкуренты выходят
     * на шаге 1 или здесь) — снимок модели обновляется, чтобы счётчик
     * attempts не занизился: конкурент между шагом 1 этого воркера
     * и захватом мог выполнить полный цикл «захват → ошибка SMTP →
     * attempts++ → pending → release» (счётчики двух живых заданий
     * одной записи — sweeper/queue:retry; ревью T-034, P2-1).
     */
    private function captureForSending(OrderNotification $notification): bool
    {
        $captured = OrderNotification::query()
            ->whereKey($notification->id)
            ->where('status', NotificationStatus::PENDING->value)
            ->update(['status' => NotificationStatus::SENDING]);

        if ($captured !== 1) {
            return false;
        }

        $notification->refresh();

        return true;
    }

    /**
     * Шаг 5 §7: отправка plain-text письма с данными текущего заказа
     * и фиксация результата — `sent`/`sent_at` либо `pending` +
     * attempts/backoff, после исчерпания лимита — `failed`
     * (FR-MAIL-05). Мейблы синхронные (ShouldQueue на них не вводится):
     * задание обязано сразу знать результат отправки для статусов
     * outbox — очередь уже сама, это SendOrderNotification.
     */
    private function deliverAndRecord(OrderNotification $notification, Order $order): void
    {
        $mailable = $this->mailableFor($notification->type, $order);

        try {
            Mail::to($order->customer_email)->send($mailable);
        } catch (TransportExceptionInterface $exception) {
            $this->recordTemporaryFailure($notification, $exception);

            return;
        }

        OrderNotification::query()
            ->whereKey($notification->id)
            ->update([
                'status' => NotificationStatus::SENT,
                'attempts' => $notification->attempts + 1,
                'recipient' => $order->customer_email,
                'subject' => $mailable->envelope()->subject,
                'last_error' => null,
                'sent_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * Временная ошибка SMTP (FR-MAIL-05): attempts++ и возврат в
     * `pending` с повтором по backoff; после лимита попыток — `failed`
     * с последней ошибкой. Письмо остаётся в outbox — данные не
     * теряются (NFR-05).
     */
    private function recordTemporaryFailure(
        OrderNotification $notification,
        TransportExceptionInterface $exception,
    ): void {
        $attempts = $notification->attempts + 1;

        $limitReached = $attempts >= $this->tries;

        OrderNotification::query()
            ->whereKey($notification->id)
            ->update([
                'status' => $limitReached ? NotificationStatus::FAILED : NotificationStatus::PENDING,
                'attempts' => $attempts,
                'last_error' => mb_substr($exception->getMessage(), 0, 200),
            ]);

        if ($limitReached) {
            Log::warning('Попытки отправки уведомления исчерпаны (notification_failed): письмо не отправлено.', [
                'reason' => 'notification_failed',
                'notification_id' => $notification->id,
                'order_id' => $notification->order_id,
                'attempts' => $attempts,
                'error_message' => $this->maskedForLog($exception->getMessage()),
            ]);

            return;
        }

        Log::warning('Временная ошибка отправки письма (notification_retry_scheduled): повтор по backoff.', [
            'reason' => 'notification_retry_scheduled',
            'notification_id' => $notification->id,
            'order_id' => $notification->order_id,
            'attempts' => $attempts,
            'retry_delay' => $this->retryDelay($attempts),
            'error_message' => $this->maskedForLog($exception->getMessage()),
        ]);

        $this->release($this->retryDelay($attempts));
    }

    /**
     * Задержка повтора после $attempts-й неудачной попытки отправки
     * (FR-MAIL-05: 10 с / 1 мин / 5 мин / 15 мин). Попыткам за пределами
     * списка — последний интервал; пустой конфиг — немедленный повтор
     * (политику попыток продолжает держать $tries).
     */
    private function retryDelay(int $attempts): int
    {
        $backoff = $this->backoff();

        if ($backoff === []) {
            return 0;
        }

        return $backoff[min($attempts, count($backoff)) - 1];
    }

    /**
     * Маскирование email в диагностике для логов (NFR-04: email не
     * пишется в логи в открытом виде): ответы SMTP стадии RCPT цитируют
     * адрес получателя — в warning уходит замаскированный текст
     * (урезанный до 200 символов, как и last_error). В БД last_error
     * сохраняется дословно: адрес получателя и так хранится в колонке
     * recipient, колонка диагностики — не лог.
     */
    private function maskedForLog(string $message): string
    {
        $masked = preg_replace('/[^\s<>"\']+@[^\s<>"\']+/u', '***@***', mb_substr($message, 0, 200));

        return $masked ?? $message;
    }

    /**
     * Блокировка заказа не получена за order_lock_timeout (§7 шаг 2:
     * «повторить задачу по политике очереди»): задание возвращается
     * в очередь с первым интервалом backoff — попытка отправки письма
     * не выполнялась, счётчик attempts записи не растёт, длинные паузы
     * не требуются. Исчерпания попыток самой очереди (release считает
     * их) запись не портят: failed() не трогает `pending`, письмо
     * переставит sweeper (FR-MAIL-06).
     */
    private function rescheduleWhileOrderIsBusy(OrderNotification $notification): void
    {
        $backoff = $this->backoff();

        Log::warning('Блокировка заказа занята дольше order_lock_timeout (order_busy_retry_scheduled): задание повторено по политике очереди.', [
            'reason' => 'order_busy_retry_scheduled',
            'notification_id' => $notification->id,
            'order_id' => $notification->order_id,
            'retry_delay' => $backoff[0] ?? 0,
        ]);

        $this->release($backoff[0] ?? 0);
    }

    /**
     * Пропуск отправки (FR-MAIL-03/07/08, §7 шаг 3): терминальный
     * `skipped` с причиной и освобождением dedupe_key — недопустимое
     * письмо больше не должно занимать ключ дедупликации. Условие
     * `status = pending` — захват мог уйти конкурентному воркеру;
     * warning NFR-06: только идентификаторы и машинный токен причины.
     */
    private function skip(OrderNotification $notification, string $reason): void
    {
        OrderNotification::query()
            ->whereKey($notification->id)
            ->where('status', NotificationStatus::PENDING->value)
            ->update([
                'status' => NotificationStatus::SKIPPED,
                'skip_reason' => $reason,
                'dedupe_key' => null,
            ]);

        Log::warning(sprintf('Отправка письма пропущена (notification_skipped): %s.', $this->skipReasonText($reason)), [
            'reason' => $reason,
            'notification_id' => $notification->id,
            'order_id' => $notification->order_id,
            'type' => $notification->type->value,
        ]);
    }

    /**
     * Формулировка причины пропуска для warning (NFR-06): причина
     * читается из текста лога однозначно, без разбора контекста —
     * по образцу logRepeatableRefusal (T-027).
     */
    private function skipReasonText(string $reason): string
    {
        return match ($reason) {
            self::SKIP_SUPERSEDED => 'текущий статус заказа вне матрицы допустимости §6.5',
            self::SKIP_INVALID_RECIPIENT => 'невалидный email получателя в текущем состоянии заказа',
            self::SKIP_NO_SHIPMENT => 'у shipped-письма нет shipment в текущем состоянии заказа',
            self::SKIP_DUPLICATE_TRACKING => 'dedupe-ключ текущего трек-номера уже занят другой записью',
            default => throw new \LogicException(sprintf(
                'Отсутствует формулировка предупреждения для причины %s — добавьте её.',
                $reason,
            )),
        };
    }

    /**
     * Освобождение блокировки заказа в finally на все исходы (§7 шаг 5).
     * Авария хранилища блокировок (Redis недоступен) не заменяет
     * результат отправки: исключение из release() гасится с warning,
     * владение истечёт само по lease (order_lock_lease) — решение
     * заметки ревью T-027 P3-4 для job (окно здесь шире: SMTP внутри).
     */
    private function releaseLockQuietly(Lock $lock, OrderNotification $notification): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            Log::warning('Не удалось освободить блокировку заказа после отправки письма (lock_release_failed): владение истечёт по lease.', [
                'reason' => 'lock_release_failed',
                'notification_id' => $notification->id,
                'order_id' => $notification->order_id,
                'error' => $exception::class,
            ]);
        }
    }

    /**
     * Письмо нужного типа по текущему состоянию заказа (§6.5): темы и
     * тела — T-033, конструктор — только Order (получателя задаёт
     * Mail::to в deliverAndRecord).
     */
    private function mailableFor(NotificationType $type, Order $order): OrderPaidMail|OrderShippedMail|OrderCancelledMail
    {
        return match ($type) {
            NotificationType::PAID => new OrderPaidMail($order),
            NotificationType::SHIPPED => new OrderShippedMail($order),
            NotificationType::CANCELLED => new OrderCancelledMail($order),
        };
    }
}
