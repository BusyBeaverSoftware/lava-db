<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * A DELETE. Like {@see UpdateQuery}, it refuses to be built without
 * conditions — see that class for why the refusal is worth the friction.
 *
 * There is deliberately no `truncate()` here. Emptying a table is a schema
 * operation with different semantics (it resets auto-increment, it is not
 * transactional on MySQL), so it belongs to the schema layer, not to a
 * builder whose other methods are all row-scoped.
 */
final class DeleteQuery extends Query
{
    /**
     * @param list<Condition> $conditions
     */
    private function __construct(string $table, array $conditions)
    {
        parent::__construct($table, $conditions);
    }

    /**
     * @param list<Condition> $conditions
     * @throws BadQuery when the statement would delete every row
     */
    public static function of(string $table, array $conditions): self
    {
        if ($conditions === []) {
            throw BadQuery::unbounded('DELETE', $table);
        }
        return new self($table, $conditions);
    }
}
