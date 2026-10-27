<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Webhooks\ProcessMarketplaceEvent;
use App\Data\IncomingWebhook;
use Tests\TestCase;

/**
 * ProcessMarketplaceEvent (T-010): скелет фиксирует порядок §7 и не
 * исполняет его фиктивно успешно — первый нереализованный шаг (разбор
 * конверта, T-021) бросает LogicException, а не возвращает результат,
 * изображающий `200 processed` (правило этапа 2).
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

    public function test_handle_refuses_to_fake_processing_until_parser_exists(): void
    {
        $action = $this->app->make(ProcessMarketplaceEvent::class);

        $webhook = new IncomingWebhook(
            webhookId: 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            signatureTimestamp: 1792577149,
            rawBody: '{"id":"evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC","type":"order.paid"}',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-021');

        $action->handle($webhook);
    }
}
