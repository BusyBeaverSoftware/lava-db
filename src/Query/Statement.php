<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * Something the connection can run.
 *
 * This interface exists so {@see \Lava\Db\Connection}'s verbs can take either
 * a {@see QueryBuilder} (still being composed) or a {@see Query} (already
 * frozen) without a union type at every call site and without an `instanceof`
 * inside the connection. A builder is a statement that has not decided what
 * it is yet; a Query is one that has.
 */
interface Statement
{
    /** The frozen query this statement compiles from. */
    public function query(): Query;
}
