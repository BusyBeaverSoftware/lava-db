<?php

declare(strict_types=1);

namespace Lava\Db\Sql;

use Lava\Db\Problem\BadSchema;
use Lava\Db\Query\Bindings;
use Lava\Db\Schema\ColumnDef;
use Lava\Db\Schema\ColumnType;
use Lava\Db\Schema\Index;
use Lava\Db\Schema\Table;

/**
 * Table definition in, DDL out. Pure, like {@see Compiler} and for the same
 * reason: the schema layer is where a mistake is most expensive — it runs
 * once, against a real database, and the failure mode is a half-applied
 * migration. Every dialect's DDL is asserted as an exact string, with no
 * database involved, before any of it is executed.
 *
 * The one place a value becomes SQL text lives here: a column default cannot
 * be a placeholder, because DDL is not parameterizable. {@see literal()}
 * renders it, and it is the only such rendering in the pack.
 */
final class SchemaCompiler
{
    public function __construct(private readonly Dialect $dialect)
    {
    }

    /**
     * The statements that create a table: one `CREATE TABLE`, then one
     * `CREATE INDEX` per index.
     *
     * Foreign keys are emitted as table-level `CONSTRAINT … FOREIGN KEY`
     * clauses, not as inline `REFERENCES` on the column. MySQL parses an
     * inline `REFERENCES` and then ignores it, so a schema written that way
     * gets enforced on SQLite and PostgreSQL and silently does nothing on
     * MySQL — the exact class of quiet lie this pack exists to avoid. The
     * table-level form is understood by all three.
     *
     * @return list<Compiled>
     * @throws BadSchema when the definition cannot be compiled
     */
    public function create(Table $table): array
    {
        $this->validate($table);

        $parts = [];
        foreach ($table->columns() as $column) {
            $parts[] = $this->column($column, inlineReferences: false);
        }
        if ($table->primaryKey() !== []) {
            $parts[] = 'PRIMARY KEY (' . $this->columnList($table->primaryKey()) . ')';
        }
        foreach ($table->columns() as $column) {
            if ($column->referencesTable !== null) {
                $parts[] = $this->foreignKey($table->name, $column);
            }
        }

        $statements = [new Compiled(
            'CREATE TABLE ' . $this->dialect->quote($table->name) . ' (' . implode(', ', $parts) . ')'
        )];

        foreach ($table->indexes() as $index) {
            $statements[] = $this->createIndex($table->name, $index);
        }

        return $statements;
    }

    /**
     * The statements `Schema::table()` runs: one `ALTER TABLE … ADD COLUMN`
     * per column, then one `CREATE INDEX` per index — a column's own
     * `->unique()` included.
     *
     * An index may cover a column the table already has, so the caller says
     * which columns those are, and every index is checked against them plus
     * the added ones before anything is emitted. A primary key is refused:
     * SQLite cannot add one to an existing table at all, and a declaration
     * this dropped would be the quiet lie 0.2.0 told about every index here.
     *
     * @param list<string> $existing the columns the table has before this runs
     * @return list<Compiled>
     * @throws BadSchema
     */
    public function alter(Table $table, array $existing): array
    {
        if ($table->primaryKey() !== []) {
            throw BadSchema::primaryKeyOnExistingTable($table->name);
        }
        $this->validateIndexes($table, [
            ...$existing,
            ...array_map(static fn (ColumnDef $column): string => $column->name, $table->columns()),
        ]);

        $statements = $this->addColumns($table->name, $table->columns());
        foreach ($table->indexes() as $index) {
            $statements[] = $this->createIndex($table->name, $index);
        }

        return $statements;
    }

    /**
     * The statements that add columns to an existing table.
     *
     * `ALTER TABLE … ADD COLUMN` is the one schema change all three dialects
     * agree on, which is why adding is supported and changing is not: SQLite
     * cannot alter a column's type or drop it without rebuilding the table,
     * so a DSL that offered `change()` would produce a migration that works
     * on two dialects out of three. Rebuild the table, or write the
     * dialect-specific SQL by hand.
     *
     * A NOT NULL column added to a non-empty table needs a default on every
     * dialect; without one the database refuses, and its message is the
     * diagnosis.
     *
     * `ADD COLUMN` cannot carry a table-level constraint, so a reference here
     * has to be inline — which SQLite and PostgreSQL honour and MySQL does
     * not. Rather than emit a constraint MySQL will drop on the floor, this
     * refuses on MySQL and says so.
     *
     * @param list<ColumnDef> $columns
     * @return list<Compiled>
     * @throws BadSchema
     */
    public function addColumns(string $table, array $columns): array
    {
        foreach ($columns as $column) {
            if ($column->referencesTable !== null && $this->dialect === Dialect::Mysql) {
                throw BadSchema::inlineReferenceUnsupported($table, $column->name);
            }
        }

        $statements = [];
        foreach ($columns as $column) {
            $statements[] = new Compiled(
                'ALTER TABLE ' . $this->dialect->quote($table)
                . ' ADD COLUMN ' . $this->column($column, inlineReferences: true)
            );
        }
        return $statements;
    }

