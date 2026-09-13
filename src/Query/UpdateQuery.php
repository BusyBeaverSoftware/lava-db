<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * An UPDATE. `of()` refuses two things, and both refusals are the point:
 *
 * - no columns, which would compile to `SET` with nothing after it;
 * - no conditions, which would rewrite every row in the table.
 *
 * The second is the one that matters. A query builder is where a missing
 * WHERE is easiest to write and hardest to notice, and the damage is
 * total and silent. Refusing it costs an explicit `->whereRaw('1 = 1')`
 * from anyone who genuinely means "all rows" — friction in exactly the
 * place it belongs, and a line in the diff that a reviewer will see.
 */
final class UpdateQuery extends Query
{
    /**
     * @param array<string, int|float|string|null> $values
     * @param list<Condition> $conditions
     */
    private function __construct(
        string $table,
        public readonly array $values,
        array $conditions,
    ) {
        parent::__construct($table, $conditions);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<Condition> $conditions
     * @throws BadQuery
     */
    public static function of(string $table, array $values, array $conditions): self
    {
        if ($values === []) {
            throw BadQuery::emptyWrite('update', $table);
        }
        foreach (array_keys($values) as $column) {
            ColumnName::check((string) $column, 'update()');
        }
        if ($conditions === []) {
            throw BadQuery::unbounded('UPDATE', $table);
        }
        return new self($table, Bindings::map($values), $conditions);
    }
}
