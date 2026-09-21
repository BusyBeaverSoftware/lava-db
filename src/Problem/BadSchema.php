<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A table definition that cannot be compiled into DDL.
 *
 * These are the mistakes a schema DSL invites: two columns with one name, an
 * index over a column that was renamed, an auto-increment column that is not
 * an integer. Every one of them would otherwise surface as a database error
 * partway through a migration — after some statements had already run — with
 * a message about SQL rather than about the definition that produced it.
 * Catching them at compile time means the report names the table and column
 * the developer actually wrote.
 */
final class BadSchema extends LavaProblem
{
    public static function emptyTableName(): self
    {
        return new self(
            'A table definition has an empty name.',
            "Pass the table name: \$schema->create('users', function (Table \$t) { ... }).",
            [],
        );
    }

    /**
     * The schema DSL was given a table name that is not a name.
     *
     * Every other identifier position in the pack went through the builder's
     * grammar; the table did not, so `drop("a\" b'c;--")` compiled a (correctly
     * quoted) statement the builder would have refused outright. Quoting held
     * either way — this closes the asymmetry, not an injection (security
     * review).
     */
    public static function notATableName(string $table, string $call): self
    {
        return new self(
            "{$call} was given '{$table}', which is not a table name. A name is letters, digits and "
                . "underscores, not starting with a digit, optionally qualified ('posts', 'main.posts').",
            "Pass the table's own name: \$schema->{$call}('posts', …). A name built from input belongs "
                . 'in an allowlist your code owns — an identifier is code, not data.',
            ['table' => $table, 'call' => $call],
        );
    }

    public static function emptyColumnName(string $table): self
    {
        return new self(
            "Table '{$table}' has a column with an empty name.",
            "Give every column a name: \$t->string('name'), not \$t->string('').",
            ['table' => $table],
        );
    }

    public static function duplicateColumn(string $table, string $column): self
    {
        return new self(
            "Table '{$table}' declares the column '{$column}' twice.",
            'Remove one of the two declarations — the second would silently win.',
            ['table' => $table, 'column' => $column],
        );
    }

    public static function autoIncrementOnNonInteger(string $table, string $column, string $type): self
    {
        return new self(
            "Column '{$column}' of table '{$table}' is {$type} and cannot auto-increment.",
            "Use \$t->id() (or \$t->bigInt('{$column}')->autoIncrement()) — only integer columns can auto-increment in SQLite, MySQL, and PostgreSQL.",
            ['table' => $table, 'column' => $column, 'type' => $type],
        );
    }

    public static function multipleAutoIncrement(string $table): self
    {
        return new self(
            "Table '{$table}' has more than one auto-increment column.",
            'A table has at most one: keep ->autoIncrement() on the primary key and remove it from the others.',
            ['table' => $table],
        );
    }

    public static function autoIncrementWithCompositeKey(string $table): self
    {
        return new self(
            "Table '{$table}' declares an auto-increment column AND a composite primary key; they cannot both be the primary key.",
            'Drop the ->primary([...]) call, or drop ->autoIncrement() from the integer column.',
            ['table' => $table],
        );
    }

    /**
     * @param list<string> $declared every column the table does declare, so
     *                              the fix can name them rather than making
     *                              the reader go and look
     */
    public static function unknownColumn(string $table, string $column, string $by, array $declared): self
    {
        return new self(
            "{$by} on table '{$table}' names the column '{$column}', which the table does not declare.",
            'Fix the spelling, or declare the column. The table declares: ' . implode(', ', $declared) . '.',
            ['table' => $table, 'column' => $column, 'declared' => $declared],
        );
    }

    public static function emptyIndex(string $index): self
    {
        return new self(
            "Index '{$index}' covers no columns.",
            "Pass at least one: \$t->index('email'), or \$t->index(['first', 'last']).",
            ['index' => $index],
        );
    }