    public function dropTable(string $table, bool $ifExists = false): Compiled
    {
        return new Compiled(
            'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->dialect->quote($table)
        );
    }

    public function createIndex(string $table, Index $index): Compiled
    {
        return new Compiled(
            'CREATE ' . ($index->unique ? 'UNIQUE ' : '') . 'INDEX '
            . $this->dialect->quote($index->name)
            . ' ON ' . $this->dialect->quote($table)
            . ' (' . $this->columnList($index->columns) . ')'
        );
    }

    /** MySQL scopes a DROP INDEX to its table; the other two do not. */
    public function dropIndex(string $table, string $name): Compiled
    {
        return new Compiled(
            'DROP INDEX ' . $this->dialect->quote($name)
            . ($this->dialect === Dialect::Mysql ? ' ON ' . $this->dialect->quote($table) : '')
        );
    }

    /**
     * The native type for a column on this dialect.
     *
     * SQLite stores dates and JSON as TEXT because it has no other choice —
     * its type affinity system has no DATE or JSON type, and the values are
     * text either way. The DSL's `DateTime` still means "a datetime" to the
     * caller, and the binding layer still writes the documented format; only
     * the storage class differs.
     */
    public function type(ColumnDef $column): string
    {
        $length = $column->length ?? 255;
        $precision = $column->precision ?? 10;
        $scale = $column->scale ?? 2;

        return match ($column->type) {
            ColumnType::Int => $this->dialect === Dialect::Sqlite ? 'INTEGER' : 'INT',
            ColumnType::BigInt => $this->dialect === Dialect::Sqlite ? 'INTEGER' : 'BIGINT',
            ColumnType::String => "VARCHAR({$length})",
            ColumnType::Text => 'TEXT',
            ColumnType::Bool => match ($this->dialect) {
                Dialect::Sqlite => 'INTEGER',
                Dialect::Mysql => 'TINYINT(1)',
                Dialect::Pgsql => 'BOOLEAN',
            },
            ColumnType::Float => match ($this->dialect) {
                Dialect::Sqlite => 'REAL',
                Dialect::Mysql => 'DOUBLE',
                Dialect::Pgsql => 'DOUBLE PRECISION',
            },
            ColumnType::Decimal => $this->dialect === Dialect::Mysql
                ? "DECIMAL({$precision}, {$scale})"
                : "NUMERIC({$precision}, {$scale})",
            ColumnType::Date => $this->dialect === Dialect::Sqlite ? 'TEXT' : 'DATE',
            ColumnType::DateTime => match ($this->dialect) {
                Dialect::Sqlite => 'TEXT',
                Dialect::Mysql => 'DATETIME',
                Dialect::Pgsql => 'TIMESTAMP',
            },
            ColumnType::Time => $this->dialect === Dialect::Sqlite ? 'TEXT' : 'TIME',
            ColumnType::Json => match ($this->dialect) {
                Dialect::Sqlite => 'TEXT',
                Dialect::Mysql => 'JSON',
                Dialect::Pgsql => 'JSONB',
            },
            ColumnType::Uuid => match ($this->dialect) {
                Dialect::Sqlite => 'TEXT',
                Dialect::Mysql => 'CHAR(36)',
                Dialect::Pgsql => 'UUID',
            },
            ColumnType::Binary => $this->dialect === Dialect::Pgsql ? 'BYTEA' : 'BLOB',
        };
    }

