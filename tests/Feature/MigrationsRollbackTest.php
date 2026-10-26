<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsRollbackTest extends TestCase
{
    /**
     * Доменные таблицы задачи 003.
     */
    private const DOMAIN_TABLES = [
        'orders',
        'order_items',
        'order_status_history',
        'webhook_events',
        'order_notifications',
    ];

    public function test_domain_migrations_rollback_and_reapply(): void
    {
        // migrate:fresh, а не migrate: тест работает в общей базе testing
        // (без DB-трейтов), и rollback снимает только последний батч —
        // состояние базы перед этим должно быть детерминированным.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} must exist before rollback.");
        }

        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table {$table} must be dropped by rollback.");
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} must exist after re-applying.");
        }
    }
}
