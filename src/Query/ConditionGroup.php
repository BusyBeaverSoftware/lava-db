<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * The builder a `whereGroup` closure is handed, and the only thing it can be.
 *
 * It exists so that a group is expressed with the same vocabulary as a chain —
 * `$q->where(...)->orWhere(...)` — while being unable to do anything a group
 * cannot mean. A `QueryBuilder` here would accept `->limit(5)` and drop it,
 * which is the silent-ignore failure this framework refuses everywhere else; a
 * class whose only methods are the condition ones cannot make that mistake, so
 * the restriction is a type rather than a rule someone has to remember.
 *
 * The bodies live in {@see HasConditions}, shared with `QueryBuilder`, so the
 * two cannot drift apart on what `IN ()` or `= NULL` means.
 *
 * Mutable while the closure runs, and read once into an immutable
 * {@see Condition} when it returns — the same freeze-as-you-terminal shape the
 * rest of the builder uses. Nothing keeps a reference to this object afterwards,
 * so a group cannot be altered after the query was built.
 */
final class ConditionGroup
{
    use HasConditions;
}
