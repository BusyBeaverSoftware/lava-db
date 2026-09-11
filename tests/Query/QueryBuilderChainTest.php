<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Query;

use Lava\Db\Query\Direction;
use Lava\Db\Query\Operator;
use Lava\Db\Query\QueryBuilder;
use Lava\Db\Sql\Compiler;
use Lava\Db\Sql\Dialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The builder's fluent surface, compiled and asserted — the half an app
 * actually calls.
 *
 * `QueryBuilderTest` covers the refusals, which is the pack's most
 * agent-facing behaviour. This covers the other half: every method in the
 * chain produces the SQL the caller meant. They are separate classes because
 * they fail for different reasons — a refusal test fails when the builder
 * accepts something it should not, and a case here fails when it accepts
 * something and then says it wrong, which no refusal test can see.
 *
 * **Why this class exists at all.** A coverage run showed the builder at
 * 31/61 executable lines with the whole `or*` family, both joins, `whereIn`,
 * `whereNotIn`, `whereBetween` and the success path of `limit`/`offset`
 * unexecuted — because `CompilerTest` builds its `Condition`, `Join` and
 * `OrderBy` values directly and never goes through the builder. So the
 * compiler was thoroughly tested and the API that feeds it was not. That is a
 * gap a reader of the report could not tell from a well-tested file, which is
 * why it is worth a class of its own rather than another case in the compiler
 * table.
 *
 * **The fixtures are closures, not built queries.** Each provider entry is a
 * `Closure(QueryBuilder): void` applied to a fresh builder inside the test,
 * matching `QueryBuilderTest::refusals()`. That is not only for readability: a
 * query constructed in a data provider is executed during PHPUnit's test
 * ENUMERATION, before the coverage driver opens its first window, so those
 * lines read as uncovered no matter how thoroughly the case is asserted. The
 * closures keep the construction inside the measured window — see
 * DECISIONS.md, "the second instrument artifact".
 */
