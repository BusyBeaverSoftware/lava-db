<?php

declare(strict_types=1);

namespace Lava\Db\Schema;

use Lava\Db\Problem\BadSchema;

/**
 * A table being described, inside the closure `$schema->create()` calls.
 *
 * The typed shorthands (`string()`, `bool()`, `timestamps()`) exist so the
 * common column is one call rather than a constructor with named arguments.
 * They are pure convenience over {@see ColumnDef} — every one of them returns
 * the ColumnDef, so anything a shorthand does not cover is one modifier away.
 *
 * Note what is NOT here: no `->unsigned()`, no `->after()`, no `->charset()`.
 * Those are single-dialect instructions, and a portable schema DSL that
 * accepts them has to either ignore them (a silent lie) or reject them
 * (at which point they may as well not exist).
 */
final class Table
{
    /** @var list<ColumnDef> */
    private array $columns = [];

    /** @var list<Index> */
    private array $indexes = [];

    /** @var list<string> */
    private array $primaryKey = [];

    public function __construct(public readonly string $name)
    {
    }

    /**
     * The conventional auto-increment primary key. `BigInt` by default
     * because a 32-bit key is a decision that ages badly and costs nothing
     * to avoid.
     */
    public function id(string $name = 'id', ColumnType $type = ColumnType::BigInt): ColumnDef
    {
        return $this->add($name, $type)->autoIncrement()->primary();
    }

    public function int(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Int);
    }

    public function bigInt(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::BigInt);
    }

    public function string(string $name, int $length = 255): ColumnDef
    {
        return $this->add($name, ColumnType::String, length: $length);
    }

    public function text(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Text);
    }

    public function bool(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Bool);
    }

    public function float(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Float);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDef
    {
        return $this->add($name, ColumnType::Decimal, precision: $precision, scale: $scale);
    }

    public function date(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Date);
    }

    public function dateTime(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::DateTime);
    }

    public function time(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Time);
    }

    public function json(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Json);
    }

    public function uuid(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Uuid);
    }

    public function binary(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::Binary);
    }

    /** A foreign-key column, named the way the convention names it (`user_id`). */
    public function foreignId(string $name): ColumnDef
    {
        return $this->add($name, ColumnType::BigInt);
    }

    /**
     * `created_at` and `updated_at`, both nullable.
     *
     * Nullable because the framework does not write them for you — there is
     * no model layer to hook, by design — so a NOT NULL column would fail on
     * the first insert that did not set them. Set them in the code that
     * writes the row, or make them non-null yourself once you do.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    public function softDeletes(): void
    {
        $this->dateTime('deleted_at')->nullable();
    }

    /** A composite primary key. Cannot be combined with an auto-increment column. */
    public function primary(string ...$columns): self
    {
        $this->primaryKey = array_values($columns);
        return $this;
    }

    /**
     * A unique index over one or more columns.
     *
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex($columns, true, $name);
    }

    /**
     * A non-unique index over one or more columns.
     *
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        return $this->addIndex($columns, false, $name);
    }

    /** @return list<ColumnDef> in declaration order */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<Index> in declaration order, including column-level ->unique() */
    public function indexes(): array
    {
        $indexes = [];
        foreach ($this->columns as $column) {
            if ($column->unique) {
                $indexes[] = new Index(
                    Index::defaultName($this->name, [$column->name], true),
                    [$column->name],
                    true,
                );
            }
        }
        return [...$indexes, ...$this->indexes];
    }

    /** @return list<string> */
    public function primaryKey(): array
    {
        return $this->primaryKey;
    }

    /**
     * @param string|list<string> $columns
     */
    private function addIndex(string|array $columns, bool $unique, ?string $name): self
    {
        $list = is_string($columns) ? [$columns] : $columns;
        if ($list === []) {
            throw BadSchema::emptyIndex($name ?? '');
        }
        $this->indexes[] = new Index($name ?? Index::defaultName($this->name, $list, $unique), $list, $unique);
        return $this;
    }

    private function add(
        string $name,
        ColumnType $type,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
    ): ColumnDef {
        if ($name === '') {
            throw BadSchema::emptyColumnName($this->name);
        }
        foreach ($this->columns as $existing) {
            if ($existing->name === $name) {
                throw BadSchema::duplicateColumn($this->name, $name);
            }
        }
        $column = new ColumnDef($name, $type, $length, $precision, $scale);
        $this->columns[] = $column;
        return $column;
    }
}
