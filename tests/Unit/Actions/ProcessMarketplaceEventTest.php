<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Webhooks\ProcessMarketplaceEvent;
use App\Data\IncomingWebhook;
use App\Enums\PayloadIssue;
use App\Exceptions\Webhook\MalformedPayload;
use Tests\Support\MarketplaceExamples;
use Tests\Support\MutatedWebhookBody;
use Tests\TestCase;

/**
 * ProcessMarketplaceEvent (T-010/T-021): разбор тела реализован парсером
 * (T-021), поэтому Action доводит валидное событие ровно до первого
 * нереализованного транзакционного шага и бросает его заглушку —
 * фиктивный `200 processed` по-прежнему невозможен (правило этапа 2).
 *
 * Оркестрация ветвится по результату разбора (§7 шаги 8/10/12):
 * неизвестный тип → журналирование (T-026); известный тип с валидным
 * снимком → применение под блокировкой (T-027…T-032); невалидное тело →
 * MalformedPayload от парсера (400/422, до журналирования).
 *
 * Полные HTTP-контракты тех же веток — feature-наборы T-017 (зеленеют
 * после T-024/T-026); здесь — маршрутизация Action по данным конверта.
 */
class ProcessMarketplaceEventTest extends TestCase
{
    public function test_action_is_resolvable_from_container(): void
    {
        $this->assertInstanceOf(
            ProcessMarketplaceEvent::class,
            $this->app->make(ProcessMarketplaceEvent::class),
        );
    }

    /**
     * Валидное событие известного типа с корректным снимком проходит
     * разбор (T-021) и упирается в заглушку применения (шаги 12–14 §7).
     */
    public function test_known_type_valid_snapshot_reaches_application_stub(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-027');

        $action->handle($this->paidWebhook());
    }

    /**
     * Неизвестный тип разбирается без снимка (FR-WH-07) и упирается в
     * заглушку журналирования ignored-события (шаг 8 §7, T-026).
     */
    public function test_unknown_type_reaches_journal_stub(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-026');

        $action->handle(new IncomingWebhook(
            webhookId: 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            signatureTimestamp: 1792577149,
            rawBody: '{"id":"evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC","type":"order.refunded",'
                . '"created_at":"2026-10-21T10:05:48+00:00","data":{}}',
        ));
    }

    /**
     * Известный тип с неизвестным строковым статусом разбирается без
     * снимка (FR-WH-07/S-36) и упирается в заглушку журналирования
     * ignored-события с order_id (шаг 10 §7, T-026) — отдельная от
     * unknown type ветка маршрутизации (ревью r2, P3).
     */
    public function test_unknown_status_reaches_journal_stub(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('неизвестного статуса');

        $action->handle(new IncomingWebhook(
            webhookId: 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K',
            signatureTimestamp: 1792577149,
            rawBody: MutatedWebhookBody::fromExample('order-updated')
                ->withStatus('returned')
                ->body(),
        ));
    }

    /**
     * Невалидное тело отбрасывается парсером до заглушек журнала:
     * MalformedPayload с типизированной причиной (FR-WH-05/06/08).
     */
    public function test_invalid_body_is_rejected_by_parser_before_journal_stub(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        try {
            $action->handle(new IncomingWebhook(
                webhookId: 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
                signatureTimestamp: 1792577149,
                rawBody: '{"id":"evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC","type":"order.paid"}',
            ));

            $this->fail('Ожидался MalformedPayload(InvalidEnvelope).');
        } catch (MalformedPayload $exception) {
            $this->assertSame(PayloadIssue::InvalidEnvelope, $exception->issue);
        }
    }

    /** Контекст доставки: пример order-paid, подписанный идентификатор события из тела. */
    private function paidWebhook(): IncomingWebhook
    {
        return new IncomingWebhook(
            webhookId: 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            signatureTimestamp: 1792577149,
            rawBody: MarketplaceExamples::raw('order-paid'),
        );
    }
}
