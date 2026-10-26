/**
 * Транспорт для клиентских запросов админки к методам контракта
 * task/openapi.yaml (Q3-гибрид, FR-ADM-05): fetch + JSON + типизированная
 * ошибка ApiError с классификацией, которую используют страницы
 * (404 — «не найдено», 422 — «ошибка параметров», прочее — транспорт).
 *
 * Форму ошибок 422 не придумываем: это стандартное тело валидации
 * Laravel `{ message, errors: { поле: [сообщения] } }` (A-29).
 */

/** Класс ошибки запроса к API. */
export type ApiErrorKind =
    /** 404 — ресурс не найден (для карточки: «заказ не найден»). */
    | 'not-found'
    /** 422 — ошибка параметров запроса (стандартная валидация Laravel). */
    | 'validation'
    /** Любой другой неуспешный HTTP-статус. */
    | 'http'
    /** Сеть недоступна / запрос оборван (fetch бросил исключение). */
    | 'network'
    /** Успешный статус с не-JSON телом. */
    | 'parse';

export class ApiError extends Error {
    readonly kind: ApiErrorKind;
    readonly status: number | null;
    /** Ошибки по полям из тела 422 (если Laravel их передал). */
    readonly fieldErrors: Record<string, string[]> | null;

    constructor(
        kind: ApiErrorKind,
        message: string,
        options: {
            status?: number | null;
            fieldErrors?: Record<string, string[]> | null;
            cause?: unknown;
        } = {},
    ) {
        super(
            message,
            options.cause === undefined ? undefined : { cause: options.cause },
        );
        this.name = 'ApiError';
        this.kind = kind;
        this.status = options.status ?? null;
        this.fieldErrors = options.fieldErrors ?? null;
    }
}

interface LaravelErrorBody {
    message?: unknown;
    errors?: unknown;
}

/** Извлекает поле errors из тела ошибки Laravel, если оно корректно. */
function extractFieldErrors(
    body: LaravelErrorBody | null,
): Record<string, string[]> | null {
    if (body?.errors === null || typeof body?.errors !== 'object') {
        return null;
    }

    const fieldErrors: Record<string, string[]> = {};

    for (const [field, messages] of Object.entries(body.errors)) {
        if (
            Array.isArray(messages) &&
            messages.every((m) => typeof m === 'string')
        ) {
            fieldErrors[field] = messages;
        }
    }

    return Object.keys(fieldErrors).length > 0 ? fieldErrors : null;
}

/** Читает тело ошибки как JSON, насколько возможно, без бросаний. */
async function readErrorBody(
    response: Response,
): Promise<LaravelErrorBody | null> {
    try {
        const body: unknown = await response.json();

        return typeof body === 'object' && body !== null
            ? (body as LaravelErrorBody)
            : null;
    } catch {
        return null;
    }
}

/**
 * Выполняет GET-запрос к API и возвращает разобранный JSON.
 * Неуспех всегда выражается ApiError — страницы различают сценарии
 * по `kind` (404/422/сеть), а не по коду в каждом вызове.
 */
export async function requestJson<T>(url: string): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
    } catch (cause) {
        throw new ApiError('network', 'Сетевая ошибка запроса к API', {
            cause,
        });
    }

    if (!response.ok) {
        const body = await readErrorBody(response);
        const serverMessage =
            typeof body?.message === 'string'
                ? body.message
                : `HTTP ${response.status}`;

        if (response.status === 404) {
            throw new ApiError('not-found', serverMessage, {
                status: response.status,
            });
        }

        if (response.status === 422) {
            throw new ApiError('validation', serverMessage, {
                status: response.status,
                fieldErrors: extractFieldErrors(body),
            });
        }

        throw new ApiError('http', serverMessage, { status: response.status });
    }

    try {
        return (await response.json()) as T;
    } catch (cause) {
        throw new ApiError('parse', 'Не удалось разобрать ответ API', {
            status: response.status,
            cause,
        });
    }
}
