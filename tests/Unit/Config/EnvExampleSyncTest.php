<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Синхронность переменных окружения (T-043, FR-ENV-02/04/05, §5.4 ТЗ):
 * переменные, читаемые кодом (env() в config/*.php), compose.yaml
 * (${…}-интерполяции) и фронтендом (import.meta.env.VITE_*), обязаны
 * быть подмножеством ключей .env.example — «скрытых» переменных нет,
 * новая переменная появляется в .env.example в том же коммите, что
 * и в коде (FR-ENV-04). Дополнительно: .env.testing не вводит
 * неизвестных переменных, в .env.example нет осиротевших ключей
 * (FR-ENV-03: каждая строка осмысленна), политика секрета вебхуков
 * фиксирована tripwire'ами (DECISIONS.md, задачи 042/043).
 *
 * Фикстуры — реальные файлы репозитория (base_path()): проверяется
 * фактическое состояние, БД не используется. Тест живёт в PHPUnit-сьюте,
 * поэтому `composer tests`/`check`/`max` и будущий CI (T-044) выполняют
 * его без отдельного шага.
 *
 * Допущения сверки — явные:
 *
 * 1) INERT_CONFIG_KEYS — env()-ключи конфигов, осознанно не входящие
 *    в .env.example по принципу включения T-014 (DECISIONS.md): неактивные
 *    драйверы скелета Laravel и необязательная тонкая настройка активных
 *    секций живут на безопасных дефолтах конфигов. Список проверяется
 *    на актуальность — «сгнить» молча он не может;
 * 2) переменные compose/Vite/artisan serve читаются вне config/*.php —
 *    участвуют в обратной проверке (осиротевших ключей в .env.example нет);
 * 3) закомментированные строки dotenv-файлов (#
 *    KEY=…) — подсказки, объявлениями не считаются.
 */
class EnvExampleSyncTest extends TestCase
{
    /** Демо-значение секрета вебхуков из материалов ТЗ (§5.3/§5.4); только оно допустимо в VCS. */
    private const string DEMO_WEBHOOK_SECRET = 'whsec_test_5f1d8c2a9b';

    /** Переменные, подставляемые самим compose из окружения запуска (не переменные проекта). */
    private const array COMPOSE_BUILTIN_KEYS = ['PWD'];

    /**
     * Dotenv-файлы, находящиеся в Git: их и только их сканирует
     * tripwire демо-секрета. Локальный `.env` сюда не входит — он
     * вне VCS и вправе содержать реальный секрет. Расширяйте список
     * при добавлении нового dotenv-файла в репозиторий.
     */
    private const array COMMITTED_DOTENV_FILES = ['.env.example', '.env.testing'];

    /**
     * Ключи .env.example, читаемые вне config/*.php: PHP_CLI_SERVER_WORKERS
     * читает `artisan serve` (ServeCommand фреймворка) из окружения
     * процесса; config-файлов и compose-интерполяций для него нет.
     */
    private const array NON_CONFIG_READERS = ['PHP_CLI_SERVER_WORKERS'];

    /**
     * Env()-ключи config/*.php, осознанно НЕ входящие в .env.example —
     * принцип включения T-014 (см. docblock класса). Две группы:
     * неактивные драйверы/сервисы скелета (читаются только при
     * переключении драйвера, которого у проекта нет) и необязательная
     * тонкая настройка активных секций (безопасные дефолты конфигов).
     *
     * Требования к списку — test_inert_config_keys_stay_actual: каждый
     * ключ реально читается каким-то config/*.php и отсутствует
     * в .env.example; переменную удалили из конфига или «повысили» до
     * .env.example — запись обязана уйти из списка.
     */
    private const array INERT_CONFIG_KEYS = [
        // --- Неактивные драйверы и сервисы скелета Laravel ---

        // аутентификация — вне границ проекта (§4 ТЗ)
        'AUTH_GUARD',
        'AUTH_MODEL',
        'AUTH_PASSWORD_BROKER',
        'AUTH_PASSWORD_RESET_TOKEN_TABLE',
        'AUTH_PASSWORD_TIMEOUT',
        // файловое хранилище S3 (активен local)
        'AWS_ACCESS_KEY_ID',
        'AWS_BUCKET',
        'AWS_DEFAULT_REGION',
        'AWS_ENDPOINT',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_URL',
        'AWS_USE_PATH_STYLE_ENDPOINT',
        // очередь Beanstalkd (активна redis)
        'BEANSTALKD_QUEUE',
        'BEANSTALKD_QUEUE_HOST',
        'BEANSTALKD_QUEUE_RETRY_AFTER',
        // кэш DynamoDB (активен redis)
        'DYNAMODB_CACHE_TABLE',
        'DYNAMODB_ENDPOINT',
        // кэш memcached (активен redis)
        'MEMCACHED_HOST',
        'MEMCACHED_PASSWORD',
        'MEMCACHED_PERSISTENT_ID',
        'MEMCACHED_PORT',
        'MEMCACHED_USERNAME',
        // лог-каналы papertrail/slack (активны stack/single)
        'LOG_PAPERTRAIL_HANDLER',
        'LOG_SLACK_EMOJI',
        'LOG_SLACK_USERNAME',
        'LOG_SLACK_WEBHOOK_URL',
        'PAPERTRAIL_PORT',
        'PAPERTRAIL_URL',
        'SLACK_BOT_USER_DEFAULT_CHANNEL',
        'SLACK_BOT_USER_OAUTH_TOKEN',
        // почта postmark/resend и альтернативные транспорты (активен smtp → Mailpit)
        'MAIL_EHLO_DOMAIN',
        'MAIL_LOG_CHANNEL',
        'MAIL_SENDMAIL_PATH',
        'MAIL_URL',
        'POSTMARK_API_KEY',
        'RESEND_API_KEY',
        // очередь SQS (активна redis)
        'SQS_PREFIX',
        'SQS_QUEUE',
        'SQS_SUFFIX',
        // queue/cache/session на БД и file-хранилище кэша (активны redis)
        'CACHE_STORAGE_DISK',
        'CACHE_STORAGE_PATH',
        'DB_CACHE_CONNECTION',
        'DB_CACHE_LOCK_CONNECTION',
        'DB_CACHE_LOCK_TABLE',
        'DB_CACHE_TABLE',
        'DB_QUEUE',
        'DB_QUEUE_CONNECTION',
        'DB_QUEUE_RETRY_AFTER',
        'DB_QUEUE_TABLE',
        'SESSION_TABLE',
        // альтернативные параметры DB-соединения (активно прямое TCP-подключение)
        'DB_SOCKET',
        'DB_SSLMODE',
        'DB_URL',
        'MYSQL_ATTR_SSL_CA',

        // --- Тонкая настройка активных секций (безопасные дефолты конфигов) ---

        // app: maintenance store — только при driver=database; ротация ключей
        'APP_MAINTENANCE_STORE',
        'APP_PREVIOUS_KEYS',
        // кэш: префикс ключей
        'CACHE_PREFIX',
        // БД: charset/collation/режим внешних ключей
        'DB_CHARSET',
        'DB_COLLATION',
        'DB_FOREIGN_KEYS',
        // Redis: альтернативы соединения и тонкая настройка клиента
        'REDIS_BACKOFF_ALGORITHM',
        'REDIS_BACKOFF_BASE',
        'REDIS_BACKOFF_CAP',
        'REDIS_CACHE_CONNECTION',
        'REDIS_CACHE_DB',
        'REDIS_CACHE_LOCK_CONNECTION',
        'REDIS_CLUSTER',
        'REDIS_DB',
        'REDIS_MAX_RETRIES',
        'REDIS_PERSISTENT',
        'REDIS_PREFIX',
        'REDIS_QUEUE',
        'REDIS_QUEUE_CONNECTION',
        'REDIS_QUEUE_RETRY_AFTER',
        'REDIS_URL',
        'REDIS_USERNAME',
        // Horizon: префикс/имя/маршрут — одна установка на инстанс
        'HORIZON_DOMAIN',
        'HORIZON_NAME',
        'HORIZON_PATH',
        'HORIZON_PREFIX',
        // логи: параметры каналов
        'LOG_DAILY_DAYS',
        'LOG_DEPRECATIONS_TRACE',
        'LOG_STDERR_FORMATTER',
        'LOG_SYSLOG_FACILITY',
        // очередь: драйвер failed_jobs
        'QUEUE_FAILED_DRIVER',
        // сессии: параметры cookie и хранилища
        'SESSION_CONNECTION',
        'SESSION_COOKIE',
        'SESSION_EXPIRE_ON_CLOSE',
        'SESSION_HTTP_ONLY',
        'SESSION_PARTITIONED_COOKIE',
        'SESSION_SAME_SITE',
        'SESSION_SECURE_COOKIE',
        'SESSION_STORE',
    ];

    /**
     * FR-ENV-02/05: каждый env()-ключ config/*.php либо объявлен
     * в .env.example, либо явно перечислен в INERT_CONFIG_KEYS.
     */
    public function test_config_env_keys_are_declared_or_explicitly_allowed(): void
    {
        $declared = array_flip($this->dotenvKeys(base_path('.env.example')));
        $missing = array_diff_key($this->configEnvKeys(), $declared, array_flip(self::INERT_CONFIG_KEYS));

        $this->assertSame(
            [],
            $missing,
            'env()-переменные config/*.php без строки в .env.example (FR-ENV-02/04): '
            . implode(', ', array_keys($missing))
            . '. Добавьте переменную в .env.example (значение по умолчанию + короткий комментарий, §5.4 ТЗ)'
            . ' или, если это неактивный драйвер/тонкая настройка, включите ключ в INERT_CONFIG_KEYS'
            . ' с обоснованием (принцип включения — DECISIONS.md, задачи 014/043).',
        );
    }

    /** FR-ENV-02: все ${…}-интерполяции compose.yaml объявлены в .env.example. */
    public function test_compose_variables_are_declared_in_env_example(): void
    {
        $declared = $this->dotenvKeys(base_path('.env.example'));
        $missing = array_values(array_diff(
            array_diff($this->composeVariables(), self::COMPOSE_BUILTIN_KEYS),
            $declared,
        ));

        $this->assertSame(
            [],
            $missing,
            'Переменные ${…}-интерполяции compose.yaml без строки в .env.example (FR-ENV-02): '
            . implode(', ', $missing)
            . '. Compose читает их из .env — объявите переменные в .env.example (§5.4 ТЗ).',
        );
    }

    /** FR-ENV-02: VITE_*-переменные исходников объявлены в .env.example. */
    public function test_frontend_vite_variables_are_declared_in_env_example(): void
    {
        $declared = $this->dotenvKeys(base_path('.env.example'));

        $used = [];
        foreach ($this->frontendViteVariables() as $keys) {
            $used = array_merge($used, $keys);
        }

        $missing = array_values(array_diff(array_unique($used), $declared));

        $this->assertSame(
            [],
            $missing,
            'VITE_*-переменные фронтенда без объявления в .env.example (FR-ENV-02): '
            . implode(', ', $missing)
            . '. Vite exposes в import.meta.env только объявленные в .env переменные (§5.4 ТЗ).',
        );
    }

    /**
     * .env.testing (T-036) — строгое подмножество ключей .env.example:
     * тестовое окружение задаёт значения, но не вводит неизвестных
     * переменных; нужна тонкая настройка — переменная сперва
     * появляется в .env.example.
     */
    public function test_env_testing_keys_are_subset_of_env_example(): void
    {
        $unknown = array_values(array_diff(
            $this->dotenvKeys(base_path('.env.testing')),
            $this->dotenvKeys(base_path('.env.example')),
        ));

        $this->assertSame(
            [],
            $unknown,
            '.env.testing вводит переменные, которых нет в .env.example: '
            . implode(', ', $unknown)
            . '. Перенесите объявления в .env.example (§5.4 ТЗ), .env.testing — только значения.',
        );
    }

    /**
     * Обратная проверка (FR-ENV-03): в .env.example нет осиротевших
     * ключей — каждая строка читается кодом, compose, Vite либо
     * artisan serve.
     */
    public function test_env_example_has_no_stale_keys(): void
    {
        $read = array_merge(
            array_keys($this->configEnvKeys()),
            $this->composeVariables(),
            self::NON_CONFIG_READERS,
        );
        foreach ($this->frontendViteVariables() as $keys) {
            $read = array_merge($read, $keys);
        }

        $stale = array_values(array_diff($this->dotenvKeys(base_path('.env.example')), $read));

        $this->assertSame(
            [],
            $stale,
            'Осиротевшие ключи .env.example — переменные никто не читает (config/*.php, compose.yaml, Vite, artisan serve): '
            . implode(', ', $stale)
            . '. Удалите строку или верните читателя.',
        );
    }

    /**
     * Гигиена allowlist: каждая запись INERT_CONFIG_KEYS реально
     * читается каким-то config/*.php и отсутствует в .env.example —
     * список не «гниёт» при удалении переменной из конфига или её
     * «повышении» до .env.example.
     */
    public function test_inert_config_keys_stay_actual(): void
    {
        $read = array_keys($this->configEnvKeys());

        $unknown = array_values(array_diff(self::INERT_CONFIG_KEYS, $read));
        $this->assertSame(
            [],
            $unknown,
            'INERT_CONFIG_KEYS содержит ключи, которые уже не читаются config/*.php: '
            . implode(', ', $unknown)
            . '. Удалите записи из списка.',
        );

        $promoted = array_values(array_intersect(
            self::INERT_CONFIG_KEYS,
            $this->dotenvKeys(base_path('.env.example')),
        ));
        $this->assertSame(
            [],
            $promoted,
            'Ключи перенесены в .env.example, но остались в INERT_CONFIG_KEYS: '
            . implode(', ', $promoted)
            . '. Удалите их из списка.',
        );
    }

    /**
     * Политика секрета (DECISIONS.md, задача 042, P3-2):
     * MARKETPLACE_WEBHOOK_SECRET не пробрасывается в compose. `${VAR:-…}`
     * в environment зафиксировал бы значение (пустое при отсутствии
     * переменной в оболочке → молчаливый fail-closed) и перестал бы
     * реагировать на правку .env без пересоздания контейнеров; секрет
     * живёт в .env, который монтируется в контейнер целиком.
     */
    public function test_compose_does_not_interpolate_webhook_secret(): void
    {
        $this->assertStringNotContainsString(
            'MARKETPLACE_WEBHOOK_SECRET',
            (string) file_get_contents(base_path('compose.yaml')),
            'MARKETPLACE_WEBHOOK_SECRET не должен появляться в compose.yaml (политика секрета, DECISIONS.md 042/043): секрет читается приложением из смонтированного .env.',
        );
    }

    /**
     * FR-ENV-03/§5.4: в закоммиченных dotenv-файлах допустим только
     * демо-плейсхолдер секрета из материалов ТЗ; реальный секрет —
     * только в локальном .env (не в Git).
     */
    public function test_committed_dotenv_files_carry_only_demo_webhook_secret(): void
    {
        foreach (self::COMMITTED_DOTENV_FILES as $file) {
            preg_match_all('/whsec_\S+/', (string) file_get_contents(base_path($file)), $matches);

            $foreign = array_values(array_diff(array_unique($matches[0]), [self::DEMO_WEBHOOK_SECRET]));

            $this->assertSame(
                [],
                $foreign,
                "В {$file} допустимо только демо-значение секрета из ТЗ (whsec_test_…): " . implode(', ', $foreign) . '.',
            );
        }

        $this->assertSame(
            self::DEMO_WEBHOOK_SECRET,
            $this->dotenvValue(base_path('.env.example'), 'MARKETPLACE_WEBHOOK_SECRET'),
            'Значение MARKETPLACE_WEBHOOK_SECRET в .env.example — демо-плейсхолдер §5.3/§5.4 ТЗ; реальный секрет — в локальном .env.',
        );
    }

    /**
     * env()-ключи всех config/*.php: токен-парсинг, а не grep по строкам —
     * многострочные вызовы env(
     *     'KEY',
     *     default,
     * ) регулярным выражением не ловятся (config/horizon.php,
     * config/session.php). Первый аргумент env() обязан быть строковым
     * литералом — иначе синхронность непроверяема, и тест падает явно.
     *
     * @return array<string, list<string>> ключ => config-файлы, где читается
     */
    private function configEnvKeys(): array
    {
        $keys = [];

        foreach (glob(config_path('*.php')) ?: [] as $configFile) {
            $tokens = token_get_all((string) file_get_contents($configFile));
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== 'env') {
                    continue;
                }

                // Только вызов глобальной функции env(...): пропускаем
                // ::env, ->env, ?->env, namespaced \Foo\env(...) и
                // объявление function env(...).
                $previous = $this->skipWhitespaceBackwards($tokens, $i - 1);
                if ($previous >= 0 && is_array($tokens[$previous]) && in_array(
                    $tokens[$previous][0],
                    [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_NS_SEPARATOR, T_FUNCTION],
                    true,
                )) {
                    continue;
                }

                $openingParenthesis = $this->skipWhitespace($tokens, $i + 1);
                if ($openingParenthesis >= $count || $tokens[$openingParenthesis] !== '(') {
                    continue;
                }

                $firstArgument = $this->skipWhitespace($tokens, $openingParenthesis + 1);
                if ($firstArgument >= $count || ! is_array($tokens[$firstArgument])
                    || $tokens[$firstArgument][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    $this->fail(sprintf(
                        'env() в %s вызван не со строковым литералом первого аргумента: ключи переменных обязаны быть литералами, иначе синхронность .env.example непроверяема.',
                        basename($configFile),
                    ));
                }

                $key = stripcslashes(substr($tokens[$firstArgument][1], 1, -1));
                $keys[$key][] = basename($configFile);
            }
        }

        $keys = array_map(
            static fn(array $files): array => array_values(array_unique($files)),
            $keys,
        );
        ksort($keys);

        return $keys;
    }

    /**
     * Переменные ${…}-интерполяции compose.yaml: обе формы — ${KEY},
     * ${KEY:-default} и короткая $KEY (compose интерполирует обе).
     * $$KEY — экранированный литерал `$`, нейтрализуется до
     * сопоставления ($${KEY} интерполяцией не является); вложенные
     * конструкции ${A:-${B}} дают обе переменные — закрывающая `}`
     * в паттерне не требуется.
     *
     * @return list<string>
     */
    private function composeVariables(): array
    {
        $path = base_path('compose.yaml');
        $this->assertFileExists($path, 'Фикстура проверки — реальные файлы репозитория; ожидается ' . $path . '.');

        $yaml = str_replace('$$', "\u{0000}", (string) file_get_contents($path));

        preg_match_all('/\$\{([A-Za-z0-9_]+)/', $yaml, $braces);
        preg_match_all('/(?<!\$)\$([A-Za-z][A-Za-z0-9_]*)/', $yaml, $short);

        $keys = array_unique(array_merge($braces[1], $short[1]));
        sort($keys);

        return array_values($keys);
    }

    /**
     * VITE_*-переменные из исходников фронтенда: рекурсивный обход
     * resources/ плюс vite.config.ts; учитываются оба способа доступа
     * import.meta.env.VITE_* (клиент) и process.env.VITE_* (конфиг Vite).
     *
     * @return array<string, list<string>> файл => VITE_*-переменные
     */
    private function frontendViteVariables(): array
    {
        $base = (string) base_path();
        $viteConfig = $base . '/vite.config.ts';
        $this->assertFileExists($viteConfig, 'Фикстура проверки — реальные файлы репозитория; ожидается ' . $viteConfig . '.');
        $paths = [$viteConfig];

        $resources = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base . '/resources', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($resources as $info) {
            if ($info instanceof SplFileInfo && $info->isFile()) {
                $paths[] = $info->getPathname();
            }
        }

        $result = [];
        foreach ($paths as $path) {
            $content = (string) file_get_contents($path);

            $keys = [];
            foreach (['import\.meta\.env\.(VITE_[A-Z0-9_]+)', 'process\.env\.(VITE_[A-Z0-9_]+)'] as $pattern) {
                preg_match_all('/' . $pattern . '/', $content, $matches);
                $keys = array_merge($keys, $matches[1]);
            }

            if ($keys !== []) {
                $result[substr($path, strlen($base) + 1)] = array_values(array_unique($keys));
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * Незакомментированные ключи dotenv-файла (строки вида KEY=...).
     * Дубликаты ключей — ошибка файла и падают явно (в dotenv
     * побеждает последняя строка, молчаливое схлопывание скрыло бы
     * двусмысленность).
     *
     * @return list<string>
     */
    private function dotenvKeys(string $path): array
    {
        $this->assertFileExists($path, 'Фикстура проверки — реальные файлы репозитория; ожидается ' . $path . '.');

        $keys = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_.]+)\s*=/', $line, $matches) === 1) {
                $keys[] = $matches[1];
            }
        }

        $duplicates = array_diff_key($keys, array_unique($keys));
        if ($duplicates !== []) {
            $this->fail(sprintf(
                'Дубликаты ключей в %s: %s (в dotenv побеждает последняя строка — объявление двусмысленно).',
                $path,
                implode(', ', array_unique($duplicates)),
            ));
        }

        sort($keys);

        return $keys;
    }

    /**
     * Значение переменной dotenv-файла без окружающих кавычек;
     * null — переменная не объявлена. При нескольких объявлениях
     * побеждает последнее (семантика dotenv).
     */
    private function dotenvValue(string $path, string $key): ?string
    {
        $value = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^' . $key . '\s*=\s*(.*)$/', $line, $matches) === 1) {
                $value = trim(trim($matches[1]), "\"'");
            }
        }

        return $value;
    }

    /**
     * Индекс первого не-whitespace токена начиная с $index.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function skipWhitespace(array $tokens, int $index): int
    {
        $count = count($tokens);
        while ($index < $count && is_array($tokens[$index]) && $tokens[$index][0] === T_WHITESPACE) {
            $index++;
        }

        return $index;
    }

    /**
     * Индекс последнего не-whitespace токена перед $index (включительно).
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function skipWhitespaceBackwards(array $tokens, int $index): int
    {
        while ($index >= 0 && is_array($tokens[$index]) && $tokens[$index][0] === T_WHITESPACE) {
            $index--;
        }

        return $index;
    }
}
