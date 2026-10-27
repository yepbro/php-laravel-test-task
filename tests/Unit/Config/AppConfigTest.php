<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\Support\RestoresEnvironment;
use Tests\TestCase;

/**
 * config/app.php, ключ timezone (T-014): NFR-03/§5.3 ТЗ требуют
 * APP_TIMEZONE=UTC как переменную окружения с дефолтом UTC — раньше
 * значение было жёсткой константой и переменная из §5.3 не работала.
 * Тест фиксирует дефолт и env-маппинг (методика — как в
 * MarketplaceConfigTest, T-009). Мутации env — через withEnv():
 * исходные значения восстанавливаются, окружение процесса не
 * протекает в последующие тесты.
 */
class AppConfigTest extends TestCase
{
    use RestoresEnvironment;

    public function test_timezone_defaults_to_utc(): void
    {
        $config = $this->withEnv(
            ['APP_TIMEZONE' => null],
            static function (): array {
                return require config_path('app.php');
            },
        );

        $this->assertSame('UTC', $config['timezone']);
    }

    public function test_timezone_reads_env_variable(): void
    {
        $config = $this->withEnv(
            ['APP_TIMEZONE' => 'Europe/Moscow'],
            static function (): array {
                return require config_path('app.php');
            },
        );

        // Переменная управляема (§5.3), при этом доменные даты в БД
        // остаются UTC — смена пояса не должна происходить молча.
        $this->assertSame('Europe/Moscow', $config['timezone']);
    }
}
