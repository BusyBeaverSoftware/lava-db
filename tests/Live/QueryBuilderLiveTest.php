<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Live;

use Lava\Db\Query\Direction;
use Lava\Db\Query\Operator;
use Lava\Db\Schema\Table;

/**
 * The builder's chain, RUN against a real database rather than compiled and
 * compared as text.
 *
 * `QueryBuilderChainTest` asserts the SQL a chain produces, which is the
 * contract and the half that can be checked without a driver. This asserts the
 * other half: that the statement is one the database accepts and that the rows
 * that come back are the rows the chain described. The two are not the same
 * claim, and the pack's own tests already make the distinction — a
 * `CREATE TABLE` string can be exactly what the compiler meant to write and
 * still be rejected.
 *
 * Four of the chains below are here for a specific reason rather than for
 * symmetry:
 *
 *  - `LIMIT -1 OFFSET n`, which is how SQLite spells "skip n, no cap" and
 *    which is a syntax error in MySQL. Compiled text cannot tell you it runs.
 *  - `LEFT JOIN` against a row with no match, because the NULLs on the
 *    unmatched side are the entire reason to write `leftJoin` instead of
 *    `innerJoin`, and a compile test would have accepted a builder that
 *    dropped the join entirely.
 *  - `NOT IN`, because a `NOT IN` with a NULL in the list matches NOTHING —
 *    a real trap, and one a compile assertion cannot see.
 *  - the `OR`/`AND` precedence case, which is the one place these tests
 *    document behaviour a caller is likely to be surprised by. See
 *    {@see self::testOrBindsLooserThanAndWhichIsNotWhatAChainLooksLike()}.
 */
