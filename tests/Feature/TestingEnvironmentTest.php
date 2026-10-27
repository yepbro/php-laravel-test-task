<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Окружение тестового сьюта (T-020, красный baseline): сьют обязан
 * работать против MySQL 8.4 — значимы `SELECT … FOR UPDATE`, уникальные
 * индексы и семантика `DATETIME` (§11 ТЗ: тестовая БД — MySQL, не SQLite).
 *
 * Guard фиксирует конфигурацию соединения во время прогона и не даёт
 * негласно уехать на другой драйвер (случайный `DB_CONNECTION=sqlite`
 * или `DB_URL` в окружении упадут здесь, а не молча пройдут мимо).
 * Схему и таблицы не трогает — единственная работа с БД без DB-трейтов
 * рядом с `DatabaseReproducibilityTest` (правило T-008 про DDL не
 * затрагивается: DDL-команд нет, данных не создаёт).
 */
class TestingEnvironmentTest extends TestCase
{
    public function test_suite_runs_against_mysql_8_4(): void
    {
        $this->assertSame(
            'mysql',
            config('database.default'),
            'Тестовый драйвер БД — только MySQL (§11 ТЗ), SQLite не воспроизводит FOR UPDATE/уники/DATETIME.',
        );

        // Класс без DB-трейтов всегда работает в базовой `testing`
        // (per-process базы Paratest переключаются только тестам с
        // DB-трейтами — TestDatabases). Допуск префикса консервативен,
        // на случай смены механизма; сам guard схему и данные не трогает.
        $this->assertStringStartsWith(
            'testing',
            (string) DB::connection()->getDatabaseName(),
            'Тесты обязаны идти только против тестовой базы (phpunit.xml DB_DATABASE=testing).',
        );

        $version = DB::selectOne('select version() as v')?->v;
        $this->assertIsString($version);
        $this->assertStringStartsWith(
            '8.4.',
            $version,
            sprintf('Ожидается сервер MySQL 8.4 (§5.1 ТЗ), фактически: %s.', $version),
        );
    }
}
