<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Problem;

use Lava\Db\Problem\QueryFailed;
use Lava\Db\Sql\Compiled;
use PHPUnit\Framework\TestCase;

/**
 * What a failed statement is allowed to say, and where.
 *
 * Production withholds a 5xx's `context` and keeps its `problem`, so anything
 * in the sentence is published to whoever provoked the failure. The driver's
 * own words name tables, columns and — on MySQL — the offending value, so they
 * travel in the context with the SQL and the bindings, which the same rule
 * already withholds (security review).
 */
final class QueryFailedTest extends TestCase
{
    public function testTheDriversWordsTravelInTheContextRatherThanTheSentence(): void
    {
        $problem = QueryFailed::of(
            new Compiled('SELECT * FROM "admin_sessions" WHERE "email" = ?', ['ada@example.test']),
            new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: admin_sessions'),
        );

        self::assertSame('query_failed', $problem->code());
        self::assertSame('The database rejected a statement.', $problem->getMessage());

        // The schema is the thing an unauthenticated caller must not be handed.
        self::assertStringNotContainsString('admin_sessions', $problem->getMessage());
        self::assertStringNotContainsString('no such table', $problem->getMessage());

        // Nothing is lost: the context carries the whole diagnosis, and dev
        // renders it.
        self::assertStringContainsString('no such table: admin_sessions', (string) $problem->context['driver_message']);
        self::assertSame('SELECT * FROM "admin_sessions" WHERE "email" = ?', $problem->context['sql']);
        self::assertSame(['ada@example.test'], $problem->context['bindings']);
    }

    public function testASubmittedValueInAConstraintMessageStaysOutOfTheSentence(): void
    {
        // The MySQL shape: a duplicate-key error quotes the value that
        // collided, so the sentence would have carried a user's email.
        $problem = QueryFailed::of(
            new Compiled('INSERT INTO "users" ("email") VALUES (?)', ['ada@example.test']),
            new \PDOException("SQLSTATE[23000]: Duplicate entry 'ada@example.test' for key 'users.email_unique'"),
        );

        self::assertStringNotContainsString('ada@example.test', $problem->getMessage());
        self::assertStringContainsString('ada@example.test', (string) $problem->context['driver_message']);
        // The fix still tells the reader where to look.
        self::assertStringContainsString('driver_message', $problem->fix);
    }
}
