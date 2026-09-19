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
 *
 * The `where*` family — including `whereGroup()`, which is how a parenthesised
 * `(A OR B) AND C` is written — lives in {@see HasConditions} and is shared with
 * {@see ConditionGroup}, the only thing a group closure is handed.
 */
final class QueryBuilder implements Statement
{
    use HasConditions;

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var array<int, string> a position in $columns => the alias that column is selected as */
    private array $aliases = [];

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
     * Replaces the column list. Called with no arguments, it selects `*` again.
     *
     * Column names — `title`, `posts.title`, `*`, `posts.*` — and maps from an
     * alias to a column name, `['author' => 'users.name']`, which compile to
     * `"users"."name" AS "author"`, in the order given. An aggregate or an
     * expression is refused rather than quoted: `COUNT(*)` would become
     * `"COUNT(*)"`, and SQLite answers that with the string itself. Count rows
     * with {@see \Lava\Db\Connection::count()}.
     *
     * Two columns that would come back under one name are refused as well. PDO
     * keeps the last of two same-named columns, so `select('posts.id',
     * 'users.id')` returned one `id`, the user's, and said nothing; alias one of
     * them. Two column references alike apart from case are refused too, because
     * SQLite returns a reference under the name its TABLE declares: both sides of
     * `select('posts.ID', 'users.id')` came back as `id`. An alias comes back as
     * written on every engine, so it is compared exactly — `['ID' => 'posts.id']`
     * beside `users.id` is two columns and is allowed. A `*` is not counted:
     * which names it brings is the database's to say.
     *
     * @param string|array<mixed> ...$columns column names, and alias => column maps
     * @throws BadQuery for a column that is not a name, an alias that is not one
     *         name, or a name two columns would share — see {@see ColumnName}
     */
    public function select(string|array ...$columns): self
    {
        $list = [];
        $aliases = [];
        $names = [];
        $arguments = array_values($columns);
        foreach ($arguments as $argument => $entry) {
            foreach (is_string($entry) ? [[null, $entry]] : self::aliased($entry) as [$alias, $column]) {
                ColumnName::check($column, 'select()', star: $alias === null);

                $dot = strrpos($column, '.');
                $name = $alias ?? (str_ends_with($column, '*') ? null : ($dot === false ? $column : substr($column, $dot + 1)));
                if ($name !== null) {
                    $side = ['column' => $column, 'alias' => $alias, 'argument' => $argument, 'name' => $name];

                    // Grouped by the FOLDED name, because two names alike apart
                    // from case can still arrive as one (Lava Notes, R3-B6):
                    // SQLite returns a column reference under the name its table
                    // declares, not the name the query wrote, so `posts.ID` and
                    // `users.id` both come back as `id` and a row keeps one.
                    //
                    // Which is why the fold alone is not the refusal. An alias
                    // is quoted and comes back exactly as written on every
                    // engine, so `['ID' => 'posts.id']` beside `users.id` gives
                    // `ID` and `id` — two keys, nothing lost — and refusing it
                    // would refuse a call that works. Two spellings are one
                    // name only when the database gets to choose both, so a
                    // folded match is refused between two UNALIASED references,
                    // and an exact match is refused however it was written.
                    foreach ($names[strtolower($name)] ?? [] as $earlier) {
                        if ($earlier['name'] === $name || ($earlier['alias'] === null && $alias === null)) {
                            throw BadQuery::sameResultName($earlier, $side, $arguments, self::resultNames($arguments));
                        }
                    }
                    $names[strtolower($name)][] = $side;
                }

                if ($alias !== null) {
                    $aliases[count($list)] = $alias;
                }
                $list[] = $column;
            }
        }

        $this->columns = $list === [] ? ['*'] : $list;
        $this->aliases = $aliases;
        return $this;
    }

    /**
     * Every name select()'s arguments come back under, as far as each is a name
     * at all, so the alias a same-name refusal suggests can avoid every one of
     * them, the arguments after the clash included. It never throws: an
     * argument select() has not reached yet may still be refused on its own.
     *
     * @param list<string|array<mixed>> $arguments
     * @return list<string>
     */
    private static function resultNames(array $arguments): array
    {
        $names = [];
        foreach ($arguments as $entry) {
            foreach (is_string($entry) ? [$entry] : $entry as $alias => $column) {
                if (is_string($alias)) {
                    $names[] = $alias;
                } elseif (is_string($column) && !str_ends_with($column, '*')) {
                    $dot = strrpos($column, '.');
                    $names[] = $dot === false ? $column : substr($column, $dot + 1);
                }
            }
        }

        return $names;
    }

    /**
     * @param array<mixed> $map alias => column
     * @return list<array{0: string, 1: string}> [alias, column] pairs
     * @throws BadQuery for a key that is not an alias or a value that is not a string
     */
    private static function aliased(array $map): array
    {
        $pairs = [];
        foreach ($map as $alias => $column) {
            if (!is_string($alias)) {
                throw BadQuery::notAnAlias($alias);
            }
            if (!is_string($column)) {
                throw BadQuery::notAColumn(get_debug_type($column), 'select()');
            }
            $pairs[] = [ColumnName::alias($alias), $column];
        }

        return $pairs;
    }

    /** @throws BadQuery when the table or either column is not a name — see {@see ColumnName} */
    public function innerJoin(string $table, string $first, string $second): self
    {
        $this->joins[] = self::join(JoinType::Inner, $table, $first, $second, 'innerJoin()');
        return $this;
    }

    /** @throws BadQuery when the table or either column is not a name — see {@see ColumnName} */
    public function leftJoin(string $table, string $first, string $second): self
    {
        $this->joins[] = self::join(JoinType::Left, $table, $first, $second, 'leftJoin()');
        return $this;
    }

    /** @throws BadQuery when the column is not a name — see {@see ColumnName} */
    public function orderBy(string $column, Direction $direction = Direction::Asc): self
    {
        $this->orders[] = new OrderBy(ColumnName::check($column, 'orderBy()'), $direction);
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
            $this->aliases,
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

    private static function join(JoinType $type, string $table, string $first, string $second, string $call): Join
    {
        return new Join(
            $type,
            ColumnName::check($table, $call),
            ColumnName::check($first, $call),
            Operator::Eq,
            ColumnName::check($second, $call),
        );
    }
}
