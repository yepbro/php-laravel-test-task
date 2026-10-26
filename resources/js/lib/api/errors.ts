/**
 * Русские пользовательские сообщения по классу ошибки API (Q38: тексты
 * для пользователя — на русском). Сообщения сервера (message) —
 * технические, наружу не показываются; детали полей 422 (fieldErrors)
 * выводятся списком — это тексты валидации Laravel, которые проект
 * локализует отдельно (T-038+), скелету достаточно перечня полей.
 */
import { ApiError } from '@/lib/api/client';

export function apiErrorTitle(error: ApiError): string {
    switch (error.kind) {
        case 'not-found':
            return 'Заказ не найден';
        case 'validation':
            return 'Некорректные параметры запроса';
        case 'network':
            return 'Нет связи с сервером';
        default:
            return 'Не удалось загрузить данные';
    }
}

export function apiErrorDescription(error: ApiError): string {
    switch (error.kind) {
        case 'not-found':
            return 'Возможно, заказ удалён или ссылка неверна.';
        case 'validation':
            return 'Проверьте параметры запроса и попробуйте ещё раз.';
        case 'network':
            return 'Проверьте подключение и обновите страницу.';
        default:
            return 'Произошла ошибка при обращении к сервису заказов. Попробуйте обновить страницу.';
    }
}

/** Поля с ошибками из тела 422 — для показа под сообщением. */
export function apiErrorFields(error: ApiError): string[] {
    if (error.fieldErrors === null) {
        return [];
    }

    return Object.entries(error.fieldErrors).map(
        ([field, messages]) => `${field}: ${messages.join(', ')}`,
    );
}
