<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * A frozen statement. The compiler consumes only these, never a builder, so
 * the pure SQL layer has no dependency on mutable state — which is what makes
 * {@see \Lava\Db\Sql\Compiler} testable without a database, and what lets a
 * query be inspected (logged, asserted, dumped) after it was built.
 *
 * The constructors of the four concrete queries are private: each has an
 * invariant (a DELETE must be bounded, an INSERT's rows must line up) and a
 * public constructor would let that invariant be broken. Build them through
 * `of()` or through {@see QueryBuilder}, and the compiler can trust them.
 */
abstract class Query implements Statement
{
    /**
     * @param list<Condition> $conditions
     */
    public function __construct(
        public readonly string $table,
        public readonly array $conditions = [],
    ) {
    }

    /**
     * A Query is already what a Statement promises, so this is the identity.
     * It is `final` because overriding it would break the contract that the
     * connection can run anything a Statement hands it.
     */
    final public function query(): Query
    {
        return $this;
    }
}
