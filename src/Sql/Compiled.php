<?php

declare(strict_types=1);

namespace Lava\Db\Sql;

/**
 * One statement, ready to hand to PDO: SQL text plus the values that fill its
 * placeholders, in order.
 *
 * Bindings are separate from the SQL on purpose, and that separation is the
 * whole safety story. A value never becomes part of the string, so no value
 * can be SQL — there is no escaping to get wrong, no `quote()` call to forget,
 * and the same statement text is reused for every execution (which is also
 * what lets a database cache its plan).
 *
 * The types are narrowed to what PDO can bind directly. Anything else —
 * a DateTime, a backed enum, a bool — was converted on the way in by
 * {@see \Lava\Db\Query\Bindings}, so a Compiled never holds a value the
 * driver would stringify by accident.
 */
final readonly class Compiled
{
    /** @param list<int|float|string|null> $bindings */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {
    }

    /** @return array{sql: string, bindings: list<int|float|string|null>} */
    public function json(): array
    {
        return ['sql' => $this->sql, 'bindings' => $this->bindings];
    }
}
