<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Sql;

use Lava\Db\Problem\BadQuery;
use Lava\Db\Query\Direction;
use Lava\Db\Query\Operator;
use Lava\Db\Query\QueryBuilder;
use Lava\Db\Sql\Compiler;
use Lava\Db\Sql\Dialect;
use PHPUnit\Framework\TestCase;

/**
 * Aliases in `select()`, the refusal of two columns under one name, and
 * `COUNT(*)` of a SELECT (Lava Notes, R2-G8), held to exact strings.
 */
final class SelectAliasAndCountTest extends TestCase
{
    public function testAnAliasMapCompilesToAsInTheOrderColumnsWereGiven(): void
    {
        $query = (new QueryBuilder('posts'))
            ->select('posts.id', ['author' => 'users.name', 'author_id' => 'users.id'], 'posts.title')
            ->innerJoin('users', 'users.id', 'posts.user_id')
            ->toSelect();

        self::assertSame(['posts.id', 'users.name', 'users.id', 'posts.title'], $query->columns);
        self::assertSame([1 => 'author', 2 => 'author_id'], $query->aliases);
        self::assertStringStartsWith(
            'SELECT "posts"."id", "users"."name" AS "author", "users"."id" AS "author_id", "posts"."title" FROM "posts" ',
            (new Compiler(Dialect::Sqlite))->compile($query)->sql,
        );
        self::assertStringStartsWith(
            'SELECT `posts`.`id`, `users`.`name` AS `author`, `users`.`id` AS `author_id`, `posts`.`title` FROM `posts` ',
            (new Compiler(Dialect::Mysql))->compile($query)->sql,
        );
        self::assertSame([], (new QueryBuilder('posts'))->select('id')->toSelect()->aliases);
    }

    public function testColumnsThatWouldComeBackUnderOneNameAreRefused(): void
    {
        $cases = [
            'two ids over a join' => [['posts.id', 'users.id'], "two columns named 'id', 'posts.id' and 'users.id'", "['users_id' => 'users.id']"],
            'an alias that takes a column name' => [['title', ['title' => 'users.name']], "two columns named 'title'", "'users_name'"],
            'two aliases alike' => [[['n' => 'posts.id'], ['n' => 'users.id']], "two columns named 'n'", "'users_id'"],
        ];

        foreach ($cases as $case => [$columns, $message, $fix]) {
            try {
                (new QueryBuilder('posts'))->select(...$columns);
                self::fail("Accepted {$case}.");
            } catch (BadQuery $problem) {
                self::assertStringContainsString($message, $problem->getMessage(), $case);
                self::assertStringContainsString($fix, $problem->fix, $case);
            }
        }

        self::assertSame(['posts.*', 'users.id'], (new QueryBuilder('posts'))->select('posts.*', 'users.id')->toSelect()->columns, 'A * is not counted.');
    }

    public function testTwoColumnReferencesAlikeApartFromCaseAreOneNameAndAnAliasIsNot(): void
    {
        // Lava Notes R3-B6: names were compared case-sensitively, so this passed
        // and SQLite — which returns a reference under the name its table
        // declares, not the name the query wrote — returned one `id`.
        try {
            (new QueryBuilder('posts'))->select('posts.ID', 'users.id');
            self::fail('Accepted two column references alike apart from case.');
        } catch (BadQuery $problem) {
            self::assertSame('bad_query', $problem->code());
            self::assertSame(
                "select() would return two columns named 'ID' and 'id', 'posts.ID' and 'users.id',"
                . ' which a database that folds case returns as one name, keeping only one of them.',
                $problem->getMessage(),
                'Both spellings are named: neither is a name the reader wrote twice.',
            );
            self::assertSame("Alias one of them: select('posts.ID', ['users_id' => 'users.id']).", $problem->fix);
            self::assertSame(['ID', 'id'], $problem->context['names']);
        }

        // Accepted, and each for the reason the refusal does not apply: an alias
        // is quoted and comes back exactly as written on every engine, so only a
        // clash the DATABASE creates is refused. Verified against SQLite in
        // Live\AliasAndCountLiveTest.
        $allowed = [
            'two references that do not fold alike' => ['posts.ID', 'users.NAME'],
            'an alias against a reference' => [['ID' => 'posts.id'], 'users.id'],
            'a reference against an alias' => [['Title' => 'posts.body'], 'posts.title'],
            'two aliases alike apart from case' => [['n' => 'posts.id'], ['N' => 'users.id']],
        ];

        foreach ($allowed as $case => $columns) {
            self::assertCount(2, (new QueryBuilder('posts'))->select(...$columns)->toSelect()->columns, $case);
        }
    }

