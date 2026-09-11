<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Sql;

use Lava\Db\Query\Condition;
use Lava\Db\Query\DeleteQuery;
use Lava\Db\Query\Direction;
use Lava\Db\Query\InsertQuery;
use Lava\Db\Query\Join;
use Lava\Db\Query\JoinType;
use Lava\Db\Query\Operator;
use Lava\Db\Query\OrderBy;
use Lava\Db\Query\Query;
use Lava\Db\Query\SelectQuery;
use Lava\Db\Query\UpdateQuery;
use Lava\Db\Sql\Compiler;
use Lava\Db\Sql\Dialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The compiler, held to exact strings.
 *
 * This is the pack's centre of gravity and it runs with no database at all —
 * which is the point of keeping SQL generation pure. Every statement shape is
 * pinned as literal SQL plus literal bindings, so a change to how anything
 * compiles shows up as a failing string rather than as a surprise in
 * production. The expected text is written in the `"`-quoting dialects
 * (SQLite and PostgreSQL agree on every clause except LIMIT/OFFSET); MySQL's
 * backticks and the LIMIT/OFFSET differences have their own cases below.
 */
final class CompilerTest extends TestCase
{
    private static function compiler(Dialect $dialect): Compiler
    {
        return new Compiler($dialect);
    }

    /**
     * @return array<string, array{Query, string, list<int|float|string|null>}>
     */
    public static function statements(): array
    {
        return [
            'select all' => [
                new SelectQuery('users'),
                'SELECT * FROM "users"',
                [],
            ],
            'select named columns' => [
                new SelectQuery('users', ['id', 'name']),
                'SELECT "id", "name" FROM "users"',
                [],
            ],
            'select a star-qualified column' => [
                new SelectQuery('users', ['users.*']),
                'SELECT "users".* FROM "users"',
                [],
            ],
            // `order` and `group` are reserved in all three dialects; if
            // quoting ever regresses, these are the cases that fail.
            'reserved words survive quoting' => [
                new SelectQuery('order', ['group']),
                'SELECT "group" FROM "order"',
                [],
            ],
            'a quote inside an identifier is doubled, not escaped away' => [
                new SelectQuery('we"ird'),
                'SELECT * FROM "we""ird"',
                [],
            ],
            'where with a bool' => [
                new SelectQuery('users', ['*'], [Condition::compare(false, 'active', Operator::Eq, true)]),
                'SELECT * FROM "users" WHERE "active" = ?',
                [1],
            ],
            'where chains with AND, then OR' => [
                new SelectQuery('users', ['*'], [
                    Condition::compare(false, 'a', Operator::Eq, 1),
                    Condition::compare(false, 'b', Operator::Gt, 2),
                    Condition::compare(true, 'c', Operator::Lt, 3),
                ]),
                'SELECT * FROM "users" WHERE "a" = ? AND "b" > ? OR "c" < ?',
                [1, 2, 3],
            ],
            'where in' => [
                new SelectQuery('users', ['*'], [Condition::in(false, 'id', [1, 2, 3])]),
                'SELECT * FROM "users" WHERE "id" IN (?, ?, ?)',
                [1, 2, 3],
            ],
            'where not in' => [
                new SelectQuery('users', ['*'], [Condition::in(false, 'id', [7], not: true)]),
                'SELECT * FROM "users" WHERE "id" NOT IN (?)',
                [7],
            ],
            'where null and not null' => [
                new SelectQuery('users', ['*'], [
                    Condition::null(false, 'deleted_at'),
                    Condition::null(false, 'email', not: true),
                ]),
                'SELECT * FROM "users" WHERE "deleted_at" IS NULL AND "email" IS NOT NULL',
                [],
            ],
            'where between binds both ends in order' => [
                new SelectQuery('users', ['*'], [Condition::between(false, 'age', 18, 30)]),
                'SELECT * FROM "users" WHERE "age" BETWEEN ? AND ?',
                [18, 30],
            ],
            'where raw keeps the fragment and still binds' => [
                new SelectQuery('users', ['*'], [
                    Condition::compare(false, 'id', Operator::Eq, 1),
                    Condition::raw(false, 'LOWER("email") = ?', ['ada@example.com']),
                ]),
                'SELECT * FROM "users" WHERE "id" = ? AND LOWER("email") = ?',
                [1, 'ada@example.com'],
            ],
            'inner join' => [
                new SelectQuery('users', ['users.id'], [], [
                    new Join(JoinType::Inner, 'posts', 'users.id', Operator::Eq, 'posts.user_id'),
                ]),
                'SELECT "users"."id" FROM "users" INNER JOIN "posts" ON "users"."id" = "posts"."user_id"',
                [],
            ],
            'left join' => [
                new SelectQuery('users', ['*'], [], [
                    new Join(JoinType::Left, 'posts', 'users.id', Operator::Eq, 'posts.user_id'),
                ]),
                'SELECT * FROM "users" LEFT JOIN "posts" ON "users"."id" = "posts"."user_id"',
                [],
            ],
            'order by both directions' => [
                new SelectQuery('users', ['*'], [], [], [
                    new OrderBy('name'),
                    new OrderBy('created_at', Direction::Desc),
                ]),
                'SELECT * FROM "users" ORDER BY "name" ASC, "created_at" DESC',
                [],
            ],
            'insert one row' => [
                InsertQuery::of('users', [['name' => 'ada', 'age' => 36]]),
                'INSERT INTO "users" ("name", "age") VALUES (?, ?)',
                ['ada', 36],
            ],
            'insert many rows' => [
                InsertQuery::of('users', [['name' => 'ada', 'age' => 36], ['name' => 'grace', 'age' => 45]]),
                'INSERT INTO "users" ("name", "age") VALUES (?, ?), (?, ?)',
                ['ada', 36, 'grace', 45],
            ],
            'update with a where' => [
                UpdateQuery::of('users', ['name' => 'grace'], [Condition::compare(false, 'id', Operator::Eq, 1)]),
                'UPDATE "users" SET "name" = ? WHERE "id" = ?',
                ['grace', 1],
            ],
            'delete with a where' => [
                DeleteQuery::of('users', [Condition::compare(false, 'id', Operator::Eq, 1)]),
                'DELETE FROM "users" WHERE "id" = ?',
                [1],
            ],
            'a DateTime is written in the documented format' => [
                new SelectQuery('events', ['*'], [
                    Condition::compare(false, 'at', Operator::Gte, new \DateTimeImmutable('2026-09-11 08:30:00')),
                ]),
                'SELECT * FROM "events" WHERE "at" >= ?',
                ['2026-09-11 08:30:00'],
            ],
            'a backed enum binds its value' => [
                new SelectQuery('users', ['*'], [
                    Condition::compare(false, 'status', Operator::Eq, Status::Active),
                ]),
                'SELECT * FROM "users" WHERE "status" = ?',
                ['active'],
            ],
        ];
    }