final class QueryBuilderChainTest extends TestCase
{
    /**
     * One entry per builder method that had no execution, plus the two that
     * compose them into something a real query looks like.
     *
     * @return array<string, array{\Closure(QueryBuilder): void, string, list<int|float|string|null>}>
     */
    public static function chains(): array
    {
        return [
            // ── the or* family ───────────────────────────────────────────────
            'orWhere' => [
                static function (QueryBuilder $q): void {
                    $q->where('id', Operator::Eq, 1)->orWhere('status', Operator::Eq, 'active');
                },
                'SELECT * FROM "users" WHERE "id" = ? OR "status" = ?',
                [1, 'active'],
            ],
            'orWhereNull' => [
                static function (QueryBuilder $q): void {
                    $q->where('id', Operator::Eq, 1)->orWhereNull('deleted_at');
                },
                'SELECT * FROM "users" WHERE "id" = ? OR "deleted_at" IS NULL',
                [1],
            ],
            'orWhereNotNull' => [
                static function (QueryBuilder $q): void {
                    $q->where('id', Operator::Eq, 1)->orWhereNotNull('email_verified_at');
                },
                'SELECT * FROM "users" WHERE "id" = ? OR "email_verified_at" IS NOT NULL',
                [1],
            ],
            'orWhereIn' => [
                static function (QueryBuilder $q): void {
                    $q->where('id', Operator::Eq, 1)->orWhereIn('role', ['admin', 'owner']);
                },
                'SELECT * FROM "users" WHERE "id" = ? OR "role" IN (?, ?)',
                [1, 'admin', 'owner'],
            ],
            'orWhereNotIn' => [
                static function (QueryBuilder $q): void {
                    $q->where('id', Operator::Eq, 1)->orWhereNotIn('role', ['guest']);
                },
                'SELECT * FROM "users" WHERE "id" = ? OR "role" NOT IN (?)',
                [1, 'guest'],
            ],
            'orWhereBetween' => [
                static function (QueryBuilder $q): void {
                    $q->where('id', Operator::Eq, 1)->orWhereBetween('age', 18, 30);
                },
                'SELECT * FROM "users" WHERE "id" = ? OR "age" BETWEEN ? AND ?',
                [1, 18, 30],
            ],
            'orWhereRaw' => [
                static function (QueryBuilder $q): void {
                    $q->where('id', Operator::Eq, 1)->orWhereRaw('LOWER("email") = ?', ['ada@example.com']);
                },
                'SELECT * FROM "users" WHERE "id" = ? OR LOWER("email") = ?',
                [1, 'ada@example.com'],
            ],

            // ── the null and set tests, on the AND side ──────────────────────
            'whereNull' => [
                static fn (QueryBuilder $q) => $q->whereNull('deleted_at'),
                'SELECT * FROM "users" WHERE "deleted_at" IS NULL',
                [],
            ],
            'whereNotNull' => [
                static fn (QueryBuilder $q) => $q->whereNotNull('email_verified_at'),
                'SELECT * FROM "users" WHERE "email_verified_at" IS NOT NULL',
                [],
            ],
            'whereIn' => [
                static fn (QueryBuilder $q) => $q->whereIn('id', [1, 2]),
                'SELECT * FROM "users" WHERE "id" IN (?, ?)',
                [1, 2],
            ],
            'whereNotIn' => [
                static fn (QueryBuilder $q) => $q->whereNotIn('id', [1, 2]),
                'SELECT * FROM "users" WHERE "id" NOT IN (?, ?)',
                [1, 2],
            ],
            'whereBetween' => [
                static fn (QueryBuilder $q) => $q->whereBetween('age', 18, 30),
                'SELECT * FROM "users" WHERE "age" BETWEEN ? AND ?',
                [18, 30],
            ],

            // ── joins ────────────────────────────────────────────────────────
            'innerJoin' => [
                static fn (QueryBuilder $q) => $q->innerJoin('posts', 'users.id', 'posts.user_id'),
                'SELECT * FROM "users" INNER JOIN "posts" ON "users"."id" = "posts"."user_id"',
                [],
            ],
            'leftJoin' => [
                static fn (QueryBuilder $q) => $q->leftJoin('posts', 'users.id', 'posts.user_id'),
                'SELECT * FROM "users" LEFT JOIN "posts" ON "users"."id" = "posts"."user_id"',
                [],
            ],

            // ── the success path of the two that were only ever refused ──────
            'limit' => [
                static fn (QueryBuilder $q) => $q->limit(10),
                'SELECT * FROM "users" LIMIT 10',
                [],
            ],
            'offset without a limit' => [
                static fn (QueryBuilder $q) => $q->offset(20),
                'SELECT * FROM "users" LIMIT -1 OFFSET 20',
                [],
            ],
            'limit and offset together' => [
                static fn (QueryBuilder $q) => $q->limit(10)->offset(20),
                'SELECT * FROM "users" LIMIT 10 OFFSET 20',
                [],
            ],

            // ── the value conversions, reached through the builder ───────────
            // `CompilerTest` proves `Bindings` converts these; these prove the
            // BUILDER path reaches the conversion, which is the difference
            // between a tested helper and a tested feature.
            'a backed enum binds its value' => [
                static fn (QueryBuilder $q) => $q->where('status', Operator::Eq, Status::Active),
                'SELECT * FROM "users" WHERE "status" = ?',
                ['active'],
            ],
            'a DateTime is written in the documented format' => [
                static fn (QueryBuilder $q) => $q->where('at', Operator::Gte, new \DateTimeImmutable('2026-09-11 08:30:00')),
                'SELECT * FROM "users" WHERE "at" >= ?',
                ['2026-09-11 08:30:00'],
            ],
            'a bool becomes an int, never a PDO bool' => [
                static fn (QueryBuilder $q) => $q->where('published', Operator::Eq, false),
                'SELECT * FROM "users" WHERE "published" = ?',
                [0],
            ],

            // ── the whole chain, in the order an app writes it ───────────────
            'a query that uses most of the surface at once' => [
                static function (QueryBuilder $q): void {
                    $q->select('users.id', 'posts.title')
                        ->innerJoin('posts', 'users.id', 'posts.user_id')
                        ->where('users.role', Operator::Eq, 'admin')
                        ->orWhereIn('users.plan', ['pro', 'team'])
                        ->whereNotNull('users.email_verified_at')
                        ->orderBy('posts.title', Direction::Desc)
                        ->limit(25)
                        ->offset(50);
                },
                'SELECT "users"."id", "posts"."title" FROM "users"'
                    . ' INNER JOIN "posts" ON "users"."id" = "posts"."user_id"'
                    . ' WHERE "users"."role" = ?'
                    . ' OR "users"."plan" IN (?, ?)'
                    . ' AND "users"."email_verified_at" IS NOT NULL'
                    . ' ORDER BY "posts"."title" DESC'
                    . ' LIMIT 25 OFFSET 50',
                ['admin', 'pro', 'team'],
            ],
        ];
    }

