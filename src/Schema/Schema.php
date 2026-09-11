<?php

declare(strict_types=1);

namespace Lava\Db\Schema;

use Lava\Db\Connection;
use Lava\Db\Problem\BadSchema;
use Lava\Db\Sql\SchemaCompiler;

/**
 * The schema DSL's entry point: `$db->schema()->create('users', fn (Table $t) => …)`.
 *
 * It is a thin coordinator on purpose. The definition lives in {@see Table},
 * the DDL lives in {@see SchemaCompiler}, and this class only joins them and
 * hands the statements to the connection — which means the interesting half
 * of the schema layer is testable without a database, and this half has
 * almost nothing in it that could be wrong.
 *
 * `table()` adds columns and nothing else; see {@see SchemaCompiler::addColumns()}
 * for why changing a column is not offered.
 */
final class Schema
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param \Closure(Table): void $define
     */
    public function create(string $table, \Closure $define): void
    {
        $definition = $this->describe($table, $define);
        $this->checkReferences($definition);

        $this->run((new SchemaCompiler($this->connection->dialect()))->create($definition));
    }

    /**
     * Adds columns to an existing table.
     *
     * @param \Closure(Table): void $define
     */
    public function table(string $table, \Closure $define): void
    {
        $definition = $this->describe($table, $define);
        $this->checkReferences($definition);

        $this->run((new SchemaCompiler($this->connection->dialect()))
            ->addColumns($table, $definition->columns()));
    }

    public function drop(string $table): void
    {
        $this->connection->execute((new SchemaCompiler($this->connection->dialect()))->dropTable($table));
    }

    /** For migrations that must be re-runnable — `down()` usually wants this one. */
    public function dropIfExists(string $table): void
    {
        $this->connection->execute((new SchemaCompiler($this->connection->dialect()))->dropTable($table, true));
    }

    public function has(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /** @return list<string> every table in the database, sorted — includes the migration repository */
    public function tables(): array
    {
        return SchemaSnapshot::of($this->connection)->tableNames();
    }

    /**
     * @param \Closure(Table): void $define
     */
    private function describe(string $table, \Closure $define): Table
    {
        $definition = new Table($table);
        $define($definition);

        return $definition;
    }

    /**
     * Checks each reference against a table that already exists.
     *
     * A reference to a table that is not there yet is left alone — migrations
     * create `posts` before `users` all the time, and refusing that would
     * reject working code. But when the target *is* there, a reference to a
     * column it does not have is a mistake that no dialect reports at DDL
     * time: SQLite accepts the constraint happily and then answers the first
     * insert with `foreign key mismatch - "posts" referencing "users"`, which
     * names neither the column nor the fix.
     *
     * The snapshot is taken at most once, and only when there is a reference
     * to check — a schema with no foreign keys pays nothing for this.
     *
     * @throws \Lava\Db\Problem\BadSchema
     */
    private function checkReferences(Table $table): void
    {
        $snapshot = null;

        foreach ($table->columns() as $column) {
            $target = $column->referencesTable;

            // A self-reference points at the table being created, which is by
            // definition not in the snapshot yet.
            if ($target === null || $target === $table->name) {
                continue;
            }

            $snapshot ??= SchemaSnapshot::of($this->connection);

            if (!$snapshot->has($target)) {
                continue;
            }

            $declared = $snapshot->columns($target);
            if (!array_key_exists($column->referencesColumn, $declared)) {
                throw BadSchema::unknownReferencedColumn(
                    $table->name,
                    $column->name,
                    $target,
                    $column->referencesColumn,
                    array_keys($declared),
                );
            }
        }
    }

    /** @param list<\Lava\Db\Sql\Compiled> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    }
}