    /**
     * Renders a PHP value as a SQL literal. The pack's only value-to-SQL
     * conversion — see {@see Dialect::escapeString()} for the escaping rules
     * and why they are dialect-specific.
     *
     * A bool is the one value whose rendering depends on the dialect rather
     * than only on the escaping. `DEFAULT 1` against a `BOOLEAN` column is a
     * type error on PostgreSQL — DDL literals are typed, unlike bound
     * parameters — so PostgreSQL gets `TRUE`/`FALSE` and the other two, whose
     * boolean column is an integer column wearing a hat, get `1`/`0`.
     *
     * @throws BadSchema when the value has no literal form
     */
    public function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $this->dialect === Dialect::Pgsql
                ? ($value ? 'TRUE' : 'FALSE')
                : ($value ? '1' : '0');
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \BackedEnum) {
            return $this->literal($value->value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $this->dialect->escapeString($value->format(Bindings::DATE_FORMAT));
        }
        if (is_string($value)) {
            return $this->dialect->escapeString($value);
        }
        throw BadSchema::notALiteral($value);
    }

    /**
     * Everything the definition must satisfy before a single statement is
     * emitted. Running the whole check first means a bad table reports all of
     * its problems in one pass rather than failing on the first statement and
     * leaving the rest unexamined.
     *
     * @throws BadSchema
     */
    private function validate(Table $table): void
    {
        if ($table->name === '') {
            throw BadSchema::emptyTableName();
        }

        $declared = [];
        $autoIncrement = null;
        foreach ($table->columns() as $column) {
            $declared[] = $column->name;
            if (!$column->autoIncrement) {
                continue;
            }
            if (!in_array($column->type, [ColumnType::Int, ColumnType::BigInt], true)) {
                throw BadSchema::autoIncrementOnNonInteger($table->name, $column->name, $column->type->value);
            }
            if ($autoIncrement !== null) {
                throw BadSchema::multipleAutoIncrement($table->name);
            }
            $autoIncrement = $column->name;
        }

        if ($autoIncrement !== null && $table->primaryKey() !== []) {
            throw BadSchema::autoIncrementWithCompositeKey($table->name);
        }

        foreach ($table->primaryKey() as $name) {
            if (!in_array($name, $declared, true)) {
                throw BadSchema::unknownColumn($table->name, $name, 'PRIMARY KEY', $declared);
            }
        }

        $this->validateIndexes($table, $declared);
    }

    /**
     * @param list<string> $declared every column the table will have
     * @throws BadSchema
     */
    private function validateIndexes(Table $table, array $declared): void
    {
        $indexNames = [];
        foreach ($table->indexes() as $index) {
            if (isset($indexNames[$index->name])) {
                throw BadSchema::duplicateIndex($table->name, $index->name);
            }
            $indexNames[$index->name] = true;
            foreach ($index->columns as $name) {
                if (!in_array($name, $declared, true)) {
                    throw BadSchema::unknownColumn($table->name, $name, "INDEX '{$index->name}'", $declared);
                }
            }
        }
    }

    private function column(ColumnDef $column, bool $inlineReferences): string
    {
        $name = $this->dialect->quote($column->name);

        // Auto-increment is a phrase, not a modifier: SQLite's spelling is
        // `INTEGER PRIMARY KEY AUTOINCREMENT`, PostgreSQL's replaces the type
        // with SERIAL, MySQL's is a suffix on an ordinary type. So it takes
        // over the whole column rather than appending to one.
        if ($column->autoIncrement) {
            return $name . ' ' . $this->autoIncrement($column);
        }

        $sql = $name . ' ' . $this->type($column);
        if ($column->primary) {
            $sql .= ' PRIMARY KEY';
        }
        if (!$column->nullable) {
            $sql .= ' NOT NULL';
        }

        $sql .= $this->defaultClause($column);

        return $inlineReferences ? $sql . $this->referenceClause($column) : $sql;
    }

    /**
     * A reference as a table-level constraint. Named deterministically so a
     * later migration can drop it — see {@see ColumnDef::foreignKeyName()}.
     */
    private function foreignKey(string $table, ColumnDef $column): string
    {
        return 'CONSTRAINT ' . $this->dialect->quote(ColumnDef::foreignKeyName($table, $column->name))
            . ' FOREIGN KEY (' . $this->dialect->quote($column->name) . ')'
            . ' REFERENCES ' . $this->dialect->quote((string) $column->referencesTable)
            . ' (' . $this->dialect->quote($column->referencesColumn) . ')'
            . $this->referenceActions($column);
    }

    private function autoIncrement(ColumnDef $column): string
    {
        return match ($this->dialect) {
            // SQLite requires the exact spelling INTEGER (not BIGINT) for the
            // rowid alias, so the column's requested width cannot be honoured.
            Dialect::Sqlite => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            Dialect::Mysql => $this->type($column) . ' NOT NULL AUTO_INCREMENT PRIMARY KEY',
            Dialect::Pgsql => $column->type === ColumnType::BigInt
                ? 'BIGSERIAL PRIMARY KEY'
                : 'SERIAL PRIMARY KEY',
        };
    }

    private function defaultClause(ColumnDef $column): string
    {
        if ($column->default === null) {
            return '';
        }
        $value = $column->default['kind'] === 'sql'
            ? (string) $column->default['value']
            : $this->literal($column->default['value']);

        return ' DEFAULT ' . $value;
    }

    private function referenceClause(ColumnDef $column): string
    {
        if ($column->referencesTable === null) {
            return '';
        }
        $sql = ' REFERENCES ' . $this->dialect->quote($column->referencesTable)
            . ' (' . $this->dialect->quote($column->referencesColumn) . ')';

        return $sql . $this->referenceActions($column);
    }

    private function referenceActions(ColumnDef $column): string
    {
        $sql = '';
        if ($column->onDelete !== null) {
            $sql .= ' ON DELETE ' . $column->onDelete->value;
        }
        if ($column->onUpdate !== null) {
            $sql .= ' ON UPDATE ' . $column->onUpdate->value;
        }

        return $sql;
    }

    /** @param list<string> $columns */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map($this->dialect->quote(...), $columns));
    }
}