    public static function duplicateIndex(string $table, string $index): self
    {
        return new self(
            "Table '{$table}' declares an index named '{$index}' twice.",
            'Name the second one explicitly: \$t->index([...], name: \'...\').',
            ['table' => $table, 'index' => $index],
        );
    }

    /** @param list<string> $indexes the indexes the table does have */
    public static function unknownIndex(string $table, string $index, array $indexes): self
    {
        $naming = 'An index declared without a name is called <table>_<columns>_index, or <table>_<columns>_unique for unique().';

        return new self(
            "Table '{$table}' has no index named '{$index}'.",
            ($indexes === []
                ? "Table '{$table}' has no indexes at all; check the table name. "
                : 'Its indexes are: ' . implode(', ', $indexes) . '. ') . $naming,
            ['table' => $table, 'index' => $index, 'indexes' => $indexes],
        );
    }

    public static function primaryKeyOnExistingTable(string $table): self
    {
        return new self(
            "Adding a primary key to the existing table '{$table}' is not portable: "
            . 'SQLite cannot add one without rebuilding the table.',
            "Declare the key when the table is created, with \$schema->create(). To change an existing table's key, "
            . 'create a new table with it, copy the rows across, drop the old table and rename the new one.',
            ['table' => $table],
        );
    }

    /**
     * A string default holding a backslash, which cannot be escaped safely for
     * every dialect at once.
     *
     * Escaping is the unsafe part: doubling the backslash for MySQL breaks
     * under a multibyte connection charset, and not doubling it for PostgreSQL
     * is correct only while `standard_conforming_strings` is on. Neither is
     * knowable from the compiler, so the value is refused and the deliberate
     * escape hatch is named (security review).
     */
    public static function unsafeDefault(string $value): self
    {
        return new self(
            'A default value holding a backslash cannot be written as a literal that means the same thing '
                . 'on every dialect.',
            "Drop the backslash, or write the default as SQL yourself with ->defaultExpression('…'), which "
                . 'says you have checked what the target database will make of it.',
            ['value' => $value],
        );
    }

    public static function notALiteral(mixed $value): self
    {
        return new self(
            'A column default must be a scalar, a DateTimeInterface, or a backed enum; got ' . get_debug_type($value) . '.',
            "Pass a literal, or an SQL expression: ->defaultExpression('CURRENT_TIMESTAMP').",
            ['type' => get_debug_type($value)],
        );
    }

    public static function inlineReferenceUnsupported(string $table, string $column): self
    {
        return new self(
            "Adding the referencing column '{$column}' to table '{$table}' is not portable: "
            . 'MySQL parses an inline REFERENCES clause on ALTER TABLE ADD COLUMN and then ignores it, '
            . 'so the foreign key would exist on SQLite and PostgreSQL and silently not exist on MySQL.',
            "Create the table with \$schema->create() instead (its foreign keys are emitted as table-level "
            . "constraints, which all three dialects honour), or add the column and its constraint yourself "
            . "with \$db->statement('ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ...').",
            ['table' => $table, 'column' => $column],
        );
    }

    /**
     * @param list<string> $declared the referenced table's columns, so the fix
     *                               can name them
     */
    public static function unknownReferencedColumn(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        array $declared,
    ): self {
        return new self(
            "Column '{$column}' of table '{$table}' references '{$referencedTable}' ('{$referencedColumn}'), "
            . "but table '{$referencedTable}' has no column '{$referencedColumn}'.",
            "'{$referencedTable}' declares: " . implode(', ', $declared) . '. Point the reference at one of those, '
            . 'or add the column first. The target must also be a PRIMARY KEY or UNIQUE column — SQLite accepts the '
            . 'constraint without it and then reports "foreign key mismatch" on the first insert.',
            [
                'table' => $table,
                'column' => $column,
                'referenced_table' => $referencedTable,
                'referenced_column' => $referencedColumn,
            ],
        );
    }

    public function code(): string
    {
        return 'bad_schema';
    }
}
