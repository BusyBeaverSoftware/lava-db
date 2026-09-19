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
    /**
     * @param string $call the builder call that was given it — see {@see \Lava\Db\Query\ColumnName}
     */
    public static function notAColumn(string $column, string $call = 'select()'): self
    {
        return new self(
            "{$call} was given '{$column}', which the builder does not take as a name. It takes letters, digits "
                . "and underscores, optionally qualified ('title', 'posts.title', 'main.posts.title'; select() "
                . "also takes '*' and 'posts.*'), and quotes that as an identifier — so an expression or an alias "
                . 'would be quoted too, and SQLite answers an unknown quoted identifier with the string itself.',
            'Write anything else as SQL, through the escape hatches: '
                . "->whereRaw('LOWER(email) = ?', [\$email]) for a condition, "
                . "\$db->query('SELECT COUNT(*) AS total FROM posts WHERE …', \$bindings) for a read, "
                . "and \$db->statement('UPDATE …', \$bindings) for a write.",
            ['column' => $column, 'call' => $call],
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

    public static function notAnAlias(string|int $alias): self
    {
        return new self(
            is_int($alias)
                ? "select() was given an array with the key {$alias}. An array in select() maps aliases to columns, so every key is an alias."
                : "select() was given the alias '{$alias}', which is not one name. An alias is letters, digits and underscores, not starting with a digit, with no dots and no '*'.",
            is_int($alias)
                ? "Pass column names as separate arguments, select(...\$names), and aliases as a map: select('posts.id', ['author' => 'users.name'])."
                : "Write the alias as one name: select(['author' => 'users.name']).",
            ['alias' => $alias],
        );
    }

    /**
     * Two columns of one select() would come back under one name.
     *
     * The fix is the reader's own call with one change, so it can be pasted
     * back as it is: the second column gets an alias no column of the call
     * already comes back under (`users_id`, else `users_id_2`, …), and every
     * other argument and alias stays as written. It used to print only the two
     * columns, with an alias that could already be taken, so following it could
     * be refused again (Lava Notes, R3-B6). `context.suggested` holds the same
     * arguments, for a caller that would rather not read them out of the fix.
     *
     * Two column references that differ only in case are the same clash, because
     * SQLite returns each under its table's declared name (Lava Notes, R3-B6).
     * The message then names both spellings rather than claiming one name the
     * reader never wrote.
     *
     * @param array{column: string, alias: string|null, argument: int, name: string} $first
     * @param array{column: string, alias: string|null, argument: int, name: string} $second
     * @param list<string|array<mixed>> $arguments select()'s arguments, as given
     * @param list<string> $taken every name the call's columns come back under
     */
    public static function sameResultName(array $first, array $second, array $arguments, array $taken): self
    {
        $base = str_replace('.', '_', $second['column']);
        $lowered = array_map(strtolower(...), $taken);
        $suggestion = $base;
        for ($n = 2; in_array(strtolower($suggestion), $lowered, true); $n++) {
            $suggestion = "{$base}_{$n}";
        }

        $suggested = $arguments;
        $entry = $arguments[$second['argument']];
        if (is_array($entry)) {
            $renamed = [];
            foreach ($entry as $alias => $column) {
                $renamed[$alias === $second['alias'] ? $suggestion : $alias] = $column;
            }
            $suggested[$second['argument']] = $renamed;
        } else {
            $suggested[$second['argument']] = [$suggestion => $second['column']];
        }

        $alike = $first['name'] === $second['name'];

        return new self(
            'select() would return two columns named '
                . ($alike ? "'{$second['name']}'" : "'{$first['name']}' and '{$second['name']}'")
                . ', ' . self::side($first) . ' and ' . self::side($second)
                . ($alike
                    ? ', and a row keeps only the last of them.'
                    : ', which a database that folds case returns as one name, keeping only one of them.'),
            ($second['alias'] === null ? 'Alias one of them: ' : 'Give one of them another alias: ')
                . 'select(' . implode(', ', array_map(self::source(...), $suggested)) . ').',
            [
                'name' => $second['name'],
                'names' => [$first['name'], $second['name']],
                'columns' => [$first['column'], $second['column']],
                'suggested' => $suggested,
            ],
        );
    }

    /** @param array{column: string, alias: string|null, argument: int} $side */
    private static function side(array $side): string
    {
        return self::source($side['alias'] === null ? $side['column'] : [$side['alias'] => $side['column']]);
    }

    /** A select() argument written as PHP: `'posts.id'`, `['author' => 'users.name']`. */
    private static function source(mixed $value): string
    {
        if (is_array($value)) {
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = self::source($key) . ' => ' . self::source($item);
            }

            return '[' . implode(', ', $pairs) . ']';
        }

        return is_string($value) ? "'" . addcslashes($value, "'\\") . "'" : var_export($value, true);
    }

    public static function writeInRead(string $verb): self
    {
        return new self(
            "->{$verb}() reads rows, but this query is an INSERT, UPDATE or DELETE.",
            "Pass the builder before its write terminal, \$db->{$verb}(\$db->table('posts')->where(…)); run a write with run().",
            ['method' => $verb],
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