final class QueryBuilderLiveTest extends LiveDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->remember('users');
        $this->remember('posts');

        $this->db->schema()->create('users', function (Table $t): void {
            $t->id();
            $t->string('email', 120);
            $t->string('role', 20);
            $t->string('plan', 20);
            $t->int('age');
            $t->dateTime('email_verified_at')->nullable();
        });

        $this->db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->string('title', 200);
            $t->foreignId('user_id');
        });

        $this->db->run($this->db->table('users')->insertMany([
            ['email' => 'ada@example.com', 'role' => 'admin', 'plan' => 'pro', 'age' => 36, 'email_verified_at' => '2026-01-01 00:00:00'],
            ['email' => 'grace@example.com', 'role' => 'admin', 'plan' => 'team', 'age' => 45, 'email_verified_at' => null],
            ['email' => 'alan@example.com', 'role' => 'member', 'plan' => 'pro', 'age' => 41, 'email_verified_at' => '2026-02-01 00:00:00'],
            ['email' => 'edsger@example.com', 'role' => 'member', 'plan' => 'free', 'age' => 72, 'email_verified_at' => null],
        ]));

        $this->db->run($this->db->table('posts')->insertMany([
            ['title' => 'On Computable Numbers', 'user_id' => 1],
            ['title' => 'The Mind and the Machine', 'user_id' => 1],
            ['title' => 'The Chemical Basis of Morphogenesis', 'user_id' => 3],
        ]));
    }

    /** @return list<string> */
    private function emails(string $sql = ''): array
    {
        $query = $this->db->table('users')->select('email');

        if ($sql !== '') {
            $query = $query->whereRaw($sql);
        }

        return array_column($this->db->fetch($query->orderBy('email')), 'email');
    }

    public function testWhereInAndWhereNotInSelectTheSetsTheyName(): void
    {
        self::assertSame(
            ['ada@example.com', 'grace@example.com'],
            $this->emails('"role" = \'admin\''),
        );

        self::assertSame(
            ['ada@example.com', 'grace@example.com'],
            array_column($this->db->fetch(
                $this->db->table('users')->select('email')->whereIn('role', ['admin'])->orderBy('email'),
            ), 'email'),
        );

        self::assertSame(
            ['alan@example.com', 'edsger@example.com'],
            array_column($this->db->fetch(
                $this->db->table('users')->select('email')->whereNotIn('role', ['admin'])->orderBy('email'),
            ), 'email'),
        );
    }

    /**
     * The `NOT IN` trap, pinned as behaviour rather than left as folklore: SQL's
     * three-valued logic makes `x NOT IN (1, NULL)` true for NO row, because
     * `x <> NULL` is unknown rather than true. The builder does not and cannot
     * fix that — the list is data, and a NULL in it is a caller's mistake — so
     * the honest thing is to assert what actually happens, which is the answer
     * a caller debugging an empty result set needs.
     */
    public function testANotInContainingNullMatchesNothing(): void
    {
        $rows = $this->db->fetch(
            $this->db->table('users')->select('email')->whereNotIn('role', ['admin', null]),
        );

        self::assertSame([], $rows);
    }

    public function testWhereBetweenAndTheNullTestsSelectWhatTheyName(): void
    {
        self::assertSame(
            ['ada@example.com', 'alan@example.com', 'grace@example.com'],
            array_column($this->db->fetch(
                $this->db->table('users')->select('email')->whereBetween('age', 36, 45)->orderBy('email'),
            ), 'email'),
        );

        self::assertSame(
            ['ada@example.com', 'alan@example.com'],
            array_column($this->db->fetch(
                $this->db->table('users')->select('email')->whereNotNull('email_verified_at')->orderBy('email'),
            ), 'email'),
        );

        self::assertSame(
            ['edsger@example.com', 'grace@example.com'],
            array_column($this->db->fetch(
                $this->db->table('users')->select('email')->whereNull('email_verified_at')->orderBy('email'),
            ), 'email'),
        );
    }

    public function testTheOrFamilyWidensTheResultSet(): void
    {
        // age >= 70 OR role = 'admin' — both sides are AND-ed with nothing, so
        // there is no precedence question here and the union is the answer.
        $rows = $this->db->fetch(
            $this->db->table('users')
                ->select('email')
                ->whereBetween('age', 70, 99)
                ->orWhere('role', Operator::Eq, 'admin')
                ->orderBy('email'),
        );

        self::assertSame(
            ['ada@example.com', 'edsger@example.com', 'grace@example.com'],
            array_column($rows, 'email'),
        );
    }

    /**
     * `INNER JOIN` keeps only the pairs that exist; `LEFT JOIN` keeps every row
     * on the left, filling the right with NULLs. Both are asserted against the
     * same data, because the difference between them is the only thing that
     * makes the second one worth having.
     */
    public function testInnerJoinAndLeftJoinDifferExactlyOnTheUnmatchedRow(): void
    {
        $inner = $this->db->fetch(
            $this->db->table('users')
                ->select('users.email', 'posts.title')
                ->innerJoin('posts', 'users.id', 'posts.user_id')
                ->orderBy('posts.title'),
        );

        self::assertSame(
            [
                ['ada@example.com', 'On Computable Numbers'],
                ['alan@example.com', 'The Chemical Basis of Morphogenesis'],
                ['ada@example.com', 'The Mind and the Machine'],
            ],
            array_map(static fn (array $row): array => array_values($row), $inner),
        );

        // Grace and Edsger have no posts, so an inner join loses them entirely.
        self::assertCount(3, $inner);

        $left = $this->db->fetch(
            $this->db->table('users')
                ->select('users.email')
                ->leftJoin('posts', 'users.id', 'posts.user_id')
                ->whereNull('posts.id')
                ->orderBy('users.email'),
        );

        self::assertSame(
            [['email' => 'edsger@example.com'], ['email' => 'grace@example.com']],
            $left,
            'the left join should be the only way to find the users with no posts',
        );
    }

    /**
     * `LIMIT -1 OFFSET n` is the SQLite spelling of "skip n, no cap". It is a
     * syntax error in MySQL, which is why the dialect decides it — and this is
     * the only test that can tell you the SQLite spelling is accepted.
     */
    public function testOffsetWithoutALimitIsAcceptedByTheDatabase(): void
    {
        $rows = $this->db->fetch(
            $this->db->table('users')->select('email')->orderBy('email')->offset(2),
        );

        self::assertSame(
            [['email' => 'edsger@example.com'], ['email' => 'grace@example.com']],
            $rows,
        );

        $limited = $this->db->fetch(
            $this->db->table('users')->select('email')->orderBy('email')->limit(1)->offset(1),
        );

        self::assertSame([['email' => 'alan@example.com']], $limited);
    }

    /**
     * The surprise, asserted so it is documented rather than discovered.
     *
     * `AND` binds tighter than `OR` in SQL, so this chain:
     *
     *     where('role', 'admin')->orWhereIn('plan', ['pro'])->whereNotNull('email_verified_at')
     *
     * compiles to `role = 'admin' OR (plan IN ('pro') AND email_verified_at IS NOT NULL)`
     * — NOT to `(role = 'admin' OR plan IN ('pro')) AND email_verified_at IS NOT NULL`.
     * A caller reading the chain as a sentence almost always means the second,
     * and gets the first.
     *
     * The builder has no grouping, so the second is not expressible. That is
     * recorded as an open finding in DECISIONS.md rather than changed here:
     * adding a grouping API is a product decision, and this test's job is to
     * make the current behaviour visible.
     */
    public function testOrBindsLooserThanAndWhichIsNotWhatAChainLooksLike(): void
    {
        $rows = $this->db->fetch(
            $this->db->table('users')
                ->select('email')
                ->where('role', Operator::Eq, 'admin')
                ->orWhereIn('plan', ['pro'])
                ->whereNotNull('email_verified_at')
                ->orderBy('email'),
        );

        // Ada: role = admin (true) → in, verified or not.
        // Grace: role = admin (true) → in, even though NOT verified.
        // Alan: role = member, plan = pro AND verified → in.
        // Edsger: role = member, plan = free → out.
        self::assertSame(
            ['ada@example.com', 'alan@example.com', 'grace@example.com'],
            array_column($rows, 'email'),
        );

        // The reading a caller probably intended — (admin OR pro) AND verified —
        // is a strictly smaller set, and this is what it would look like. It is
        // spelled with `whereRaw` because the chain cannot say it.
        $intended = $this->db->fetch(
            $this->db->table('users')
                ->select('email')
                ->whereRaw('("role" = ? OR "plan" IN (?, ?))', ['admin', 'pro', 'team'])
                ->whereNotNull('email_verified_at')
                ->orderBy('email'),
        );

        self::assertSame(['ada@example.com', 'alan@example.com'], array_column($intended, 'email'));
        self::assertNotSame(
            array_column($rows, 'email'),
            array_column($intended, 'email'),
            'the chain and the sentence it looks like select different rows',
        );
    }

    public function testOrWhereRawBindsItsOwnValuesInOrder(): void
    {
        $rows = $this->db->fetch(
            $this->db->table('users')
                ->select('email')
                ->where('role', Operator::Eq, 'member')
                ->orWhereRaw('LOWER("email") = ?', ['ada@example.com'])
                ->orderBy('email'),
        );

        self::assertSame(
            ['ada@example.com', 'alan@example.com', 'edsger@example.com'],
            array_column($rows, 'email'),
        );
    }

    public function testADeepChainRunsAndReturnsWhatItDescribes(): void
    {
        $rows = $this->db->fetch(
            $this->db->table('users')
                ->select('users.email', 'posts.title')
                ->innerJoin('posts', 'users.id', 'posts.user_id')
                ->where('users.role', Operator::Eq, 'admin')
                ->whereBetween('users.age', 30, 40)
                ->orderBy('posts.title', Direction::Desc)
                ->limit(1),
        );

        self::assertSame(
            [['email' => 'ada@example.com', 'title' => 'The Mind and the Machine']],
            $rows,
        );
    }
}
