<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Live;

use Lava\Db\Problem\BadQuery;
use Lava\Db\Query\Direction;
use Lava\Db\Query\Operator;
use Lava\Db\Schema\Table;

/**
 * Aliases and `count()` against a real database (Lava Notes, R2-G8): that the
 * aliased columns come back under their aliases, and that a count is what
 * fetch() would return, over a join whose `*` names `id` twice.
 */
final class AliasAndCountLiveTest extends LiveDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->remember('writers');
        $this->remember('articles');

        $this->db->schema()->create('writers', function (Table $t): void {
            $t->id();
            $t->string('email', 120);
            $t->string('role', 20);
        });
        $this->db->schema()->create('articles', function (Table $t): void {
            $t->id();
            $t->string('title', 200);
            $t->foreignId('writer_id');
        });

        $this->db->run($this->db->table('writers')->insertMany([
            ['email' => 'ada@example.com', 'role' => 'admin'],
            ['email' => 'grace@example.com', 'role' => 'member'],
            ['email' => 'alan@example.com', 'role' => 'admin'],
        ]));
        $this->db->run($this->db->table('articles')->insertMany([
            ['title' => 'On Computable Numbers', 'writer_id' => 3],
            ['title' => 'Notes on the Analytical Engine', 'writer_id' => 1],
            ['title' => 'Compiling Routines', 'writer_id' => 2],
        ]));
    }

    public function testAliasesKeepBothColumnsAJoinWouldHaveCollapsedIntoOne(): void
    {
        $rows = $this->db->fetch(
            $this->db->table('articles')
                ->select(['article_id' => 'articles.id', 'writer_id' => 'writers.id'], 'writers.email')
                ->innerJoin('writers', 'writers.id', 'articles.writer_id')
                ->orderBy('articles.id'),
        );

        self::assertSame(
            [[1, 3, 'alan@example.com'], [2, 1, 'ada@example.com'], [3, 2, 'grace@example.com']],
            array_map(static fn (array $row): array => [(int) $row['article_id'], (int) $row['writer_id'], $row['email']], $rows),
        );
        self::assertSame(['article_id', 'writer_id', 'email'], array_keys($rows[0]));
    }

    public function testCountIsWhatFetchWouldReturn(): void
    {
        $byAdmins = fn () => $this->db->table('articles')
            ->innerJoin('writers', 'writers.id', 'articles.writer_id')
            ->where('writers.role', Operator::Eq, 'admin');

        self::assertCount(2, $this->db->fetch($byAdmins()));
        self::assertSame(2, $this->db->count($byAdmins()), 'A SELECT * over the join names id twice; the count does not care.');
        self::assertSame(1, $this->db->count($byAdmins()->orderBy('articles.id')->limit(1)));
        self::assertSame(1, $this->db->count($byAdmins()->limit(5)->offset(1)));
        self::assertSame(3, $this->db->count($this->db->table('articles')->toSelect()));
        self::assertSame(0, $this->db->count($this->db->table('articles')->where('title', Operator::Eq, 'Nothing')));
    }

    public function testAPageOrderedByAnAliasCountsWhatItFetches(): void
    {
        // Lava Notes R3-B7: `id` is a column of both joined tables. fetch()
        // orders by the alias; the counted subquery, with no alias in it, was
        // refused as ambiguous.
        $page = fn () => $this->db->table('articles')
            ->select(['id' => 'articles.id'], 'writers.email')
            ->innerJoin('writers', 'writers.id', 'articles.writer_id')
            ->orderBy('id', Direction::Desc)
            ->limit(2);

        self::assertCount(2, $this->db->fetch($page()));
        self::assertSame(2, $this->db->count($page()));
        self::assertSame(1, $this->db->count($page()->offset(2)));
    }

    public function testTwoColumnReferencesAlikeApartFromCaseLoseAColumnAndAreRefused(): void
    {
        // Lava Notes R3-B6. The raw query is the loss itself: SQLite returns a
        // reference under the name its TABLE declares, so two references that
        // differ only in case arrive as one column and a row keeps one value.
        $raw = $this->db->query(
            'SELECT "articles"."ID", "writers"."id" FROM "articles"'
            . ' INNER JOIN "writers" ON "writers"."id" = "articles"."writer_id"'
            . ' ORDER BY "articles"."id" LIMIT 1',
        );
        self::assertCount(1, $raw[0], 'Two references, one column back: this is what select() now refuses.');
        self::assertSame('id', strtolower((string) array_key_first($raw[0])));

        try {
            $this->db->table('articles')->select('articles.ID', 'writers.id');
            self::fail('Two column references alike apart from case should be refused.');
        } catch (BadQuery $problem) {
            self::assertSame('bad_query', $problem->code());
            self::assertStringContainsString("'ID' and 'id'", $problem->getMessage());
            self::assertStringContainsString("['writers_id' => 'writers.id']", $problem->fix);
        }

        // The same pair with an alias is two columns on this database, which is
        // why an alias is compared exactly rather than folded.
        $rows = $this->db->fetch(
            $this->db->table('articles')
                ->select(['ID' => 'articles.id'], 'writers.id')
                ->innerJoin('writers', 'writers.id', 'articles.writer_id')
                ->orderBy('articles.id')
                ->limit(1),
        );
        self::assertSame(['ID', 'id'], array_keys($rows[0]));
    }

    public function testCountingAWriteIsRefusedBeforeAnythingRuns(): void
    {
        try {
            $this->db->count($this->db->table('articles')->where('id', Operator::Eq, 1)->delete());
            self::fail('count() of a DELETE should be refused.');
        } catch (BadQuery $problem) {
            self::assertStringContainsString('->count() reads rows', $problem->getMessage());
        }

        self::assertSame(3, $this->db->count($this->db->table('articles')));
    }
}
