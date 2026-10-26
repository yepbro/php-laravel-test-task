<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * config/marketplace.php (T-009): состав ключей, значения по умолчанию
 * из §5.3 ТЗ и маппинг переменных окружения. Для проверки env-маппинга
 * конфиг-файл подключается заново с очищенной переменной — значение
 * по умолчанию берётся из файла, а не из окружения тестовой машины.
 */
class MarketplaceConfigTest extends TestCase
{
    public function test_top_level_keys_match_specification(): void
    {
        $this->assertSame(
            [
                'webhook_secret',
                'webhook_tolerance',
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
        $repository = Env::getRepository();
        $repository->set('MARKETPLACE_WEBHOOK_SECRET', 'whsec_test_env_mapping');
        $repository->set('MARKETPLACE_WEBHOOK_TOLERANCE', '30');
        $repository->set('MARKETPLACE_ORDER_LOCK_TIMEOUT', '1');
        $repository->set('MARKETPLACE_ORDER_LOCK_LEASE', '2');
        $repository->set('MARKETPLACE_EVENTS_RETENTION_DAYS', '7');

        try {
            $config = require config_path('marketplace.php');
        } finally {
            $repository->clear('MARKETPLACE_WEBHOOK_SECRET');
            $repository->clear('MARKETPLACE_WEBHOOK_TOLERANCE');
            $repository->clear('MARKETPLACE_ORDER_LOCK_TIMEOUT');
            $repository->clear('MARKETPLACE_ORDER_LOCK_LEASE');
            $repository->clear('MARKETPLACE_EVENTS_RETENTION_DAYS');
        }

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
            ['max_attempts', 'backoff', 'sweep_after_seconds', 'refund_days'],
            array_keys($notifications),
        );
        $this->assertSame(5, $notifications['max_attempts']);
        // Backoff из §6.5/FR-MAIL-05: 10 с, 1 мин, 5 мин, 15 мин.
        $this->assertSame([10, 60, 300, 900], $notifications['backoff']);
        $this->assertSame(120, $notifications['sweep_after_seconds']);
        $this->assertSame(10, $notifications['refund_days']);
    }

    public function test_notifications_env_variables_map_to_config_keys(): void
    {
        $repository = Env::getRepository();
        $repository->set('NOTIFICATIONS_TRIES', '3');
        $repository->set('NOTIFICATIONS_SWEEP_AFTER_SECONDS', '60');
        $repository->set('NOTIFICATIONS_REFUND_DAYS', '14');

        try {
            $config = require config_path('marketplace.php');
        } finally {
            $repository->clear('NOTIFICATIONS_TRIES');
            $repository->clear('NOTIFICATIONS_SWEEP_AFTER_SECONDS');
            $repository->clear('NOTIFICATIONS_REFUND_DAYS');
        }

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
        $repository = Env::getRepository();
        $repository->clear('MAIL_TIMEOUT');

        try {
            $mail = require config_path('mail.php');
        } finally {
            $repository->clear('MAIL_TIMEOUT');
        }

        $this->assertSame(5, $mail['mailers']['smtp']['timeout']);
        $this->assertLessThan(config('marketplace.order_lock_lease'), $mail['mailers']['smtp']['timeout']);
    }

    /**
     * @return array<string, mixed> значения конфига при очищенных env-переменных
     */
    private function freshConfigWithClearedEnv(): array
    {
        $repository = Env::getRepository();
        $keys = [
            'MARKETPLACE_WEBHOOK_SECRET',
            'MARKETPLACE_WEBHOOK_TOLERANCE',
            'MARKETPLACE_ORDER_LOCK_TIMEOUT',
            'MARKETPLACE_ORDER_LOCK_LEASE',
            'MARKETPLACE_EVENTS_RETENTION_DAYS',
            'NOTIFICATIONS_TRIES',
            'NOTIFICATIONS_SWEEP_AFTER_SECONDS',
            'NOTIFICATIONS_REFUND_DAYS',
        ];

        foreach ($keys as $key) {
            $repository->clear($key);
        }

        return require config_path('marketplace.php');
    }
}
