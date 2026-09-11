<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * The where-family, in one place, for the two things that accumulate conditions.
 *
 * There are two: {@see QueryBuilder}, which goes on to select, join, order and
 * limit, and {@see ConditionGroup}, which is what a `whereGroup` closure is
 * handed and can do nothing else. The trait exists so the group cannot be given
 * a `limit()` and then silently ignore it — the pack's rule is that a call is
 * either honoured or refused, never accepted and dropped, and a class that only
 * has condition methods cannot break that rule by accident.
 *
 * Sharing the bodies rather than the type is deliberate. A group is not a
 * builder and a builder is not a group; what they have in common is that they
 * *have* conditions, which is exactly what a trait expresses and an inheritance
 * chain would have to lie about. It also keeps each rule on one bound argument
 * (`IN ()` refused, `= NULL` refused) in one body, so the two can never drift.
 *
 * Every method returns `static`, so the chain inside a closure reads like the
 * chain outside one.
 */
trait HasConditions
{
    /** @var list<Condition> */
    private array $conditions = [];

    /**
     * The conditions accumulated so far, so a caller (or a diagnostic) can
     * read back what the chain decided without freezing the whole query.
     *
     * @return list<Condition>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    public function where(string $column, Operator $operator, mixed $value): static
    {
        $this->conditions[] = Condition::compare(false, $column, $operator, $value);
        return $this;
    }

    public function orWhere(string $column, Operator $operator, mixed $value): static
    {
        $this->conditions[] = Condition::compare(true, $column, $operator, $value);
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->conditions[] = Condition::null(false, $column);
        return $this;
    }

    public function orWhereNull(string $column): static
    {
        $this->conditions[] = Condition::null(true, $column);
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->conditions[] = Condition::null(false, $column, not: true);
        return $this;
    }

    public function orWhereNotNull(string $column): static
    {
        $this->conditions[] = Condition::null(true, $column, not: true);
        return $this;
    }

    /** @param array<mixed> $values */
    public function whereIn(string $column, array $values): static
    {
        $this->conditions[] = Condition::in(false, $column, $values);
        return $this;
    }

    /** @param array<mixed> $values */
    public function orWhereIn(string $column, array $values): static
    {
        $this->conditions[] = Condition::in(true, $column, $values);
        return $this;
    }

    /** @param array<mixed> $values */
    public function whereNotIn(string $column, array $values): static
    {
        $this->conditions[] = Condition::in(false, $column, $values, not: true);
        return $this;
    }

    /** @param array<mixed> $values */
    public function orWhereNotIn(string $column, array $values): static
    {
        $this->conditions[] = Condition::in(true, $column, $values, not: true);
        return $this;
    }

    public function whereBetween(string $column, mixed $low, mixed $high): static
    {
        $this->conditions[] = Condition::between(false, $column, $low, $high);
        return $this;
    }

    public function orWhereBetween(string $column, mixed $low, mixed $high): static
    {
        $this->conditions[] = Condition::between(true, $column, $low, $high);
        return $this;
    }

    /** @param array<mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->conditions[] = Condition::raw(false, $sql, $bindings);
        return $this;
    }

    /** @param array<mixed> $bindings */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->conditions[] = Condition::raw(true, $sql, $bindings);
        return $this;
    }

    /**
     * `( … )` — one parenthesised group, so `(A OR B) AND C` is expressible and
     * the chain reads as the boolean expression it looks like.
     *
     * The closure is handed a {@see ConditionGroup}, not this builder, so the
     * only thing it can add is conditions. It returns nothing: the group is the
     * conditions it collected, and a return value would invite the reader to
     * think the closure's result replaces something.
     */
    public function whereGroup(\Closure $group): static
    {
        $this->conditions[] = Condition::group(false, self::collect($group)->conditions());
        return $this;
    }

    /** The same group, joined to what came before with OR rather than AND. */
    public function orWhereGroup(\Closure $group): static
    {
        $this->conditions[] = Condition::group(true, self::collect($group)->conditions());
        return $this;
    }

    /**
     * Runs the closure against a fresh collector and hands it back.
     *
     * `ConditionGroup` refuses to be built with no conditions (via
     * {@see Condition::group()}), so an empty closure is a `bad_query` problem
     * rather than `()`, which is a syntax error on every dialect.
     *
     * @param \Closure(ConditionGroup): void $group
     */
    private static function collect(\Closure $group): ConditionGroup
    {
        $builder = new ConditionGroup();
        $group($builder);

        return $builder;
    }
}
