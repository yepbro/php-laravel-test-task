<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_schema_matches_spec(): void
    {
        $expected = [
            'id' => 'varchar(64)',
            'number' => 'varchar(64)',
            'status' => 'varchar(16)',
            'currency' => 'char(3)',
            'customer_email' => 'varchar(255)',
            'customer_email_normalized' => 'varchar(255)',
            'customer_name' => 'varchar(255)',
            'customer_phone' => 'varchar(64)',
            'delivery_price' => 'decimal(12,2)',
            'discount' => 'decimal(12,2)',
            'total' => 'decimal(12,2)',
            'shipment_carrier' => 'varchar(64)',
            'shipment_tracking_number' => 'varchar(64)',
            'shipment_tracking_url' => 'varchar(512)',
            'placed_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_reason' => 'text',
            'version_event_created_at' => 'datetime',
            'version_event_id' => 'varchar(64)',
            'snapshot' => 'json',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];

        $this->assertSame($expected, $this->columnTypes('orders'));

        $this->assertNullability('orders', [
            'customer_phone' => true,
            'shipment_carrier' => true,
            'shipment_tracking_number' => true,
            'shipment_tracking_url' => true,
            'paid_at' => true,
            'cancelled_at' => true,
            'cancel_reason' => true,
            'placed_at' => false,
            'source_updated_at' => false,
            'snapshot' => false,
            'version_event_id' => false,
        ]);

        $this->assertPrimaryKey('orders', 'id');
        $this->assertIndex('orders', ['number']);
        $this->assertIndex('orders', ['status', 'placed_at']);
        $this->assertIndex('orders', ['customer_email_normalized', 'placed_at']);
        $this->assertIndex('orders', ['placed_at', 'id']);
        $this->assertNoFloatingPointColumns('orders');
    }

    public function test_order_items_schema_matches_spec(): void
    {
        $expected = [
            'id' => 'bigint unsigned',
            'order_id' => 'varchar(64)',
            'position' => 'smallint',
            'sku' => 'varchar(64)',
            'name' => 'varchar(255)',
            'quantity' => 'int unsigned',
            'price' => 'decimal(12,2)',
        ];

        $this->assertSame($expected, $this->columnTypes('order_items'));
        $this->assertPrimaryKey('order_items', 'id');
        $this->assertUniqueIndex('order_items', ['order_id', 'position']);
        $this->assertForeignKey('order_items', 'order_id', 'orders', 'id', 'cascade');
        $this->assertNoFloatingPointColumns('order_items');
    }

    public function test_order_status_history_schema_matches_spec(): void
    {
        $expected = [
            'id' => 'bigint unsigned',
            'order_id' => 'varchar(64)',
            'status' => 'varchar(16)',
            'at' => 'datetime',
            'source_event_id' => 'varchar(64)',
        ];

        $this->assertSame($expected, $this->columnTypes('order_status_history'));
        $this->assertNullability('order_status_history', [
            'source_event_id' => true,
            'at' => false,
        ]);
        $this->assertPrimaryKey('order_status_history', 'id');
        $this->assertUniqueIndex('order_status_history', ['order_id', 'status', 'at']);
        $this->assertIndex('order_status_history', ['order_id', 'at', 'id']);
        $this->assertForeignKey('order_status_history', 'order_id', 'orders', 'id', 'cascade');
    }

    public function test_webhook_events_schema_matches_spec(): void
    {
        $expected = [
            'id' => 'varchar(64)',
            'type' => 'varchar(32)',
            'order_id' => 'varchar(64)',
            'signature_timestamp' => 'int',
            'payload' => 'mediumtext',
            'payload_sha256' => 'char(64)',
            'outcome' => 'varchar(16)',
            'reject_reason' => 'varchar(32)',
            'deliveries' => 'int',
            'received_at' => 'datetime',
            'last_received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];

        $this->assertSame($expected, $this->columnTypes('webhook_events'));
        $this->assertNullability('webhook_events', [
            'order_id' => true,
            'reject_reason' => true,
            'last_received_at' => true,
            'processed_at' => true,
            'received_at' => false,
            'payload' => false,
            'payload_sha256' => false,
        ]);

        $this->assertSame('1', $this->column('webhook_events', 'deliveries')['default']);

        $this->assertPrimaryKey('webhook_events', 'id');
        $this->assertIndex('webhook_events', ['order_id']);
        $this->assertSame([], Schema::getForeignKeys('webhook_events'));
    }

    public function test_order_notifications_schema_matches_spec(): void
    {
        $expected = [
            'id' => 'bigint unsigned',
            'order_id' => 'varchar(64)',
            'type' => 'varchar(16)',
            'dedupe_key' => 'varchar(191)',
            'status' => 'varchar(16)',
            'skip_reason' => 'varchar(32)',
            'recipient' => 'varchar(255)',
            'subject' => 'varchar(255)',
            'attempts' => 'int unsigned',
            'source_event_id' => 'varchar(64)',
            'last_error' => 'text',
            'sent_at' => 'datetime',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];

        $this->assertSame($expected, $this->columnTypes('order_notifications'));
        $this->assertNullability('order_notifications', [
            'dedupe_key' => true,
            'skip_reason' => true,
            'recipient' => true,
            'subject' => true,
            'last_error' => true,
            'sent_at' => true,
            'source_event_id' => false,
            'attempts' => false,
        ]);

        $this->assertSame('0', $this->column('order_notifications', 'attempts')['default']);

        $this->assertPrimaryKey('order_notifications', 'id');
        $this->assertUniqueIndex('order_notifications', ['dedupe_key']);
        $this->assertForeignKey('order_notifications', 'order_id', 'orders', 'id', 'cascade');
    }

    public function test_laravel_infrastructure_tables_are_not_duplicated(): void
    {
        $database = DB::connection()->getDatabaseName();

        $jobTables = array_values(array_filter(
            array_map(
                static fn(array $table): string => $table['name'],
                array_filter(
                    Schema::getTables(),
                    static fn(array $table): bool => $table['schema'] === $database,
                ),
            ),
            static fn(string $table): bool => str_contains($table, 'job'),
        ));
        sort($jobTables);

        $this->assertSame(['failed_jobs', 'job_batches', 'jobs'], $jobTables);
    }

    public function test_order_items_rejects_duplicate_position(): void
    {
        $this->insertOrder('ord_dup');

        DB::table('order_items')->insert([
            'order_id' => 'ord_dup',
            'position' => 1,
            'sku' => 'SKU-1',
            'name' => 'Товар 1',
            'quantity' => 2,
            'price' => '1290.00',
        ]);

        $this->expectException(QueryException::class);

        DB::table('order_items')->insert([
            'order_id' => 'ord_dup',
            'position' => 1,
            'sku' => 'SKU-2',
            'name' => 'Товар 2',
            'quantity' => 1,
            'price' => '890.50',
        ]);
    }

    public function test_status_history_rejects_duplicate_fact(): void
    {
        $this->insertOrder('ord_hist');

        DB::table('order_status_history')->insert([
            'order_id' => 'ord_hist',
            'status' => 'paid',
            'at' => '2026-10-21 10:05:47',
            'source_event_id' => 'evt_paid',
        ]);

        $this->expectException(QueryException::class);

        DB::table('order_status_history')->insert([
            'order_id' => 'ord_hist',
            'status' => 'paid',
            'at' => '2026-10-21 10:05:47',
            'source_event_id' => 'evt_paid_repeat',
        ]);
    }

    public function test_dedupe_key_allows_multiple_nulls_but_unique_values(): void
    {
        $this->insertOrder('ord_mail');

        DB::table('order_notifications')->insert($this->notification('ord_mail', null));
        DB::table('order_notifications')->insert($this->notification('ord_mail', null));

        $this->assertSame(
            2,
            DB::table('order_notifications')->whereNull('dedupe_key')->count(),
        );

        DB::table('order_notifications')->insert($this->notification('ord_mail', 'ord_mail:paid'));

        $this->expectException(QueryException::class);

        DB::table('order_notifications')->insert($this->notification('ord_mail', 'ord_mail:paid'));
    }

    public function test_order_deletion_cascades_to_children_but_keeps_webhook_events(): void
    {
        $this->insertOrder('ord_cascade');

        DB::table('order_items')->insert([
            'order_id' => 'ord_cascade',
            'position' => 1,
            'sku' => 'SKU-1',
            'name' => 'Товар',
            'quantity' => 1,
            'price' => '100.00',
        ]);
        DB::table('order_status_history')->insert([
            'order_id' => 'ord_cascade',
            'status' => 'created',
            'at' => '2026-10-21 10:02:11',
            'source_event_id' => 'evt_created',
        ]);
        DB::table('order_notifications')->insert($this->notification('ord_cascade', null));
        DB::table('webhook_events')->insert([
            'id' => 'evt_created',
            'type' => 'order.created',
            'order_id' => 'ord_cascade',
            'signature_timestamp' => 1792577149,
            'payload' => '{"id": "evt_created"}',
            'payload_sha256' => str_repeat('a', 64),
            'outcome' => 'applied',
            'received_at' => '2026-10-21 10:02:12',
        ]);

        DB::table('orders')->where('id', 'ord_cascade')->delete();

        $this->assertSame(0, DB::table('order_items')->where('order_id', 'ord_cascade')->count());
        $this->assertSame(0, DB::table('order_status_history')->where('order_id', 'ord_cascade')->count());
        $this->assertSame(0, DB::table('order_notifications')->where('order_id', 'ord_cascade')->count());
        $this->assertSame(1, DB::table('webhook_events')->where('id', 'evt_created')->count());
    }

    /**
     * @return array<string, string> карта «колонка => SQL-тип»
     */
    private function columnTypes(string $table): array
    {
        $types = [];

        foreach (Schema::getColumns($table) as $column) {
            $types[$column['name']] = $column['type'];
        }

        $this->assertNotEmpty($types, "Table {$table} has no columns.");

        return $types;
    }

    /**
     * @return array<string, mixed> описание колонки
     */
    private function column(string $table, string $column): array
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition;
            }
        }

        $this->fail("Column {$table}.{$column} does not exist.");
    }

    /**
     * @param array<string, bool> $expectations карта «колонка => nullable»
     */
    private function assertNullability(string $table, array $expectations): void
    {
        foreach ($expectations as $column => $nullable) {
            $this->assertSame(
                $nullable,
                $this->column($table, $column)['nullable'],
                "Column {$table}.{$column} must " . ($nullable ? 'be' : 'not be') . ' nullable.',
            );
        }
    }

    /**
     * @param list<string> $columns колонки индекса по порядку
     */
    private function assertIndex(string $table, array $columns, bool $unique = false): void
    {
        $index = $this->findIndex($table, $columns);

        $this->assertNotNull($index, sprintf(
            'Index (%s) on table %s does not exist.',
            implode(', ', $columns),
            $table,
        ));
        $this->assertFalse($index['primary']);
        $this->assertSame($unique, $index['unique'], sprintf(
            'Index (%s) on table %s must%s be unique.',
            implode(', ', $columns),
            $table,
            $unique ? '' : ' not',
        ));
    }

    /**
     * @param list<string> $columns колонки уникального индекса по порядку
     */
    private function assertUniqueIndex(string $table, array $columns): void
    {
        $this->assertIndex($table, $columns, true);
    }

    private function assertPrimaryKey(string $table, string $column): void
    {
        $index = $this->findIndex($table, [$column]);

        $this->assertNotNull($index, "Primary key {$table}({$column}) does not exist.");
        $this->assertTrue($index['primary'], "Index {$table}({$column}) is not primary.");
    }

    /**
     * @param list<string> $columns колонки индекса по порядку
     *
     * @return array<string, mixed>|null
     */
    private function findIndex(string $table, array $columns): ?array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return $index;
            }
        }

        return null;
    }

    private function assertForeignKey(
        string $table,
        string $column,
        string $foreignTable,
        string $foreignColumn,
        string $onDelete,
    ): void {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                $this->assertSame($foreignTable, $foreignKey['foreign_table']);
                $this->assertSame([$foreignColumn], $foreignKey['foreign_columns']);
                $this->assertSame($onDelete, $foreignKey['on_delete']);

                return;
            }
        }

        $this->fail("Foreign key {$table}.{$column} does not exist.");
    }

    private function assertNoFloatingPointColumns(string $table): void
    {
        $floating = array_filter(
            Schema::getColumns($table),
            static fn(array $column): bool => in_array($column['type_name'], ['float', 'double', 'real'], true),
        );

        $this->assertSame([], array_values($floating), sprintf(
            'Table %s must not use floating point columns (amounts are DECIMAL).',
            $table,
        ));
    }

    private function insertOrder(string $id): void
    {
        DB::table('orders')->insert([
            'id' => $id,
            'number' => 'MH-1048213',
            'status' => 'created',
            'currency' => 'RUB',
            'customer_email' => 'anna.smirnova@example.com',
            'customer_email_normalized' => 'anna.smirnova@example.com',
            'customer_name' => 'Анна Смирнова',
            'delivery_price' => '349.00',
            'discount' => '200.00',
            'total' => '3619.50',
            'placed_at' => '2026-10-21 10:02:11',
            'source_updated_at' => '2026-10-21 10:02:11',
            'version_event_created_at' => '2026-10-21 10:02:12',
            'version_event_id' => 'evt_created',
            'snapshot' => '{"id": "' . $id . '"}',
        ]);
    }

    /**
     * @return array<string, mixed> атрибуты уведомления
     */
    private function notification(string $orderId, ?string $dedupeKey): array
    {
        return [
            'order_id' => $orderId,
            'type' => 'paid',
            'dedupe_key' => $dedupeKey,
            'status' => 'pending',
            'attempts' => 0,
            'source_event_id' => 'evt_paid',
        ];
    }
}