    /**
     * @param list<int|float|string|null> $bindings
     */
    #[DataProvider('statements')]
    public function testTheSameSqlIsEmittedForEveryDoubleQuoteDialect(Query $query, string $sql, array $bindings): void
    {
        foreach ([Dialect::Sqlite, Dialect::Pgsql] as $dialect) {
            $compiled = self::compiler($dialect)->compile($query);

            self::assertSame($sql, $compiled->sql, "compiled for {$dialect->value}");
            self::assertSame($bindings, $compiled->bindings, "bindings for {$dialect->value}");
        }
    }

    /**
     * MySQL's differences are enumerated rather than derived from the SQLite
     * expectations by substitution, because two of them are NOT a swap of the
     * quote character: a `"` inside a backtick-quoted identifier is an
     * ordinary character, and a raw fragment is never rewritten at all. A
     * naive `str_replace` would have asserted both of those wrong.
     *
     * @return array<string, array{Query, string}>
     */
    public static function mysqlStatements(): array
    {
        return [
            'identifiers are backtick-quoted' => [
                new SelectQuery('users', ['id'], [
                    Condition::compare(false, 'active', Operator::Eq, true),
                    Condition::in(false, 'role', ['admin']),
                ], [], [new OrderBy('id', Direction::Desc)], 5, 10),
                'SELECT `id` FROM `users` WHERE `active` = ? AND `role` IN (?) ORDER BY `id` DESC LIMIT 5 OFFSET 10',
            ],
            'writes are backtick-quoted too' => [
                InsertQuery::of('order', [['group' => 'a']]),
                'INSERT INTO `order` (`group`) VALUES (?)',
            ],
            'a double quote inside an identifier is just a character' => [
                new SelectQuery('we"ird'),
                'SELECT * FROM `we"ird`',
            ],
            // MySQL escapes its own quote character, and only its own.
            'a backtick inside an identifier is doubled' => [
                new SelectQuery('we`ird'),
                'SELECT * FROM `we``ird`',
            ],
            // The escape hatch is the caller's SQL, in every dialect. If the
            // compiler rewrote it, a hand-written MySQL fragment would be
            // mangled by the very abstraction it was reaching past.
            'a raw fragment is passed through untouched' => [
                new SelectQuery('users', ['*'], [Condition::raw(false, 'LOWER("email") = ?', ['ada@example.com'])]),
                'SELECT * FROM `users` WHERE LOWER("email") = ?',
            ],
        ];
    }

