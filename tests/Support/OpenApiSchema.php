<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Контрактная валидация ответов API по task/openapi.yaml (T-018,
 * FR-API-10, §11 ТЗ «API-контракт»).
 *
 * Валидатор — opis/json-schema 2.x (dev-зависимость, решение —
 * DECISIONS-log.md, задача 018): OpenAPI 3.1 = JSON Schema 2020-12,
 * схемы контракта валидны валидатором напрямую. Корневая схема
 * собирается из всего документа: схемы ответов списка/карточки
 * объявлены в openapi.yaml инлайн (paths → responses), а их
 * $ref-ссылки '#/components/schemas/…' — обычные JSON Pointer'ы
 * от корня документа, поэтому валидации передаётся копия всего
 * документа с добавленным корневым '$ref' на нужную схему ответа.
 * Служебные ключи документа (openapi/info/paths) не являются
 * ключевыми словами JSON Schema и игнорируются как аннотации.
 *
 * Файл task/openapi.yaml только читается и не изменяется.
 */
final class OpenApiSchema
{
    /**
     * Схема ответа 200 GET /api/orders: конверт data/links/meta
     * (транзитивно валидирует каждый элемент data[] по
     * components/schemas/OrderListItem, а также links и meta).
     *
     * '~1' — экранирование '/' в JSON Pointer.
     */
    public const string LIST_RESPONSE = '#/paths/~1api~1orders/get/responses/200/content/application~1json/schema';

    /**
     * Схема ответа 200 GET /api/orders/{id}: {"data": Order}
     * (транзитивно валидирует карточку по components/schemas/Order).
     */
    public const string ORDER_RESPONSE = '#/paths/~1api~1orders~1{id}/get/responses/200/content/application~1json/schema';

    /** Путь контракта от корня проекта; task/ не изменяется. */
    private const string CONTRACT = 'task/openapi.yaml';

    /** Draft контракта OpenAPI 3.1; фиксируется явно, а не дефолтом валидатора. */
    private const string DRAFT_2020_12 = 'https://json-schema.org/draft/2020-12/schema';

    /** @var array<string, object> корневые схемы по указателю (кэш в рамках процесса теста) */
    private static array $roots = [];

    /** @var Validator|null валидатор (переиспользуется между проверками процесса) */
    private static ?Validator $validator = null;

    private static ?object $document = null;

    /**
     * Падает assertion'ом с полной диагностикой валидатора, если тело
     * ответа расходится со схемой контракта (контрактные ошибки —
     * отдельно от функциональных: указатель + причина + тело).
     */
    public static function assertResponseMatchesSchema(TestResponse $response, string $pointer, string $what): void
    {
        $body = (string) $response->getContent();

        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Assert::fail(sprintf(
                'Ответ %s не является валидным JSON (%s), контрактная проверка невозможна. Тело ответа:%s%s',
                $what,
                $exception->getMessage(),
                PHP_EOL,
                $body,
            ));
        }

        self::$validator ??= new Validator();
        $result = self::$validator->validate($data, self::root($pointer));

        if ($result->isValid()) {
            return;
        }

        Assert::fail(sprintf(
            'Ответ %s расходится с контрактом task/openapi.yaml (схема %s):%s%s%sТело ответа:%s%s',
            $what,
            $pointer,
            PHP_EOL,
            implode(PHP_EOL, self::collectErrors($result->error(), 0)),
            PHP_EOL,
            PHP_EOL,
            $body,
        ));
    }

    /**
     * Корневая схема для указателя: копия документа контракта
     * с корневым '$ref' и явным '$schema' 2020-12.
     *
     * @param non-empty-string $pointer
     */
    private static function root(string $pointer): object
    {
        if (! isset(self::$roots[$pointer])) {
            $root = clone self::document();
            $root->{'$ref'} = $pointer;
            $root->{'$schema'} = self::DRAFT_2020_12;

            self::$roots[$pointer] = $root;
        }

        return self::$roots[$pointer];
    }

    /**
     * Документ контракта как stdClass (значения YAML → JSON; 'null'
     * в union-типах остаётся строкой, настоящий null — null).
     */
    private static function document(): object
    {
        if (self::$document === null) {
            $parsed = Yaml::parseFile(base_path(self::CONTRACT));

            if (! is_array($parsed)) {
                throw new \RuntimeException('Не удалось разобрать контракт task/openapi.yaml.');
            }

            try {
                self::$document = json_decode(
                    (string) json_encode($parsed, JSON_THROW_ON_ERROR),
                    false,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new \RuntimeException('Контракт task/openapi.yaml не сериализуется в JSON: ' . $exception->getMessage(), 0, $exception);
            }
        }

        return self::$document;
    }

    /**
     * Дерево ошибок валидации: каждая строка — «указатель в данных:
     * подставленное сообщение [keyword]», вложенность subErrors
     * ($ref/allOf/oneOf) показывается отступами.
     *
     * @return list<string>
     */
    private static function collectErrors(ValidationError $error, int $depth): array
    {
        $formatter = new ErrorFormatter();

        $lines = [sprintf(
            '%s%s: %s [keyword: %s]',
            str_repeat('  ', $depth),
            $formatter->formatErrorKey($error),
            $formatter->formatErrorMessage($error),
            $error->keyword(),
        )];

        foreach ($error->subErrors() as $subError) {
            $lines = [...$lines, ...self::collectErrors($subError, $depth + 1)];
        }

        return $lines;
    }
}
