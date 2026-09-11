<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * One term of a WHERE clause — or one parenthesised group of them — validated
 * at construction.
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
 * A condition is either one TERM or one GROUP, never both, and {@see isGroup()}
 * is the only sanctioned way to ask which. A group carries no operator and no
 * bindings of its own: its text comes from the conditions inside it, rendered by
 * the same compiler method that renders a flat list, so quoting and placeholder
 * order still have one home and a group cannot smuggle a value past the
 * compiler. Its `expression`/`operator`/`bindings` are then not read at all —
 * they hold `''`, {@see Operator::Raw} and `[]` because the properties are
 * non-nullable, not because they mean anything. That is the one wrinkle of
 * putting a group in this type rather than in a second one, and naming it here
 * is cheaper than a reader guessing.
 *
 * Groups are what make a chain honest. Without one, `[a AND, b OR, c AND]`
 * compiles to `a AND b OR c`, which SQL reads as `(a AND b) OR c` — the chain
 * reads like a grouped boolean expression and is not one, and `whereRaw` was
 * the only way to say what you meant. With one, `whereGroup(fn ($q) =>
 * $q->where(a)->orWhere(b))->where(c)` compiles to `(a OR b) AND c`.
 */
final readonly class Condition
{
    /**
     * @param list<int|float|string|null> $bindings
     * @param list<Condition> $group the conditions inside a group; empty for a term
     */
    private function __construct(
        public bool $or,
        public string $expression,
        public Operator $operator,
        public array $bindings,
        public array $group = [],
    ) {
    }

    /**
     * `( … )` — one nested list, combined by the same left-to-right rule as a
     * flat one, so `whereGroup` and a bare chain mean the same thing inside
     * their own parentheses.
     *
     * An empty group is refused rather than compiled: `()` is a syntax error on
     * every dialect, and a caller who wrote a closure that added nothing meant
     * something they did not say. This is also what makes `isGroup()` total —
     * a group is never the empty list.
     *
     * @param list<Condition> $conditions
     */
    public static function group(bool $or, array $conditions): self
    {
        if ($conditions === []) {
            throw BadQuery::emptyGroup();
        }
        return new self($or, '', Operator::Raw, [], $conditions);
    }

    /** Whether this is a group rather than a term. Never read `$group` directly to decide. */
    public function isGroup(): bool
    {
        return $this->group !== [];
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
