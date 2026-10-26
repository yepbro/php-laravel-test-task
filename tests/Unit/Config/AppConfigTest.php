<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * config/app.php, ключ timezone (T-014): NFR-03/§5.3 ТЗ требуют
 * APP_TIMEZONE=UTC как переменную окружения с дефолтом UTC — раньше
 * значение было жёсткой константой и переменная из §5.3 не работала.
 * Тест фиксирует дефолт и env-маппинг (методика — как в
 * MarketplaceConfigTest, T-009).
 */
class AppConfigTest extends TestCase
{
    public function test_timezone_defaults_to_utc(): void
    {
        $repository = Env::getRepository();
        $repository->clear('APP_TIMEZONE');

        try {
            $config = require config_path('app.php');
        } finally {
            $repository->clear('APP_TIMEZONE');
        }

        $this->assertSame('UTC', $config['timezone']);
    }

    public function test_timezone_reads_env_variable(): void
    {
        $repository = Env::getRepository();
        $repository->set('APP_TIMEZONE', 'Europe/Moscow');

        try {
            $config = require config_path('app.php');
        } finally {
            $repository->clear('APP_TIMEZONE');
        }

        // Переменная управляема (§5.3), при этом доменные даты в БД
        // остаются UTC — смена пояса не должна происходить молча.
        $this->assertSame('Europe/Moscow', $config['timezone']);
    }
}
