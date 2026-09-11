<?php

declare(strict_types=1);

namespace Lava\Db\Schema;

use Lava\Db\Connection;
use Lava\Db\Sql\Dialect;

/**
 * What the database actually contains, read back from the database.
 *
 * This is the counterweight to every other class in the pack, all of which
 * describe what a schema *should* be. A snapshot is the only thing that can
 * answer "did the migration do what it said" — and the only way to assert a
 * migrate/rollback round trip, which is otherwise a claim about the code
 * rather than about the database.
 *
 * The shapes differ per dialect because the databases do. `information_schema`
 * reports a `VARCHAR(255)` as `character varying` on PostgreSQL and
 * `varchar(255)` on MySQL, and neither is wrong. So a snapshot is only ever
 * compared with another snapshot from the same dialect, and the comparison is
 * on the normalised shape below rather than on raw catalogue rows.
 *
 * @phpstan-type ColumnShape array{type: string, nullable: bool, default: string|null, primary: bool}
 * @phpstan-type TableShape array{columns: array<string, ColumnShape>, indexes: array<string, bool>}
 */
final readonly class SchemaSnapshot
{
    /**
     * @param array<string, TableShape> $tables keyed by table name, columns and
     *        indexes keyed by name; index values are true when UNIQUE
     */
    public function __construct(public array $tables)
    {
    }

    public static function of(Connection $connection): self
    {
        return match ($connection->dialect()) {
            Dialect::Sqlite => self::fromSqlite($connection),
            Dialect::Mysql => self::fromMysql($connection),
            Dialect::Pgsql => self::fromPgsql($connection),
        };
    }

    /** @return list<string> every table name, sorted */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /** @return array<string, ColumnShape> the columns of a table, empty when it does not exist */
    public function columns(string $table): array
    {
        return $this->tables[$table]['columns'] ?? [];
    }

    /** @return array<string, bool> the indexes of a table, empty when it does not exist */
    public function indexes(string $table): array
    {
        return $this->tables[$table]['indexes'] ?? [];
    }

    /**
     * True when two snapshots describe the same schema.
     *
     * `===`, so the comparison is order-sensitive — and that is safe because a
     * snapshot is only ever compared with one read from the same dialect, where
     * every level is built in a deterministic order: tables sorted, indexes
     * sorted, and columns in declaration order, which is what `PRAGMA` and
     * `information_schema` report and what makes a column's position in the
     * shape a fact about the schema rather than about the query. So two
     * snapshots of the same schema compare equal and two that differ anywhere
     * do not; a round-trip test can compare before and after directly.
     *
     * Order-insensitive was considered and is not what this needs: it would
     * hide a reordered column list, which for a migration that rebuilt a table
     * is exactly the change worth seeing.
     */
    public function equals(self $other): bool
    {
        return $this->tables === $other->tables;
    }

    /** @return array{tables: array<string, TableShape>, count: int} */
    public function json(): array
    {
        return ['tables' => $this->tables, 'count' => count($this->tables)];
    }

    private static function fromSqlite(Connection $connection): self
    {
        $dialect = $connection->dialect();

        $names = [];
        foreach ($connection->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ) as $row) {
            $names[] = (string) $row['name'];
        }

        $tables = [];
        foreach ($names as $name) {
            $columns = [];
            foreach ($connection->query('PRAGMA table_info(' . $dialect->quote($name) . ')') as $row) {
                $columns[(string) $row['name']] = [
                    'type' => strtoupper((string) $row['type']),
                    'nullable' => (int) $row['notnull'] === 0,
                    'default' => $row['dflt_value'] === null ? null : (string) $row['dflt_value'],
                    'primary' => (int) $row['pk'] > 0,
                ];
            }

            $indexes = [];
            foreach ($connection->query('PRAGMA index_list(' . $dialect->quote($name) . ')') as $row) {
                $index = (string) $row['name'];
                // SQLite silently creates indexes to back PRIMARY KEY and
                // UNIQUE constraints, named sqlite_autoindex_*. They are the
                // database's bookkeeping, not the schema's, and they have no
                // counterpart on the other two dialects — so including them
                // would make the same logical schema snapshot differently
                // here than anywhere else.
                if (str_starts_with($index, 'sqlite_autoindex_')) {
                    continue;
                }
                $indexes[$index] = (int) $row['unique'] === 1;
            }
            ksort($indexes);

            $tables[$name] = ['columns' => $columns, 'indexes' => $indexes];
        }

        return new self($tables);
    }

    private static function fromMysql(Connection $connection): self
    {
        /** @var array<string, array<string, ColumnShape>> $columns */
        $columns = [];
        foreach ($connection->query(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ty, IS_NULLABLE AS n, '
            . 'COLUMN_DEFAULT AS d, COLUMN_KEY AS k FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
        ) as $row) {
            $columns[(string) $row['t']][(string) $row['c']] = [
                'type' => strtoupper((string) $row['ty']),
                'nullable' => strtoupper((string) $row['n']) === 'YES',
                'default' => $row['d'] === null ? null : (string) $row['d'],
                'primary' => strtoupper((string) $row['k']) === 'PRI',
            ];
        }

        /** @var array<string, array<string, bool>> $indexes */
        $indexes = [];
        foreach ($connection->query(
            'SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME'
        ) as $row) {
            $indexes[(string) $row['t']][(string) $row['i']] = (int) $row['nu'] === 0;
        }

        return new self(self::assemble($columns, $indexes));
    }

    private static function fromPgsql(Connection $connection): self
    {
        /** @var array<string, array<string, ColumnShape>> $columns */
        $columns = [];
        foreach ($connection->query(
            'SELECT table_name AS t, column_name AS c, data_type AS ty, is_nullable AS n, column_default AS d '
            . "FROM information_schema.columns WHERE table_schema = 'public' ORDER BY table_name, ordinal_position"
        ) as $row) {
            $columns[(string) $row['t']][(string) $row['c']] = [
                'type' => strtoupper((string) $row['ty']),
                'nullable' => strtoupper((string) $row['n']) === 'YES',
                'default' => $row['d'] === null ? null : (string) $row['d'],
                'primary' => false,
            ];
        }

        // information_schema has no "is this column the primary key" column;
        // PostgreSQL keeps that on the index. One extra query is cheaper than
        // a snapshot that cannot tell a key from a plain column.
        foreach ($connection->query(
            'SELECT c.relname AS t, a.attname AS c FROM pg_index i '
            . 'JOIN pg_class c ON c.oid = i.indrelid '
            . 'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) '
            . "WHERE i.indisprimary AND c.relnamespace = 'public'::regnamespace"
        ) as $row) {
            $table = (string) $row['t'];
            $column = (string) $row['c'];
            if (isset($columns[$table][$column])) {
                $columns[$table][$column]['primary'] = true;
            }
        }

        /** @var array<string, array<string, bool>> $indexes */
        $indexes = [];
        foreach ($connection->query(
            "SELECT tablename AS t, indexname AS i, indexdef AS def FROM pg_indexes WHERE schemaname = 'public'"
        ) as $row) {
            $indexes[(string) $row['t']][(string) $row['i']] = str_contains((string) $row['def'], 'UNIQUE');
        }

        return new self(self::assemble($columns, $indexes));
    }

    /**
     * Joins the two per-table maps into one sorted snapshot. A table can
     * appear in either map alone (a table with no indexes, an index whose
     * table had no readable columns), so the union of the keys is what
     * decides which tables exist.
     *
     * @param array<string, array<string, ColumnShape>> $columns
     * @param array<string, array<string, bool>> $indexes
     * @return array<string, TableShape>
     */
    private static function assemble(array $columns, array $indexes): array
    {
        $names = array_keys($columns + $indexes);
        sort($names);

        $tables = [];
        foreach ($names as $name) {
            $tableColumns = $columns[$name] ?? [];
            $tableIndexes = $indexes[$name] ?? [];
            ksort($tableIndexes);
            $tables[$name] = ['columns' => $tableColumns, 'indexes' => $tableIndexes];
        }

        return $tables;
    }
}
