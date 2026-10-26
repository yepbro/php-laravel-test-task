<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Data\Version;
use App\Support\SnapshotVersionComparator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Сравнение версий снимков (T-016; красное до T-023).
 *
 * FR-ORD-02: версия = кортеж `(order.updated_at (UTC), event.created_at
 * (UTC), event.id)`; входящий снимок применяется только при СТРОГО
 * большей версии — равные и старые дают `stale` без изменения заказа.
 * FR-ORD-03: даты сравниваются как моменты времени (нормализация в
 * UTC), а не как строки: +03:00, +00:00 и `Z` могут задавать один
 * момент (S-19). `updated_at` MarketHub точен до секунды → ничья
 * по датам разрешается `event.created_at`, затем `event.id` (Q14/T8).
 *
 * Пока SnapshotVersionComparator::isNewer() — заглушка T-023, все тесты
 * падают с её LogicException; после реализации становятся зелёными
 * без правки тестов. Моменты и ID — реальные данные task/examples.
 */
class SnapshotVersionComparatorTest extends TestCase
{
    private SnapshotVersionComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new SnapshotVersionComparator();
    }

    /**
     * Первый компонент кортежа решает: свежий updated_at → новая версия
     * (реальная пара order.created → order.updated из примеров).
     */
    public function test_newer_order_updated_at_wins(): void
    {
        $this->assertTrue($this->isNewer(
            self::version('2026-10-21T13:03:30+03:00', '2026-10-21T10:03:31+00:00', 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K'),
            self::version('2026-10-21T13:02:11+03:00', '2026-10-21T10:02:12+00:00', 'evt_01JAXKZB7M2D9F4H6J8K0N3P5R'),
        ));
    }

    /**
     * Старый updated_at — не новее при любых остальных компонентах:
     * устаревший снимок не перезаписывает заказ (FR-ORD-02, `stale`).
     */
    public function test_older_order_updated_at_is_not_newer(): void
    {
        $this->assertFalse($this->isNewer(
            self::version('2026-10-21T13:05:47+03:00', '2026-10-21T10:05:48+00:00', 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC'),
            self::version('2026-10-22T06:40:03+00:00', '2026-10-22T06:40:04+00:00', 'evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0'),
        ));
    }

    /**
     * Один момент времени в разных записях — равная версия, не «новее»
     * (FR-ORD-03: сравнение моментов UTC, строковое сравнение запрещено;
     * S-19). Проверяются обе пары направлений записей +03:00 / +00:00 / Z.
     */
    #[TestWith(['2026-10-21T13:02:11+03:00', '2026-10-21T10:02:11+00:00'])]
    #[TestWith(['2026-10-21T10:02:11+00:00', '2026-10-21T13:02:11+03:00'])]
    #[TestWith(['2026-10-21T13:02:11+03:00', '2026-10-21T10:02:11Z'])]
    #[TestWith(['2026-10-21T10:02:11Z', '2026-10-21T10:02:11+00:00'])]
    public function test_same_moment_in_different_offsets_is_not_newer(string $incomingUpdatedAt, string $storedUpdatedAt): void
    {
        $this->assertFalse($this->isNewer(
            self::version($incomingUpdatedAt, '2026-10-21T10:05:48+00:00', 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC'),
            self::version($storedUpdatedAt, '2026-10-21T10:05:48+00:00', 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC'),
        ));
    }

    /**
     * Строгое сравнение: полностью равные версии (включая равные
     * моменты в разных записях) — не новее, повторная доставка того же
     * снимка ничего не перезаписывает (FR-ORD-02, T5).
     */
    public function test_strictly_equal_versions_are_not_newer(): void
    {
        $this->assertFalse($this->isNewer(
            self::version('2026-10-22T06:40:03+00:00', '2026-10-22T06:40:04+00:00', 'evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0'),
            self::version('2026-10-22T06:40:03Z', '2026-10-22T06:40:04+00:00', 'evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0'),
        ));
    }

    /**
     * Ничья по updated_at (T8: точность до секунды) разрешается вторым
     * компонентом — event.created_at.
     */
    #[TestWith(['2026-10-21T10:05:49+00:00', true])]
    #[TestWith(['2026-10-21T10:05:47+00:00', false])]
    public function test_event_created_at_breaks_updated_at_tie(string $incomingEventCreatedAt, bool $expected): void
    {
        $this->assertSame($expected, $this->isNewer(
            self::version('2026-10-21T13:05:47+03:00', $incomingEventCreatedAt, 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC'),
            self::version('2026-10-21T10:05:47+00:00', '2026-10-21T10:05:48+00:00', 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC'),
        ));
    }

    /**
     * Полная ничья по датам разрешается лексикографическим сравнением
     * event.id — детерминированный tie-break (FR-ORD-02, Q14).
     */
    #[TestWith(['evt_b', 'evt_a', true])]
    #[TestWith(['evt_a', 'evt_b', false])]
    public function test_event_id_breaks_full_datetime_tie(string $incomingEventId, string $storedEventId, bool $expected): void
    {
        $this->assertSame($expected, $this->isNewer(
            self::version('2026-10-21T13:05:47+03:00', '2026-10-21T10:05:48+00:00', $incomingEventId),
            self::version('2026-10-21T10:05:47+00:00', '2026-10-21T10:05:48+00:00', $storedEventId),
        ));
    }

    /**
     * event.id — строка, а не число: 'evt_9' лексикографически больше
     * 'evt_10' ('9' > '1'), хотя 9 < 10. ULID как источник времени не
     * используется (§3.1 ТЗ) — id участвует только строковым порядком.
     */
    public function test_event_id_compared_as_plain_string_not_number(): void
    {
        $this->assertTrue($this->isNewer(
            self::version('2026-10-21T13:05:47+03:00', '2026-10-21T10:05:48+00:00', 'evt_9'),
            self::version('2026-10-21T10:05:47+00:00', '2026-10-21T10:05:48+00:00', 'evt_10'),
        ));
    }

    /**
     * Жизненный цикл ord_7Q2KX9M4 из примеров — монотонный порядок
     * версий: created → updated → paid → shipped (внеочередная доставка
     * разрешается кортежем, T5). Проверяются все последовательные пары
     * и «первый не новее последнего».
     */
    #[DataProvider('lifecycleProvider')]
    public function test_real_lifecycle_snapshots_form_monotonic_order(string $newer, string $older): void
    {
        $lifecycle = [
            'created' => self::version('2026-10-21T13:02:11+03:00', '2026-10-21T10:02:12+00:00', 'evt_01JAXKZB7M2D9F4H6J8K0N3P5R'),
            'updated' => self::version('2026-10-21T13:03:30+03:00', '2026-10-21T10:03:31+00:00', 'evt_01JAXM0C3T5V7X9Z1B3D5F7H9K'),
            'paid' => self::version('2026-10-21T13:05:47+03:00', '2026-10-21T10:05:48+00:00', 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC'),
            'shipped' => self::version('2026-10-22T06:40:03+00:00', '2026-10-22T06:40:04+00:00', 'evt_01JAZ8R2W4Y6A8C0E2G4J6L8N0'),
        ];

        $this->assertTrue($this->isNewer($lifecycle[$newer], $lifecycle[$older]));
        $this->assertFalse($this->isNewer($lifecycle[$older], $lifecycle[$newer]));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function lifecycleProvider(): iterable
    {
        return [
            'created → updated' => ['updated', 'created'],
            'updated → paid' => ['paid', 'updated'],
            'paid → shipped (обновление в +00:00)' => ['shipped', 'paid'],
            'created → shipped (первый снимок против последнего)' => ['shipped', 'created'],
        ];
    }

    private function isNewer(Version $incoming, Version $stored): bool
    {
        return $this->comparator->isNewer($incoming, $stored);
    }

    /**
     * @param non-empty-string $orderUpdatedAt
     * @param non-empty-string $eventCreatedAt
     */
    private static function version(string $orderUpdatedAt, string $eventCreatedAt, string $eventId): Version
    {
        return new Version(CarbonImmutable::parse($orderUpdatedAt), CarbonImmutable::parse($eventCreatedAt), $eventId);
    }
}
