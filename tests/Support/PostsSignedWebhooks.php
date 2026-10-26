<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Отправка подписанных вебхуков в Laravel HTTP-тестах (T-015).
 *
 * Тело уходит БАЙТ В БАЙТ через седьмой аргумент call() (строка
 * $content): post()/postJson() не годятся — они перекодируют массив
 * или пересериализуют JSON, теряя финальный `\n` и меняя байты
 * (ловушка §3.1/R-1). Заголовки — server-массив SignedWebhook.
 */
trait PostsSignedWebhooks
{
    /**
     * POST подписанного запроса на маршрут вебхука (по умолчанию —
     * POST /api/webhooks/marketplace, §6.1 ТЗ).
     */
    protected function postSignedWebhook(SignedWebhook $request, string $uri = '/api/webhooks/marketplace'): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], $request->server(), $request->content());
    }
}
