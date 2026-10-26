<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AssertsNoWebhookSideEffects;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-017. HTTP-контракт занятой блокировки заказа: 503 (FR-ORD-07).
 *
 * Обработка события берёт общую блокировку `order:{id}` (§7 шаг 12);
 * если она занята (например, параллельной доставкой или отправкой
 * письма), вебхук ждёт не больше order_lock_timeout и отвечает 503 —
 * повторяемой ошибкой, по которой MarketHub повторит доставку.
 *
 * Красная фаза: блокировки — заглушка T-027, сейчас подписанный запрос
 * падает на 500 от заглушек разбора/применения и тест не зелёный
 * на утверждении «503». Технически тест занимает тот же лок, что
 * займёт обработчик: Cache::lock("order:ord_7Q2KX9M4") на дефолтном
 * store тестов (array — ArrayStore реализует LockProvider, отдельный
 * Redis не нужен; изоляция Paratest — per-process store). Ключ и
 * семантика фиксированы FR-ORD-07; таймаут обнуляется в конфиге, чтобы
 * тест не ждал 3 с.
 */
class MarketplaceWebhookOrderLockTest extends TestCase
{
    use RefreshDatabase;
    use PostsSignedWebhooks;
    use AssertsNoWebhookSideEffects;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    private const string ORDER_ID = 'ord_7Q2KX9M4';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
            'marketplace.order_lock_timeout' => 0,
            'app.debug' => false,
        ]);

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /**
     * Лок order:{id} уже удерживается — валидный подписанный вебхук
     * обязан получить 503 (повторяемый отказ), не применяя событие и не
     * журналируя его (вставка события — внутри транзакции под локом,
     * шаги 12–13 §7).
     */
    public function test_busy_order_lock_answers_503_without_side_effects(): void
    {
        $lock = Cache::lock('order:' . self::ORDER_ID, 10);
        $this->assertTrue($lock->get(), 'Тестовая подготовка: лок заказа должен быть занят тестом.');

        try {
            $response = $this->postSignedWebhook(SignedWebhook::forExample('order-paid'));

            $this->assertSame(503, $response->getStatusCode(), 'Занятая блокировка заказа — повторяемый отказ 503 (FR-ORD-07).');
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
            $this->assertNotSame('', (string) $response->json('error.code'), 'Тело 503 — JSON с машинным кодом ошибки.');
            $this->assertNoWebhookSideEffects();
        } finally {
            $lock->release();
        }
    }
}
