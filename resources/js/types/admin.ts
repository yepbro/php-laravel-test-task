/**
 * Типы вспомогательных props Inertia-страниц админки (§6.7 ТЗ,
 * Q3-гибрид): только данные, которых нет в контракте /api/orders.
 * Заказы и их содержимое через props не передаются (FR-ADM-05).
 */
import type { OrderStatus } from '@/types/api';

/** Опция фильтра статусов с русской подписью (props страницы списка). */
export interface StatusOption {
    value: OrderStatus;
    label: string;
}

/** Props страницы `/admin/orders` (Inertia-оболочка списка). */
export interface AdminOrdersIndexProps {
    statusOptions: StatusOption[];
    defaultPerPage: number;
}

/** Props страницы `/admin/orders/{id}` (Inertia-оболочка карточки). */
export interface AdminOrderShowProps {
    id: string;
}
