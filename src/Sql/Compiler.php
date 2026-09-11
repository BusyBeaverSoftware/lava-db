<?php

declare(strict_types=1);

namespace Lava\Db\Sql;

use Lava\Db\Query\Condition;
use Lava\Db\Query\DeleteQuery;
use Lava\Db\Query\InsertQuery;
use Lava\Db\Query\Operator;
use Lava\Db\Query\OrderBy;
use Lava\Db\Query\Query;
use Lava\Db\Query\SelectQuery;
use Lava\Db\Query\UpdateQuery;

/**
 * Query in, SQL out. No connection, no PDO, no I/O of any kind.
 *
 * That purity is the pack's load-bearing design decision, and it is a
 * response to a real constraint: a database layer whose SQL generation can
 * only be tested against a live server is a layer whose SQL generation is
 * barely tested. Here every statement shape, in all three dialects, is
 * asserted as an exact string with exact bindings — on a machine with no PDO
 * driver installed at all. What is left for live tests is the thin part: does
 * PDO execute what it was handed.
 *
 * Values never reach the SQL text. Each one becomes a `?` and a binding, so
 * the compiler has no escaping logic to get wrong — the safety property comes
 * from the shape of the output, not from care taken while building it.
 */
final class Compiler
{
    public function __construct(private readonly Dialect $dialect)
    {
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function compile(Query $query): Compiled
    {
        return match (true) {
            $query instanceof SelectQuery => $this->select($query),
            $query instanceof InsertQuery => $this->insert($query),
            $query instanceof UpdateQuery => $this->update($query),
            $query instanceof DeleteQuery => $this->delete($query),
            default => throw new \LogicException('No compiler for ' . $query::class . '.'),
        };
    }

    private function select(SelectQuery $query): Compiled
    {
        $bindings = [];
        $columns = array_map($this->dialect->quote(...), $query->columns);

        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $this->dialect->quote($query->table);

        foreach ($query->joins as $join) {
            $sql .= ' ' . $join->type->value
                . ' ' . $this->dialect->quote($join->table)
                . ' ON ' . $this->dialect->quote($join->first)
                . ' ' . $join->operator->value
                . ' ' . $this->dialect->quote($join->second);
        }

        $sql .= $this->where($query->conditions, $bindings);

        if ($query->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                fn (OrderBy $order): string => $this->dialect->quote($order->column)
                    . ' ' . $order->direction->value,
                $query->orders,
            ));
        }

        $sql .= $this->dialect->limitOffset($query->limit, $query->offset);

        return new Compiled($sql, $bindings);
    }

    private function insert(InsertQuery $query): Compiled
    {
        // InsertQuery::of() guarantees every row has these columns in this
        // order, which is why the rows can be flattened positionally below.
        $columns = array_keys($query->rows[0]);
        $bindings = [];
        $tuples = [];
        foreach ($query->rows as $row) {
            $tuples[] = '(' . $this->placeholders(count($columns)) . ')';
            foreach (array_values($row) as $value) {
                $bindings[] = $value;
            }
        }

        $sql = 'INSERT INTO ' . $this->dialect->quote($query->table)
            . ' (' . implode(', ', array_map($this->dialect->quote(...), $columns)) . ')'
            . ' VALUES ' . implode(', ', $tuples);

        return new Compiled($sql, $bindings);
    }

    private function update(UpdateQuery $query): Compiled
    {
        $bindings = [];
        $assignments = [];
        foreach ($query->values as $column => $value) {
            $assignments[] = $this->dialect->quote($column) . ' = ?';
            $bindings[] = $value;
        }

        $sql = 'UPDATE ' . $this->dialect->quote($query->table)
            . ' SET ' . implode(', ', $assignments)
            . $this->where($query->conditions, $bindings);

        return new Compiled($sql, $bindings);
    }

    private function delete(DeleteQuery $query): Compiled
    {
        $bindings = [];
        $sql = 'DELETE FROM ' . $this->dialect->quote($query->table)
            . $this->where($query->conditions, $bindings);

        return new Compiled($sql, $bindings);
    }

    /**
     * The WHERE clause including its leading space, or '' when there is none.
     *
     * @param list<Condition> $conditions
     * @param list<int|float|string|null> $bindings appended to, in placeholder order
     */
    private function where(array $conditions, array &$bindings): string
    {
        if ($conditions === []) {
            return '';
        }

        return ' WHERE ' . $this->terms($conditions, $bindings);
    }

    /**
     * Conditions joined left to right, WITH no surrounding parentheses — the
     * body of a WHERE clause, and equally the body of a group.
     *
     * Both callers go through here rather than each writing the prefix loop,
     * and that is the point: `AND`/`OR` placement, placeholder order and the
     * first-term-has-no-prefix rule then have exactly one copy, so a group
     * cannot quietly differ from a chain on any of them.
     *
     * @param list<Condition> $conditions
     * @param list<int|float|string|null> $bindings appended to, in placeholder order
     */
    private function terms(array $conditions, array &$bindings): string
    {
        $parts = [];
        foreach ($conditions as $index => $condition) {
            $prefix = $index === 0 ? '' : ($condition->or ? 'OR ' : 'AND ');
            $parts[] = $prefix . $this->condition($condition, $bindings);
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<int|float|string|null> $bindings appended to
     */
    private function condition(Condition $condition, array &$bindings): string
    {
        // A group renders itself from the conditions inside it, recursively and
        // through the same two methods — so a nested group is a group, and its
        // columns are still quoted and its bindings still ordered by this class
        // rather than by whoever built it. The check comes first because a
        // group's own operator is a placeholder ({@see Condition::isGroup()}).
        if ($condition->isGroup()) {
            return '(' . $this->terms($condition->group, $bindings) . ')';
        }

        if ($condition->operator === Operator::Raw) {
            foreach ($condition->bindings as $binding) {
                $bindings[] = $binding;
            }
            return $condition->expression;
        }

        $column = $this->dialect->quote($condition->expression);

        $sql = match ($condition->operator) {
            Operator::IsNull => $column . ' IS NULL',
            Operator::IsNotNull => $column . ' IS NOT NULL',
            Operator::In, Operator::NotIn => $column . ' ' . $condition->operator->value
                . ' (' . $this->placeholders(count($condition->bindings)) . ')',
            Operator::Between => $column . ' BETWEEN ? AND ?',
            default => $column . ' ' . $condition->operator->value . ' ?',
        };

        foreach ($condition->bindings as $binding) {
            $bindings[] = $binding;
        }

        return $sql;
    }

    private function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
