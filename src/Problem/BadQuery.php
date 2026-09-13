<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Db\Query\Operator;

/**
 * A statement the builder refuses to build.
 *
 * Every factory here exists because the alternative is SQL that is
 * syntactically fine and semantically wrong: `WHERE id = NULL` is never true,
 * `IN ()` is a syntax error, and an unqualified `DELETE` empties the table.
 * Those are the failures a database layer must not let through silently —
 * they do not throw, they return the wrong answer, and the caller finds out
 * in production. So the builder refuses at build time and says exactly what
 * to write instead.
 *
 * This is a *framework* problem, unlike a constraint violation: the query was
 * never valid, so no database was consulted.
 */
final class BadQuery extends LavaProblem
{
    public static function notAColumn(string $column): self
    {
        return new self(
            "select() was given '{$column}', which is not a column name: it would be quoted as an identifier, "
                . 'and SQLite answers an unknown quoted identifier with the string itself instead of an error.',
            "Pass column names only — 'title', 'posts.title', '*' or 'posts.*'. Run an aggregate or an "
                . "expression through the escape hatch: \$db->query('SELECT COUNT(*) AS total FROM posts WHERE …', \$bindings).",
            ['column' => $column],
        );
    }

    public static function nullComparison(string $column): self
    {
        return new self(
            "Cannot compare '{$column}' to NULL: SQL evaluates '{$column} = NULL' as unknown, so the condition would match nothing.",
            "Use ->whereNull('{$column}') or ->whereNotNull('{$column}').",
            ['column' => $column],
        );
    }

    public static function arrayValue(string $column): self
    {
        return new self(
            "Cannot bind an array as the value of '{$column}'.",
            "Use ->whereIn('{$column}', [...]) for a set of values, or JSON-encode the value yourself.",
            ['column' => $column],
        );
    }

    public static function unbindable(string $column, string $type): self
    {
        return new self(
            "Cannot bind a value of type {$type} to '{$column}'.",
            'Bind a scalar, null, a DateTimeInterface, or a backed enum — convert anything else at the edge.',
            ['column' => $column, 'type' => $type],
        );
    }

    public static function emptyIn(string $column): self
    {
        return new self(
            "->whereIn('{$column}', []) has no values to compare against, and 'IN ()' is not valid SQL.",
            "Pass at least one value, or drop the condition — an empty set matches nothing, so the query returns no rows either way.",
            ['column' => $column],
        );
    }

    public static function notAComparison(Operator $operator): self
    {
        return new self(
            "Operator {$operator->name} is not a two-sided comparison, so it cannot be used with ->where().",
            'Use the dedicated method: whereIn()/whereNotIn(), whereNull()/whereNotNull(), whereBetween(), or whereRaw().',
            ['operator' => $operator->name],
        );
    }

    public static function emptyFragment(): self
    {
        return new self(
            '->whereRaw() was given an empty SQL fragment.',
            'Pass the condition to write, e.g. ->whereRaw(\'LOWER(email) = ?\', [$email]).',
            [],
        );
    }

    public static function emptyGroup(): self
    {
        return new self(
            '->whereGroup() was given a group with no conditions in it.',
            'Add at least one condition inside the closure — ->whereGroup(fn ($q) => $q->where(\'a\', Operator::Eq, 1)) — or drop the group and write the condition directly.',
            [],
        );
    }

    public static function unbounded(string $verb, string $table): self
    {
        return new self(
            "Refusing to build {$verb} on '{$table}' with no WHERE clause: it would affect every row in the table.",
            "Add ->where(...), or if every row really is the target, say so: ->whereRaw('1 = 1'), or run the statement directly with \$db->statement('{$verb} {$table}').",
            ['statement' => $verb, 'table' => $table],
        );
    }

    public static function emptyWrite(string $verb, string $table): self
    {
        return new self(
            "Cannot build {$verb} on '{$table}' with no columns.",
            "Pass the values as an associative array: ->{$verb}(['column' => \$value]).",
            ['statement' => $verb, 'table' => $table],
        );
    }

    /**
     * @param list<string> $expected
     * @param list<string> $given
     */
    public static function mixedColumns(array $expected, array $given): self
    {
        return new self(
            'Every row of a multi-row INSERT must set the same columns, in the same order: expected ('
            . implode(', ', $expected) . '), got (' . implode(', ', $given) . ').',
            'Give every row the same keys, or insert the odd rows with separate ->insert() calls.',
            ['expected' => $expected, 'given' => $given],
        );
    }

    public static function negativeLimit(string $flag, int $value): self
    {
        return new self(
            "->{$flag}({$value}) is negative, and a negative " . strtoupper($flag) . ' is not portable SQL.',
            "Pass 0 or more. To read from an offset with no cap, set ->offset({$value}) and leave the limit unset.",
            ['method' => $flag, 'value' => $value],
        );
    }

    public static function readInWrite(string $verb): self
    {
        return new self(
            "->{$verb}() executes a statement, but this query is a SELECT.",
            'Read with fetch(), fetchOne(), or scalar() — run() is for INSERT, UPDATE, and DELETE.',
            ['method' => $verb],
        );
    }

    public function code(): string
    {
        return 'bad_query';
    }
}