    /**
     * @param \Closure(QueryBuilder): void $chain
     * @param list<int|float|string|null> $bindings
     */
    #[DataProvider('chains')]
    public function testTheChainCompilesToTheSqlTheCallerMeant(\Closure $chain, string $sql, array $bindings): void
    {
        $builder = new QueryBuilder('users');
        $chain($builder);

        $compiled = (new Compiler(Dialect::Sqlite))->compile($builder->toSelect());

        self::assertSame($sql, $compiled->sql);
        self::assertSame($bindings, $compiled->bindings);
    }

    /**
     * `table()` is the one method with no SQL of its own — it reads back what
     * the builder was constructed with, which is what a diagnostic or an
     * extension needs. Asserted separately because there is nothing to compile.
     */
    public function testTableReadsBackWhatTheBuilderWasBuiltOn(): void
    {
        self::assertSame('users', (new QueryBuilder('users'))->table());
    }

    /**
     * The `or*` methods are not `where` with a flag: the FIRST condition in a
     * chain never gets a prefix, so an `orWhere` used alone compiles to a plain
     * `WHERE` and not to `WHERE OR …`. That is the compiler's rule (index 0 has
     * no prefix), and it is worth pinning because a caller who writes a single
     * `orWhere` is relying on it.
     */
    public function testALeadingOrWhereStillCompilesToAPlainWhere(): void
    {
        $builder = new QueryBuilder('users');
        $builder->orWhere('status', Operator::Eq, 'active');

        $compiled = (new Compiler(Dialect::Sqlite))->compile($builder->toSelect());

        self::assertSame('SELECT * FROM "users" WHERE "status" = ?', $compiled->sql);
    }

    /**
     * A builder is a description of a query, not a query: compiling one does
     * not consume it, and the same builder can be compiled twice or frozen
     * again after a terminal has run. The mutability is deliberate (see the
     * class docblock on `QueryBuilder`), and this is the half of it that is a
     * guarantee rather than a hazard.
     */
    public function testCompilingDoesNotConsumeTheBuilder(): void
    {
        $builder = new QueryBuilder('users');
        $builder->where('id', Operator::Eq, 1);

        $compiler = new Compiler(Dialect::Sqlite);
        $first = $compiler->compile($builder->toSelect());
        $second = $compiler->compile($builder->toSelect());

        self::assertSame($first->sql, $second->sql);
        self::assertSame($first->bindings, $second->bindings);

        // A terminal does not reset the chain, so the conditions come along —
        // and the bindings arrive in the order the SQL names them, SET before
        // WHERE. Asserting the order is the point: a placeholder list is
        // positional, so `['ada', 1]` and `[1, 'ada']` are different queries,
        // and only one of them is the one this builder described.
        self::assertSame(['ada', 1], $compiler->compile($builder->update(['name' => 'ada']))->bindings);
    }
}

/** A backed enum for the conversion case, declared here so it needs no app. */
enum Status: string
{
    case Active = 'active';
    case Retired = 'retired';
}
