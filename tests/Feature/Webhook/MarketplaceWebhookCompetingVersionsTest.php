<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Enums\EventOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MutatedWebhookBody;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-019. Конкурирующие версии одного заказа (§6.2, S-20 e2e) и
 * дедупликация shipped-писем по трек-номеру (FR-MAIL-02, S-11):
 *
 * - S-20: два разных снимка с ОДИНАКОВЫМ order.updated_at — итог
 *   детерминирован кортежем (event.created_at, event.id) независимо от
 *   порядка доставки; повтор (проигравшего и победителя) результата не
 *   меняет (дубль — duplicate, проигравший — stale);
 * - S-11: после отката `shipped → paid` повторное shipped с тем же
 *   tracking number не создаёт второе письмо (UNIQUE dedupe_key),
 *   с другим — создаёт (A-24);
 *
 * Не дублируется: компаратор версий сам по себе — unit-набор
 * SnapshotVersionComparatorTest (T-016, включая tie-break);
 * последовательный дубль одного event.id — DuplicateDeliveryTest
 * (T-017, S-02/S-03); S-19 (моменты vs настенное время) —
 * SnapshotApplicationTest (T-017). Истинно параллельная доставка
 * (S-04: N одновременных POST одного события) — процессный P1-тест,
 * план в docs/testing.md (§11 ТЗ).
 *
 * Красная фаза: применение — заглушки T-021→T-023/T-026/T-028/T-032;
 * падения — только утверждениями о будущем поведении.
 */
class MarketplaceWebhookCompetingVersionsTest extends TestCase
{
    use PostsSignedWebhooks;
    use RefreshDatabase;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    private const string ORDER_ID = 'ord_7Q2KX9M4';

    /** Общий order.updated_at конкурирующих снимков (S-20). */
    private const string TIE_UPDATED_AT = '2026-10-22T10:00:00+00:00';

    private const string TIE_LOSER_EVENT_ID = 'evt_01JBTIEAAA00000000000000A';

