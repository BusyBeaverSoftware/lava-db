<?php

declare(strict_types=1);

namespace Lava\Db\Schema;

/**
 * A named index over one or more columns.
 *
 * Indexes are emitted as separate `CREATE INDEX` statements rather than as
 * inline table constraints. Inline `UNIQUE` looks tidier in the generated
 * DDL, but each dialect then invents its own name for the implicit index,
 * which means a later migration cannot drop it without first asking the
 * database what it decided to call the thing. A name we chose is a name we
 * can use again.
 */
final readonly class Index
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
    ) {
    }

    /**
     * The default name for an index over these columns, e.g.
     * `users_email_unique`. Deterministic, so a migration can drop an index
     * it never named.
     *
     * @param list<string> $columns
     */
    public static function defaultName(string $table, array $columns, bool $unique): string
    {
        return $table . '_' . implode('_', $columns) . ($unique ? '_unique' : '_index');
    }
}
