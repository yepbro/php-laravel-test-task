<?php

declare(strict_types=1);

namespace Tests\Support;

use Dotenv\Repository\RepositoryInterface;
use Illuminate\Support\Env;

/**
 * Изоляция Env-репозитория процесса для конфиг-тестов.
 *
 * Env::getRepository() существует один на процесс, и Laravel грузит
 * .env через него же — мутации set()/clear() из теста переживают сам
 * тест: следующее приложение процесса перечитывает config-файлы, а
 * env() идёт в тот же репозиторий. Без восстановления cleared/set
 * переменные меняют окружение всех последующих тестов процесса
 * (скрытая порядок-зависимость): например, clear(
 * 'MARKETPLACE_WEBHOOK_SECRET') оставлял бы процесс без секрета .env,
 * и последующие тесты получали дефолт '' (fail closed) вместо
 * сконфигурированного значения.
 */
trait RestoresEnvironment
{
    /**
     * Выполнить $scope с временными значениями env-переменных;
     * исходное состояние каждой переменной (значение или отсутствие)
     * восстанавливается в finally при любом исходе $scope.
     *
     * @param array<string, string|null> $variables значение null — переменная очищается
     * @param callable(): mixed $scope
     * @return mixed результат $scope
     */
    protected function withEnv(array $variables, callable $scope): mixed
    {
        $repository = Env::getRepository();

        /** @var array<string, string|null> $originals */
        $originals = [];

        foreach ($variables as $name => $value) {
            $originals[$name] = $repository->get($name);

            self::writeEnv($repository, $name, $value);
        }

        try {
            return $scope();
        } finally {
            foreach ($originals as $name => $original) {
                self::writeEnv($repository, $name, $original);
            }
        }
    }

    /**
     * @param string|null $value null — удалить переменную из репозитория
     */
    private static function writeEnv(RepositoryInterface $repository, string $name, ?string $value): void
    {
        if ($value === null) {
            $repository->clear($name);

            return;
        }

        $repository->set($name, $value);
    }
}