    private const string TIE_WINNER_EVENT_ID = 'evt_01JBTIEBBB00000000000000B';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
            'app.debug' => false,
        ]);

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * S-20: победитель по кортежу (более поздний event.created_at)
     * применяется даже когда проигравший пришёл раньше; повторы
     * победителя (duplicate) и проигравшего (stale) итог не меняют.
     */
    public function test_tiebreak_winner_applies_regardless_of_arrival_order_and_repeats(): void
    {
        $loser = $this->competingSnapshot(self::TIE_LOSER_EVENT_ID, '2026-10-22T10:00:01+00:00');
        $winner = $this->competingSnapshot(self::TIE_WINNER_EVENT_ID, '2026-10-22T10:00:02+00:00');

        $first = $this->postSignedWebhook(SignedWebhook::forBody($loser));
        $this->assertSame(['status' => 'processed'], $first->json(), 'Первый прибывший снимок применяется (заказа ещё нет).');

        $second = $this->postSignedWebhook(SignedWebhook::forBody($winner));
        $this->assertSame(['status' => 'processed'], $second->json(), 'Снимок с более поздним event.created_at новее по кортежу и применяется.');

        $this->assertDatabaseHas('orders', [
            'id' => self::ORDER_ID,
            'total' => '5001.00',
            'version_event_id' => self::TIE_WINNER_EVENT_ID,
        ]);

        // Повтор победителя — duplicate, повтор проигравшего — stale;
        // итог не меняется (S-20: «повтор не меняет результат»).
        $winnerRepeat = $this->postSignedWebhook(SignedWebhook::forBody($winner));
        $this->assertSame(['status' => 'duplicate'], $winnerRepeat->json());

        $loserRepeat = $this->postSignedWebhook(SignedWebhook::forBody($loser));
        $this->assertSame(['status' => 'stale'], $loserRepeat->json());

        $this->assertDatabaseHas('orders', [
            'id' => self::ORDER_ID,
            'total' => '5001.00',
            'version_event_id' => self::TIE_WINNER_EVENT_ID,
        ]);
        $this->assertSame(1, \App\Models\Order::query()->whereKey(self::ORDER_ID)->count());
    }

    /**
     * S-20 (зеркальный порядок): победитель по кортежу пришёл первым —
     * опоздавший проигравший stale, финальная версия не меняется.
     */
    public function test_older_competing_version_arriving_last_is_stale(): void
    {
        $loser = $this->competingSnapshot(self::TIE_LOSER_EVENT_ID, '2026-10-22T10:00:01+00:00');
        $winner = $this->competingSnapshot(self::TIE_WINNER_EVENT_ID, '2026-10-22T10:00:02+00:00');

        $first = $this->postSignedWebhook(SignedWebhook::forBody($winner));
        $this->assertSame(['status' => 'processed'], $first->json());

        $second = $this->postSignedWebhook(SignedWebhook::forBody($loser));
        $this->assertSame(['status' => 'stale'], $second->json(), 'Проигравший по кортежу stale при любом порядке доставки.');

        $this->assertDatabaseHas('orders', [
            'id' => self::ORDER_ID,
            'total' => '5001.00',
            'version_event_id' => self::TIE_WINNER_EVENT_ID,
        ]);
        $this->assertDatabaseHas('webhook_events', [
            'id' => self::TIE_LOSER_EVENT_ID,
            'outcome' => EventOutcome::STALE->value,
        ]);
    }

    /**
     * S-11 (A-24): полный сценарий — применён shipped, затем ручная
     * коррекция `order.updated` со status=paid (откат, shipment=null),
     * затем СВЕЖИЙ shipped с ТЕМ ЖЕ tracking number. Повторный shipped
     * — настоящий переход статуса (письмо-триггер срабатывает), но
     * UNIQUE dedupe_key `{order}:shipped:{tracking}` блокирует вторую
     * запись (INSERT IGNORE, FR-MAIL-02): остаётся одно уведомление.
     * Откатный шаг обязателен: shipped → shipped без смены статуса
     * письма не создаёт по правилу триггеров §6.5 (S-31) — без него
     * дедупликация не задействуется вовсе (ревью T-019, P1-1/P2-1).
     */
    public function test_same_tracking_number_does_not_create_second_shipped_notification(): void
    {
        $first = $this->postSignedWebhook(SignedWebhook::forExample('order-shipped'));
        $this->assertSame(['status' => 'processed'], $first->json());
        $this->assertDatabaseCount('order_notifications', 1);

        $rollback = $this->postSignedWebhook(SignedWebhook::forBody($this->rollbackToPaidBody()));
        $this->assertSame(['status' => 'processed'], $rollback->json(), 'Откат shipped → paid применяется (S-37).');
        $this->assertDatabaseCount('order_notifications', 1, 'Откат не порождает письма-исправления (Q28).');

        $sameTrack = MutatedWebhookBody::fromExample('order-shipped')
            ->with('id', 'evt_01JBSHIPAGAIN00000000000000')
            ->with('created_at', '2026-10-22T10:00:01+00:00')
            ->withUpdatedAt('2026-10-22T10:00:00+00:00')
            ->body();

        $second = $this->postSignedWebhook(SignedWebhook::forBody($sameTrack));

        $this->assertSame(['status' => 'processed'], $second->json(), 'Свежий снимок применяется.');
        $this->assertDatabaseCount('order_notifications', 1, 'Тот же трек-номер — письмо не дублируется (FR-MAIL-02).');
        $this->assertDatabaseHas('order_notifications', [
            'order_id' => self::ORDER_ID,
            'type' => 'shipped',
            'dedupe_key' => self::ORDER_ID . ':shipped:1098765432',
        ]);
    }

    /**
     * S-11 (A-24): тот же сценарий с откатом, но повторный shipped —
     * с ДРУГИМ tracking number: переход статуса + свободный dedupe-ключ
     * → второе уведомление с собственным ключом.
     */
    public function test_different_tracking_number_creates_new_shipped_notification(): void
    {
        $first = $this->postSignedWebhook(SignedWebhook::forExample('order-shipped'));
        $this->assertSame(['status' => 'processed'], $first->json());

        $rollback = $this->postSignedWebhook(SignedWebhook::forBody($this->rollbackToPaidBody()));
        $this->assertSame(['status' => 'processed'], $rollback->json());

        $otherTrack = MutatedWebhookBody::fromExample('order-shipped')
            ->with('id', 'evt_01JBSHIPNEWTRACK0000000000')
            ->with('created_at', '2026-10-22T10:00:01+00:00')
            ->withUpdatedAt('2026-10-22T10:00:00+00:00')
            ->withPath(['data', 'order', 'shipment', 'tracking_number'], '5550001111')
            ->withPath(['data', 'order', 'shipment', 'tracking_url'], 'https://track.example.com/5550001111')
            ->body();

        $second = $this->postSignedWebhook(SignedWebhook::forBody($otherTrack));

        $this->assertSame(['status' => 'processed'], $second->json());
        $this->assertDatabaseCount('order_notifications', 2, 'Новый трек-номер — новое письмо (A-24).');
        $this->assertDatabaseHas('order_notifications', [
            'order_id' => self::ORDER_ID,
            'type' => 'shipped',
            'dedupe_key' => self::ORDER_ID . ':shipped:5550001111',
        ]);
    }

    /**
     * Откатный шаг S-11/S-37: свежая коррекция `order.updated`
     * со status=paid и shipment=null (в примере order-updated
     * shipment отсутствует) — момент 09:00 UTC, строго новее
     * применённого shipped (06:40:03Z) и старее повторного shipped
     * (10:00 UTC).
     *
     * @return string байты мутанта
     */
    private function rollbackToPaidBody(): string
    {
        return MutatedWebhookBody::fromExample('order-updated')
            ->withStatus('paid')
            ->withPath(['data', 'order', 'paid_at'], '2026-10-21T10:05:47+00:00')
            ->with('id', 'evt_01JBROLLBACKPAID0000000000000')
            ->with('created_at', '2026-10-22T09:00:01+00:00')
            ->withUpdatedAt('2026-10-22T09:00:00+00:00')
            ->body();
    }

    /**
     * Конкурирующий снимок S-20: общий order.updated_at, свой
     * event.id/event.created_at (компоненты кортежа), итоговая
     * distinguishable-сумма у победителя.
     *
     * @return string байты мутанта
     */
    private function competingSnapshot(string $eventId, string $eventCreatedAt): string
    {
        $mutant = MutatedWebhookBody::fromExample('order-paid')
            ->with('id', $eventId)
            ->with('created_at', $eventCreatedAt)
            ->withUpdatedAt(self::TIE_UPDATED_AT);

        if ($eventId === self::TIE_WINNER_EVENT_ID) {
            $mutant = $mutant->withPath(['data', 'order', 'total'], '5001.00');
        }

        return $mutant->body();
    }
}
