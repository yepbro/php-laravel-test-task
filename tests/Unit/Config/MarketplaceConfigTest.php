<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\Support\RestoresEnvironment;
use Tests\TestCase;

/**
 * config/marketplace.php (T-009): состав ключей, значения по умолчанию
 * из §5.3 ТЗ и маппинг переменных окружения. Для проверки env-маппинга
 * конфиг-файл подключается заново с очищенной переменной — значение
 * по умолчанию берётся из файла, а не из окружения тестовой машины.
 *
 * Мутации env — через withEnv(): переменные меняются только внутри
 * $scope, исходные значения (включая загруженные из .env)
 * восстанавливаются в finally, и окружение процесса не протекает
 * в последующие тесты.
 */
class MarketplaceConfigTest extends TestCase
{
    use RestoresEnvironment;

    /** Env-переменные, читаемые config/marketplace.php. */
    private const array ENV_KEYS = [
        'MARKETPLACE_WEBHOOK_SECRET',
        'MARKETPLACE_WEBHOOK_TOLERANCE',
        'MARKETPLACE_ORDER_LOCK_TIMEOUT',
        'MARKETPLACE_ORDER_LOCK_LEASE',
        'MARKETPLACE_EVENTS_RETENTION_DAYS',
        'NOTIFICATIONS_TRIES',
        'NOTIFICATIONS_SWEEP_AFTER_SECONDS',
        'NOTIFICATIONS_REFUND_DAYS',
    ];

    public function test_top_level_keys_match_specification(): void
    {
        $this->assertSame(
            [
                'webhook_secret',
                'webhook_tolerance',
                // Лимит тела вебхука — фиксированная протокольная константа
                // ТЗ (FR-WH-12), env-переменной не имеет (T-025).
                'webhook_max_body_bytes',
                'order_lock_timeout',
                'order_lock_lease',
                'terminal_statuses',
                'events_retention_days',
                'notifications',
            ],
            array_keys(config('marketplace', [])),
        );
    }

    public function test_defaults_follow_tz_section_5_3(): void
    {
        $config = $this->freshConfigWithClearedEnv();

        $this->assertSame(300, $config['webhook_tolerance']);
        // 1 MiB — лимит тела вебхука (FR-WH-12): ровно столько допустимо.
        $this->assertSame(1024 * 1024, $config['webhook_max_body_bytes']);
        $this->assertSame(3, $config['order_lock_timeout']);
        $this->assertSame(15, $config['order_lock_lease']);
        $this->assertSame(90, $config['events_retention_days']);
    }

    /**
     * Секрет с пустым значением по умолчанию — fail closed (Q11/FR-WH-04):
     * без заданной переменной окружения подписи не с чем сверять,
     * все вебхуки обязаны отклоняться.
     */
    public function test_webhook_secret_defaults_to_empty_string(): void
    {
        $config = $this->freshConfigWithClearedEnv();

        $this->assertSame('', $config['webhook_secret']);
    }

    public function test_env_variables_map_to_config_keys(): void
    {
        $config = $this->withEnv(
            [
                'MARKETPLACE_WEBHOOK_SECRET' => 'whsec_test_env_mapping',
                'MARKETPLACE_WEBHOOK_TOLERANCE' => '30',
                'MARKETPLACE_ORDER_LOCK_TIMEOUT' => '1',
                'MARKETPLACE_ORDER_LOCK_LEASE' => '2',
                'MARKETPLACE_EVENTS_RETENTION_DAYS' => '7',
            ],
            $this->requireMarketplaceConfig(...),
        );

        $this->assertSame('whsec_test_env_mapping', $config['webhook_secret']);
        $this->assertSame(30, $config['webhook_tolerance']);
        $this->assertSame(1, $config['order_lock_timeout']);
        $this->assertSame(2, $config['order_lock_lease']);
        $this->assertSame(7, $config['events_retention_days']);
    }

    /**
     * Терминальные статусы (Q35/FR-ORD-09) задаются конфигом со значениями
     * case'ов OrderStatus — единственное место истины для
     * OrderStatus::isTerminal() (обязательство T-004, закрытое в T-009).
     *
     * Непустота — tripwire: isTerminal() при пустом списке молча вернул бы
     * false для всех статусов и отключил бы защиту Q35 (fail-open), поэтому
     * «пустой по случайности» конфиг обязан ломать тест.
     */
    public function test_terminal_statuses_come_from_enum_values(): void
    {
        $this->assertSame(['delivered', 'cancelled'], config('marketplace.terminal_statuses'));
        $this->assertNotEmpty(config('marketplace.terminal_statuses'));
    }

    public function test_notifications_defaults_follow_tz(): void
    {
        $config = $this->freshConfigWithClearedEnv();
        $notifications = $config['notifications'];

        $this->assertSame(
            [
                'max_attempts',
                'backoff',
                'sweep_after_seconds',
                // Технические параметры прогона sweeper'а (аудит P3.8):
                // без env-переменных — настроечные константы, не бизнес-правила.
                'sweep_batch_size',
                'sweep_max_per_run',
                'refund_days',
            ],
            array_keys($notifications),
        );
        $this->assertSame(5, $notifications['max_attempts']);
        // Backoff из §6.5/FR-MAIL-05: 10 с, 1 мин, 5 мин, 15 мин.
        $this->assertSame([10, 60, 300, 900], $notifications['backoff']);
        $this->assertSame(120, $notifications['sweep_after_seconds']);
        // Потолок прогона обязан быть не меньше размера батча, иначе
        // chunkById остановится на первом же неполном батче.
        $this->assertSame(500, $notifications['sweep_batch_size']);
        $this->assertSame(1000, $notifications['sweep_max_per_run']);
        $this->assertGreaterThanOrEqual(
            $notifications['sweep_batch_size'],
            $notifications['sweep_max_per_run'],
        );
        $this->assertSame(10, $notifications['refund_days']);
    }

    public function test_notifications_env_variables_map_to_config_keys(): void
    {
        $config = $this->withEnv(
            [
                'NOTIFICATIONS_TRIES' => '3',
                'NOTIFICATIONS_SWEEP_AFTER_SECONDS' => '60',
                'NOTIFICATIONS_REFUND_DAYS' => '14',
            ],
            $this->requireMarketplaceConfig(...),
        );

        $this->assertSame(3, $config['notifications']['max_attempts']);
        $this->assertSame(60, $config['notifications']['sweep_after_seconds']);
        $this->assertSame(14, $config['notifications']['refund_days']);
    }

    /**
     * §5.3 ТЗ: config/mail.php обязан явно читать MAIL_TIMEOUT (5 с);
     * значение должно быть меньше lease блокировки заказа.
     */
    public function test_smtp_mailer_reads_mail_timeout(): void
    {
        $mail = $this->withEnv(
            ['MAIL_TIMEOUT' => null],
            static function (): array {
                return require config_path('mail.php');
            },
        );

        $this->assertSame(5, $mail['mailers']['smtp']['timeout']);
        $this->assertLessThan(config('marketplace.order_lock_lease'), $mail['mailers']['smtp']['timeout']);
    }

    /**
     * @return array<string, mixed> значения конфига при очищенных env-переменных
     */
    private function freshConfigWithClearedEnv(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->withEnv(
            array_fill_keys(self::ENV_KEYS, null),
            $this->requireMarketplaceConfig(...),
        );

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMarketplaceConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require config_path('marketplace.php');

        return $config;
    }
}
