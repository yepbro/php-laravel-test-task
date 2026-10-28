<?php

declare(strict_types=1);

namespace Tests;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    /**
     * Faker-генератор, общий на весь процесс тестов.
     *
     * `Faker\Generator::__destruct()` вызывает `seed()` без аргумента, а тот —
     * глобальный `mt_srand()` со случайным сидом. Laravel создаёт
     * генератор-синглтон в контейнере каждого теста, и при пересоздании
     * приложения старый генератор собирается сборщиком мусора в произвольной
     * точке СЛЕДУЮЩЕГО теста, подменяя глобальную mt-последовательность
     * (у Faker она одна на процесс). Это ломало детерминизм фиксированного
     * сида демо-сидера посреди цикла — редкий флак
     * `DemoOrdersSeederTest::test_rerun_rebuilds_identical_dataset`
     * (диагностика T-020: деструктор срабатывал прямо внутри
     * `Factory::create()` цикла сида). Единый генератор на процесс живёт до
     * его конца — подмен состояния между тестами исчезает, а поведение
     * генерации значениями не меняется: mt-последовательность и так была
     * сквозной на процесс.
     */
    private static ?FakerGenerator $sharedFaker = null;

    protected function setUp(): void
    {
        parent::setUp();

        $locale = (string) config('app.faker_locale');
        $faker = self::$sharedFaker ??= FakerFactory::create($locale);
        $this->app->instance(FakerGenerator::class, $faker);
        $this->app->instance(FakerGenerator::class . ':' . $locale, $faker);

        // Глобальные защиты сьюта (изолируют каждый тест от внешнего
        // мира и от реального времени):
        //
        // - Http: исходящий запрос без подходящего фейка падает сразу,
        //   не доходя до сети;
        // - Sleep: retry/backoff блокировок и очередей не тратят
        //   реальное время (syncWithCarbon продвигает замороженное
        //   время на длительность sleep);
        // - Exceptions: report() пишется в фейк, а не в реальный
        //   обработчик — рендер ответов (401/422/500) не меняется;
        // - Vite: сьют не зависит от артефактов сборки фронтенда.
        //   public/build в .gitignore, а backend-джоба CI фронт не
        //   собирает (это делает параллельная frontend-джоба), поэтому
        //   без заглушки каждый Inertia-рендер падал 500
        //   (ViteManifestNotFoundException, фикс CI 2026-10-28).
        //   withoutVite() — официальный тестовый API фреймворка: @vite
        //   рендерит пустую строку, реальный manifest.json не ищется.
        //   Ассет-теги сьютом не проверяются (их корректность —
        //   frontend-джоба, `vite build`); вернуть реальный Vite в
        //   отдельном тесте можно `$this->withVite()`.
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();
        $this->withoutVite();
    }
}
