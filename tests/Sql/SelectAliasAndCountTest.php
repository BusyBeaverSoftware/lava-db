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