    public function testTheFixIsTheReadersOwnCallWithOneAliasNothingElseUses(): void
    {
        // Lava Notes R3-B6: the fix printed two columns and an alias built from
        // the second, so it dropped the reader's other arguments and could
        // suggest a name the call already used; following it was refused again.
        $cases = [
            'the alias is taken by an earlier alias' => [
                ['users.id', ['posts_id' => 'posts.user_id'], 'posts.id'],
                "Alias one of them: select('users.id', ['posts_id' => 'posts.user_id'], ['posts_id_2' => 'posts.id']).",
            ],
            'the fix it used to give, followed' => [
                ['users.id', ['posts_id' => 'posts.user_id'], ['posts_id' => 'posts.id']],
                "Give one of them another alias: select('users.id', ['posts_id' => 'posts.user_id'], ['posts_id_2' => 'posts.id']).",
            ],
            'both sides aliased' => [
                [['title' => 'posts.title'], ['title' => 'users.name']],
                "Give one of them another alias: select(['title' => 'posts.title'], ['users_name' => 'users.name']).",
            ],
            'the alias is taken by a plain column' => [
                ['users_id', 'posts.id', 'users.id'],
                "Alias one of them: select('users_id', 'posts.id', ['users_id_2' => 'users.id']).",
            ],
            'the alias is taken by a later argument' => [
                ['posts.id', 'users.id', ['users_id' => 'comments.user_id']],
                "Alias one of them: select('posts.id', ['users_id_2' => 'users.id'], ['users_id' => 'comments.user_id']).",
            ],
            'an unqualified column' => [
                [['title' => 'posts.body'], 'title'],
                "Alias one of them: select(['title' => 'posts.body'], ['title_2' => 'title']).",
            ],
            'a taken name in another case' => [
                ['USERS_ID', 'posts.id', 'users.id'],
                "Alias one of them: select('USERS_ID', 'posts.id', ['users_id_2' => 'users.id']).",
            ],
        ];

        foreach ($cases as $case => [$columns, $fix]) {
            try {
                (new QueryBuilder('posts'))->select(...$columns);
                self::fail("Accepted {$case}.");
            } catch (BadQuery $problem) {
                self::assertSame($fix, $problem->fix, $case);

                // The fix, followed, is accepted, and keeps every column.
                $suggested = $problem->context['suggested'];
                self::assertIsArray($suggested, $case);
                $pairs = array_sum(array_map(static fn (string|array $entry): int => is_string($entry) ? 1 : count($entry), $columns));
                self::assertCount($pairs, (new QueryBuilder('posts'))->select(...$suggested)->toSelect()->columns, $case);
            }
        }

        try {
            (new QueryBuilder('posts'))->select(['title' => 'posts.title'], ['title' => 'users.name']);
            self::fail('Accepted two aliases alike.');
        } catch (BadQuery $problem) {
            self::assertStringContainsString(
                "two columns named 'title', ['title' => 'posts.title'] and ['title' => 'users.name']",
                $problem->getMessage(),
                'An aliased side is named as it was written.',
            );
        }
    }

    public function testAnAliasIsOneNameAndWhatItNamesIsAColumn(): void
    {
        $cases = [
            'a qualified alias' => [['users.author' => 'users.name'], "the alias 'users.author'"],
            'a star alias' => [['*' => 'users.name'], "the alias '*'"],
            'a list instead of a map' => [['posts.id', 'posts.title'], 'an array with the key 0'],
            'an expression' => [['total' => 'COUNT(*)'], "'COUNT(*)'"],
            'a star under an alias' => [['everything' => 'users.*'], "'users.*'"],
            'not a string' => [['author' => 42], "'int'"],
        ];

        foreach ($cases as $case => [$map, $message]) {
            try {
                (new QueryBuilder('posts'))->select($map);
                self::fail("Accepted {$case}.");
            } catch (BadQuery $problem) {
                self::assertStringContainsString($message, $problem->getMessage(), $case);
            }
        }
    }

    public function testCountWrapsTheSelectSoJoinsLimitsAndOffsetsCountAsTheyFetch(): void
    {
        $query = (new QueryBuilder('posts'))
            ->select(['author' => 'users.name'])
            ->innerJoin('users', 'users.id', 'posts.user_id')
            ->where('users.role', Operator::Eq, 'admin')
            ->orderBy('posts.id', Direction::Desc)
            ->limit(10)
            ->offset(20)
            ->toSelect();

        $count = (new Compiler(Dialect::Sqlite))->count($query);

        self::assertStringStartsWith('SELECT COUNT(*) AS "count" FROM (SELECT 1 FROM "posts" INNER JOIN "users" ON ', $count->sql);
        self::assertStringContainsString(' WHERE "users"."role" = ?', $count->sql);
        self::assertStringEndsWith(' WHERE "users"."role" = ? LIMIT 10 OFFSET 20) AS "counted"', $count->sql);
        self::assertSame(['admin'], $count->bindings);

        self::assertSame(
            'SELECT COUNT(*) AS `count` FROM (SELECT 1 FROM `posts`) AS `counted`',
            (new Compiler(Dialect::Mysql))->count((new QueryBuilder('posts'))->orderBy('id')->toSelect())->sql,
            'An order with no limit or offset decides nothing a count can see.',
        );
    }

    public function testCountDropsAnOrderEvenWhenALimitPagesIt(): void
    {
        // Lava Notes R3-B7: the inner SELECT is `1`, so an order by a select
        // alias named a column that was not there. An order decides which rows
        // a page keeps, never how many.
        $query = (new QueryBuilder('posts'))
            ->select('posts.title', ['author' => 'users.name'])
            ->innerJoin('users', 'users.id', 'posts.user_id')
            ->orderBy('author')
            ->limit(2)
            ->toSelect();

        $expected = [
            'sqlite' => 'SELECT COUNT(*) AS "count" FROM (SELECT 1 FROM "posts" INNER JOIN "users" ON "users"."id" = "posts"."user_id" LIMIT 2) AS "counted"',
            'mysql' => 'SELECT COUNT(*) AS `count` FROM (SELECT 1 FROM `posts` INNER JOIN `users` ON `users`.`id` = `posts`.`user_id` LIMIT 2) AS `counted`',
            'pgsql' => 'SELECT COUNT(*) AS "count" FROM (SELECT 1 FROM "posts" INNER JOIN "users" ON "users"."id" = "posts"."user_id" LIMIT 2) AS "counted"',
        ];
        foreach ([Dialect::Sqlite, Dialect::Mysql, Dialect::Pgsql] as $dialect) {
            self::assertSame($expected[$dialect->value], (new Compiler($dialect))->count($query)->sql, $dialect->value);
        }

        self::assertStringContainsString(' ORDER BY "author" ASC LIMIT 2', (new Compiler(Dialect::Sqlite))->compile($query)->sql, 'fetch() keeps it.');
    }
}