    #[DataProvider('mysqlStatements')]
    public function testMysqlQuotesWithBackticksAndLeavesRawSqlAlone(Query $query, string $sql): void
    {
        self::assertSame($sql, self::compiler(Dialect::Mysql)->compile($query)->sql);
    }

    public function testTheDoubleQuoteDialectsLeaveABacktickAlone(): void
    {
        $compiled = self::compiler(Dialect::Sqlite)->compile(new SelectQuery('we`ird'));

        self::assertSame('SELECT * FROM "we`ird"', $compiled->sql);
    }

    /**
     * @return array<string, array{Dialect, int|null, int|null, string}>
     */
    public static function limitsAndOffsets(): array
    {
        return [
            'sqlite: limit' => [Dialect::Sqlite, 10, null, ' LIMIT 10'],
            'sqlite: offset needs a limit it does not have' => [Dialect::Sqlite, null, 20, ' LIMIT -1 OFFSET 20'],
            'sqlite: both' => [Dialect::Sqlite, 10, 20, ' LIMIT 10 OFFSET 20'],
            'mysql: limit' => [Dialect::Mysql, 10, null, ' LIMIT 10'],
            // MySQL has no "unbounded" LIMIT; its idiom is the 64-bit maximum.
            'mysql: offset needs a limit it does not have' => [Dialect::Mysql, null, 20, ' LIMIT 18446744073709551615 OFFSET 20'],
            'mysql: both' => [Dialect::Mysql, 10, 20, ' LIMIT 10 OFFSET 20'],
            'pgsql: limit' => [Dialect::Pgsql, 10, null, ' LIMIT 10'],
            // PostgreSQL is the only one of the three that accepts a bare OFFSET.
            'pgsql: offset alone is legal' => [Dialect::Pgsql, null, 20, ' OFFSET 20'],
            'pgsql: both' => [Dialect::Pgsql, 10, 20, ' LIMIT 10 OFFSET 20'],
            'nothing at all' => [Dialect::Sqlite, null, null, ''],
        ];
    }

    #[DataProvider('limitsAndOffsets')]
    public function testOffsetWithoutLimitIsSpelledPerDialect(Dialect $dialect, ?int $limit, ?int $offset, string $expected): void
    {
        $compiled = self::compiler($dialect)->compile(new SelectQuery('users', ['*'], [], [], [], $limit, $offset));

        self::assertSame('SELECT * FROM ' . $dialect->quote('users') . $expected, $compiled->sql);
    }

    public function testADialectIsChosenFromTheDsnScheme(): void
    {
        self::assertSame(Dialect::Sqlite, Dialect::fromDsn('sqlite::memory:'));
        self::assertSame(Dialect::Mysql, Dialect::fromDsn('mysql:host=127.0.0.1;dbname=app'));
        self::assertSame(Dialect::Pgsql, Dialect::fromDsn('PGSQL:host=127.0.0.1'));
    }

    public function testAnUnknownSchemeIsRefusedBySchemeOnly(): void
    {
        // The DSN can carry a password, and a problem report goes to logs and
        // CI output — so the diagnosis names the scheme and nothing else.
        try {
            Dialect::fromDsn('oracle:host=db;password=hunter2');
            self::fail('an unsupported scheme should not resolve to a dialect');
        } catch (\Lava\Db\Problem\UnsupportedDialect $problem) {
            self::assertSame('unsupported_dialect', $problem->code());
            self::assertSame('oracle', $problem->context['scheme']);
            self::assertStringNotContainsString('hunter2', $problem->getMessage());
            self::assertStringNotContainsString('hunter2', json_encode($problem->json(), JSON_THROW_ON_ERROR));
        }
    }
}

/** A backed enum for the binding test, declared here so it needs no app. */
enum Status: string
{
    case Active = 'active';
    case Retired = 'retired';
}
