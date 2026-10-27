<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Глобальные защиты сьюта (изолируют каждый тест от внешнего
        // мира и от реального времени):
        //
        // - Http: исходящий запрос без подходящего фейка падает сразу,
        //   не доходя до сети;
        // - Sleep: retry/backoff блокировок и очередей не тратят
        //   реальное время (syncWithCarbon продвигает замороженное
        //   время на длительность sleep);
        // - Exceptions: report() пишется в фейк, а не в реальный
        //   обработчик — рендер ответов (401/422/500) не меняется.
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();
    }
}
