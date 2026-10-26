<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\IncomingWebhook;
use PHPUnit\Framework\TestCase;

/**
 * IncomingWebhook (T-010): контекст доставки, изолирующий Action от
 * HTTP-слоя. Сырое тело хранится байт в байт — включая финальный перевод
 * строки и неэкранированную кириллицу (T1: как подписывает send-event.php).
 */
class IncomingWebhookTest extends TestCase
{
    public function test_holds_delivery_context_asis(): void
    {
        $rawBody = '{"id":"evt_тест"}' . "\n";

        $webhook = new IncomingWebhook(
            webhookId: 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            signatureTimestamp: 1792577149,
            rawBody: $rawBody,
        );

        $this->assertSame('evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC', $webhook->webhookId);
        $this->assertSame(1792577149, $webhook->signatureTimestamp);
        $this->assertSame($rawBody, $webhook->rawBody);
    }
}
