<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Tests\Support\MarketplaceExamples;
use Tests\Support\MutatedWebhookBody;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-015. Билдер мутантных тел: неизвестный type (S-18), неизвестный
 * status (S-36), управляемый data.order.updated_at (FR-ORD-02) —
 * на основе сырых примеров, с сохранением остальной структуры.
 *
 * Мутанты сознательно НЕ байт-в-байт примеры: для мутаций неизбежна
 * пересериализация (json_decode → изменение → json_encode c
 * неэкранированными кириллицей и слэшами); решение зафиксировано
 * в DECISIONS.md (задача 015). Подписываются мутанты по собственным
 * байтам — SignedWebhook::forBody принимает их как обычное тело.
 */
class MutatedWebhookBodyTest extends TestCase
{
    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    /** Неизвестный тип события: меняется только type, остальное сохранено. */
    public function test_unknown_type_replaces_only_type(): void
    {
        $body = MutatedWebhookBody::fromExample('order-paid')->withType('order.refunded')->body();
        $decoded = json_decode($body, true);

        $this->assertSame('order.refunded', $decoded['type']);
        $this->assertSame('evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC', $decoded['id']);
        $this->assertSame('paid', $decoded['data']['order']['status']);
        $this->assertSame('ord_7Q2KX9M4', $decoded['data']['order']['id']);
    }

    /** Неизвестный статус: меняется только data.order.status. */
    public function test_unknown_status_replaces_only_status(): void
    {
        $body = MutatedWebhookBody::fromExample('order-updated')->withStatus('returned')->body();
        $decoded = json_decode($body, true);

        $this->assertSame('returned', $decoded['data']['order']['status']);
        $this->assertSame('order.updated', $decoded['type']);
        $this->assertSame('4909.50', $decoded['data']['order']['total']);
    }

    /** Управляемый updated_at: задан дословно, соседние даты не тронуты. */
    public function test_updated_at_is_set_explicitly(): void
    {
        $iso = '2026-10-22T09:15:00+00:00';

        $body = MutatedWebhookBody::fromExample('order-paid')->withUpdatedAt($iso)->body();
        $order = json_decode($body, true)['data']['order'];

        $this->assertSame($iso, $order['updated_at']);
        $this->assertSame('2026-10-21T13:02:11+03:00', $order['created_at']);
    }

    /** Кодировка как в примерах MarketHub: кириллица и слэши не экранированы. */
    public function test_mutant_keeps_unescaped_unicode_and_slashes(): void
    {
        $body = MutatedWebhookBody::fromExample('order-shipped')->withStatus('delivered')->body();

        $this->assertStringContainsString('Анна Смирнова', $body);
        $this->assertStringNotContainsString('\u0410', $body);
        $this->assertStringContainsString('https://', $body);
        $this->assertStringNotContainsString('\/', $body);
    }

    /** Мутант подписывается как обычное тело и содержит управляемые байты. */
    public function test_mutant_is_signable_as_regular_body(): void
    {
        config(['marketplace.webhook_secret' => self::SECRET]);

        $body = MutatedWebhookBody::fromExample('order-paid')->withType('order.refunded')->body();

        $request = SignedWebhook::forBody($body, 1792577149);

        $this->assertSame($body, $request->content());
        $this->assertSame('order.refunded', json_decode($request->content(), true)['type']);
    }

    /** Билдер иммутабелен: with* не меняют исходный экземпляр. */
    public function test_builder_is_immutable(): void
    {
        $original = MutatedWebhookBody::fromExample('order-paid');

        $original->withType('order.refunded')->withStatus('returned')->withUpdatedAt('2026-10-22T09:15:00+00:00');

        $decoded = json_decode($original->body(), true);

        $this->assertSame('order.paid', $decoded['type']);
        $this->assertSame('paid', $decoded['data']['order']['status']);
        $this->assertSame('2026-10-21T13:05:47+03:00', $decoded['data']['order']['updated_at']);
    }

    /** body() детерминирован: повторная сборка даёт те же байты. */
    public function test_body_is_deterministic(): void
    {
        $builder = MutatedWebhookBody::fromExample('order-cancelled')->withUpdatedAt('2026-10-22T10:00:00+00:00');

        $this->assertSame($builder->body(), $builder->body());
    }

    /** Мутант — не копия примера: у примера есть финальный `\n`, у мутанта его нет. */
    public function test_mutant_is_not_a_byte_copy_of_example(): void
    {
        $body = MutatedWebhookBody::fromExample('order-paid')->body();

        $this->assertNotSame(MarketplaceExamples::raw('order-paid'), $body);
        $this->assertNotSame("\n", substr($body, -1));
    }

    public function test_invalid_json_source_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MutatedWebhookBody::fromRaw('not-json');
    }

    /** Отсутствующий вложенный путь создаётся (например, data.order без status). */
    public function test_missing_path_is_created(): void
    {
        $body = MutatedWebhookBody::fromRaw('{"id":"evt_test"}')->withStatus('paid')->body();

        $this->assertSame('paid', json_decode($body, true)['data']['order']['status']);
    }
}
