<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * The fluent half of the builder, and the only mutable thing in the pack.
 *
 * It is mutable because that is what a fluent chain is — but nothing else
 * depends on that: every terminal method freezes the accumulated state into
 * an immutable {@see Query}, and the compiler only ever sees those. So the
 * "pure SQL layer" claim survives contact with a convenient API, and a query
 * can be asserted on as a value in a test.
 *
 * Operators are enum cases and directions are enum cases. That is not
 * ceremony: `->orderBy('created_at', Direction::Desc)` cannot be mistyped
 * into something a database accepts but means differently, which is the
 * failure mode of a string-typed builder.
 */
final class QueryBuilder implements Statement
{
    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<Condition> */
    private array $conditions = [];

    /** @var list<Join> */
    private array $joins = [];

    /** @var list<OrderBy> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(private readonly string $table)
    {
    }

    public function table(): string
    {
        return $this->table;
    }

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

    /** Replaces the column list. Called with no arguments, it selects `*` again. */
    public function select(string ...$columns): self
    {
        $this->columns = $columns === [] ? ['*'] : array_values($columns);
        return $this;
    }

    public function where(string $column, Operator $operator, mixed $value): self
    {
        $this->conditions[] = Condition::compare(false, $column, $operator, $value);
        return $this;
    }

    public function orWhere(string $column, Operator $operator, mixed $value): self
    {
        $this->conditions[] = Condition::compare(true, $column, $operator, $value);
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->conditions[] = Condition::null(false, $column);
        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->conditions[] = Condition::null(true, $column);
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->conditions[] = Condition::null(false, $column, not: true);
        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        $this->conditions[] = Condition::null(true, $column, not: true);
        return $this;
    }

    /** @param array<mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        $this->conditions[] = Condition::in(false, $column, $values);
        return $this;
    }

    /** @param array<mixed> $values */
    public function orWhereIn(string $column, array $values): self
    {
        $this->conditions[] = Condition::in(true, $column, $values);
        return $this;
    }

    /** @param array<mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        $this->conditions[] = Condition::in(false, $column, $values, not: true);
        return $this;
    }

    /** @param array<mixed> $values */
    public function orWhereNotIn(string $column, array $values): self
    {
        $this->conditions[] = Condition::in(true, $column, $values, not: true);
        return $this;
    }

    public function whereBetween(string $column, mixed $low, mixed $high): self
    {
        $this->conditions[] = Condition::between(false, $column, $low, $high);
        return $this;
    }

    public function orWhereBetween(string $column, mixed $low, mixed $high): self
    {
        $this->conditions[] = Condition::between(true, $column, $low, $high);
        return $this;
    }

    /** @param array<mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->conditions[] = Condition::raw(false, $sql, $bindings);
        return $this;
    }

    /** @param array<mixed> $bindings */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $this->conditions[] = Condition::raw(true, $sql, $bindings);
        return $this;
    }

    public function innerJoin(string $table, string $first, string $second): self
    {
        $this->joins[] = new Join(JoinType::Inner, $table, $first, Operator::Eq, $second);
        return $this;
    }

    public function leftJoin(string $table, string $first, string $second): self
    {
        $this->joins[] = new Join(JoinType::Left, $table, $first, Operator::Eq, $second);
        return $this;
    }

    public function orderBy(string $column, Direction $direction = Direction::Asc): self
    {
        $this->orders[] = new OrderBy($column, $direction);
        return $this;
    }

    /** @throws BadQuery when negative — see {@see \Lava\Db\Sql\Dialect::limitOffset()} for why it is not portable */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw BadQuery::negativeLimit('limit', $limit);
        }
        $this->limit = $limit;
        return $this;
    }

    /** @throws BadQuery when negative */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw BadQuery::negativeLimit('offset', $offset);
        }
        $this->offset = $offset;
        return $this;
    }

    /** The read query this builder describes — what {@see Statement::query()} returns. */
    public function toSelect(): SelectQuery
    {
        return new SelectQuery(
            $this->table,
            $this->columns,
            $this->conditions,
            $this->joins,
            $this->orders,
            $this->limit,
            $this->offset,
        );
    }

    /**
     * The write queries. Each is a terminal: it freezes the builder's
     * conditions into the statement and hands back something the connection
     * can run. The builder is not reset — reuse it and the conditions come
     * along, which is the honest behaviour of a mutable chain.
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): InsertQuery
    {
        return InsertQuery::of($this->table, [$row]);
    }

    /** @param list<array<string, mixed>> $rows */
    public function insertMany(array $rows): InsertQuery
    {
        return InsertQuery::of($this->table, $rows);
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): UpdateQuery
    {
        return UpdateQuery::of($this->table, $values, $this->conditions);
    }

    public function delete(): DeleteQuery
    {
        return DeleteQuery::of($this->table, $this->conditions);
    }

    public function query(): Query
    {
        return $this->toSelect();
    }
}
