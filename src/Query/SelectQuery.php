<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * A SELECT. Unlike the write queries, every combination of its parts is
 * valid, so its constructor is public: `new SelectQuery('users')` is a
 * complete, compilable statement, which is exactly what the compiler tests
 * want to be able to write.
 */
final class SelectQuery extends Query
{
    /**
     * @param list<string> $columns
     * @param list<Condition> $conditions
     * @param list<Join> $joins
     * @param list<OrderBy> $orders
     * @param array<int, string> $aliases a position in $columns => the alias that column is selected as
     */
    public function __construct(
        string $table,
        public readonly array $columns = ['*'],
        array $conditions = [],
        public readonly array $joins = [],
        public readonly array $orders = [],
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly array $aliases = [],
    ) {
        parent::__construct($table, $conditions);
    }
}
