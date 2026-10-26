<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Читатель входных примеров MarketHub как СЫРЫХ байтов (T-015).
 *
 * Правило задачи и §3.1 ТЗ: примеры `task/examples/*.json` в тестах
 * используются байт в байт — без json_decode/нормализации/повторной
 * сериализации. Перекодирование тела меняет байты (`\/`, `\u…`,
 * пробелы) и подпись не сойдётся (ловушка R-1). Папка `task/`
 * не изменяется: хелпер только читает.
 *
 * Примеры событий заканчиваются переводом строки, эталон тела подписи —
 * нет (§3.1): хелпер не знает и не исправляет это, тесты фиксируют
 * фактические байты (S-23 — оба варианта легальны).
 */
final class MarketplaceExamples
{
    private const string DIRECTORY = 'task/examples';

    /**
     * Сырые байты примера, байт в байт как в файле.
     *
     * Имя принимается без суффикса ('order-paid') или с ним
     * ('order-paid.json'); расширение не значимо для чтения.
     *
     * @param non-empty-string $example имя файла примера
     */
    public static function raw(string $example): string
    {
        $path = self::path($example);

        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Файл примера вебхука не найден: %s.', $path));
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException(sprintf('Не удалось прочитать пример вебхука: %s.', $path));
        }

        return $raw;
    }

    /**
     * Абсолютный путь к файлу примера (от корня проекта через
     * base_path() — работает из любого контекста теста).
     *
     * @param non-empty-string $example
     */
    public static function path(string $example): string
    {
        $name = str_ends_with($example, '.json') ? $example : $example . '.json';

        return base_path(self::DIRECTORY . '/' . $name);
    }
}
