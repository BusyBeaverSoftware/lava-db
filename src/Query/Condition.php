<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * One term of a WHERE clause, validated at construction.
 *
 * Every factory here closes a hole that a looser API leaves open:
 * `compare()` refuses NULL (which SQL compares as *unknown*, so `= NULL`
 * silently matches nothing), `in()` refuses an empty list (which compiles to
 * `IN ()`, a syntax error on every dialect), and `compare()` refuses the
 * operators that need their own method so `->where('id', Operator::In, [1])`
 * is a problem with a fix rather than a mangled statement.
 *
 * Validation lives here rather than in the builder so the invariant holds for
 * *every* Condition, including ones a test or a future caller builds without
 * a builder. The compiler can then trust what it is handed, and never has to
 * re-check.
 *
 * Conditions are a flat list, combined left to right with no precedence
 * grouping: `[a AND, b AND, c OR]` compiles to `a AND b OR c`, which SQL
 * reads as `(a AND b) OR c`. That is the honest reading of "the conditions
 * in the order you wrote them", but it is not a tree — for anything that
 * needs real grouping, write the parentheses yourself with `raw()`.
 */
final readonly class Condition
{
    /**
     * @param list<int|float|string|null> $bindings
     */
    private function __construct(
        public bool $or,
        public string $expression,
        public Operator $operator,
        public array $bindings,
    ) {
    }

    /** `column <op> value` — the ordinary two-sided comparison. */
    public static function compare(bool $or, string $column, Operator $operator, mixed $value): self
    {
        if (!$operator->isComparison()) {
            throw BadQuery::notAComparison($operator);
        }
        if ($value === null) {
            throw BadQuery::nullComparison($column);
        }
        return new self($or, $column, $operator, [Bindings::normalize($value, $column)]);
    }

    /**
     * `column IN (…)` — one placeholder per value, never a literal list, so a
     * value can never be read as SQL.
     *
     * @param array<mixed> $values
     */
    public static function in(bool $or, string $column, array $values, bool $not = false): self
    {
        if ($values === []) {
            throw BadQuery::emptyIn($column);
        }
        return new self(
            $or,
            $column,
            $not ? Operator::NotIn : Operator::In,
            Bindings::list($values, $column),
        );
    }

    public static function between(bool $or, string $column, mixed $low, mixed $high): self
    {
        return new self($or, $column, Operator::Between, [
            Bindings::normalize($low, $column),
            Bindings::normalize($high, $column),
        ]);
    }

    public static function null(bool $or, string $column, bool $not = false): self
    {
        return new self($or, $column, $not ? Operator::IsNotNull : Operator::IsNull, []);
    }

    /**
     * The escape hatch: a fragment the caller wrote, with its own bindings.
     *
     * It exists because no builder anticipates every predicate — a full-text
     * match, a JSON path, a window function. It is still parameter-bound when
     * you pass bindings; what it gives up is identifier quoting, which is the
     * caller's job from here on. Prefer the typed factories, and reach for
     * this when the condition is genuinely not expressible in them.
     *
     * @param array<mixed> $bindings
     */
    public static function raw(bool $or, string $sql, array $bindings = []): self
    {
        if (trim($sql) === '') {
            throw BadQuery::emptyFragment();
        }
        return new self($or, $sql, Operator::Raw, Bindings::list($bindings, '?'));
    }
}
