<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * One JOIN clause: `type table ON first op second`.
 *
 * Both sides of the ON are column references and are quoted as such. A join
 * against a literal, or one with several ON terms, is not expressible here —
 * that is `raw()` territory, and pretending otherwise would mean a join
 * builder with a half-implemented condition language.
 */
final readonly class Join
{
    public function __construct(
        public JoinType $type,
        public string $table,
        public string $first,
        public Operator $operator,
        public string $second,
    ) {
    }
}
