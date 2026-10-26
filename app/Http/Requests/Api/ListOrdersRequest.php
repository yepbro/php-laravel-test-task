<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Data\OrderListParameters;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Параметры GET /api/orders (§6.6 ТЗ: FR-API-02, FR-API-03, FR-API-08).
 *
 * Пустые значения параметров глобальный middleware приводит к null
 * (ConvertEmptyStringsToNull) — «пустой фильтр игнорируется» (FR-API-02)
 * выполняется без отдельных правил. Некорректные значения (status вне
 * enum, page < 1, per_page вне 1..100, нечисловые) уходят в стандартный
 * 422 Laravel (FR-API-08, A-29) — формат тела не кастомизируется.
 */
final class ListOrdersRequest extends FormRequest
{
    /**
     * API публичный — авторизация вне границ проекта (§4 ТЗ).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'email' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Валидированные параметры выборки с дефолтами пагинации
     * (page = 1, per_page = 20 — FR-API-03).
     */
    public function orderListParameters(): OrderListParameters
    {
        /**
         * Форма гарантирована правилами: status — строковое значение
         * case'а OrderStatus, page/per_page — числа (числовые строки
         * из query-string приводятся к int).
         *
         * @var array{status?: string, email?: string, page?: numeric-string|int, per_page?: numeric-string|int} $validated
         */
        $validated = $this->validated();

        return new OrderListParameters(
            status: isset($validated['status'])
                ? OrderStatus::from($validated['status'])
                : null,
            email: $validated['email'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 20),
        );
    }
}
