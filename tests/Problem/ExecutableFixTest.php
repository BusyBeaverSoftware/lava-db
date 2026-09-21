<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Problem;

use Lava\Db\Problem\BadQuery;
use Lava\Db\Problem\MigrationFailed;
use PHPUnit\Framework\TestCase;

/**
 * A `fix` is an imperative someone pastes, so what it interpolates is code.
 *
 * Two of this pack's fixes built SQL by interpolating a name it does not
 * control — a migration name read out of the repository table, and a table name
 * from the caller — so `x'; DROP TABLE users; --` came back as a runnable
 * statement, in a framework whose whole premise is that an agent executes the
 * fix (security review).
 */
final class ExecutableFixTest extends TestCase
{
    private const HOSTILE = "2026_01_01_000000_x'; DROP TABLE users; --";

    public function testAMigrationNameIsBoundInTheSuggestionRatherThanPastedIntoIt(): void
    {
        $fix = MigrationFailed::missingFile(self::HOSTILE, '/app/migrations', 3)->fix;

        // The suggestion is a prepared statement, so there is no quoted literal
        // for a name to break out of.
        self::assertStringContainsString('WHERE name = ?', $fix);
        self::assertStringNotContainsString("WHERE name = '", $fix);

        // The name still appears in the path to restore, which is a filename
        // and not a statement. What matters is that the SQL half of the fix
        // carries none of it: everything from `DELETE FROM` on is fixed text.
        $statement = substr($fix, (int) strpos($fix, 'DELETE FROM'));
        self::assertStringNotContainsString('DROP TABLE users', $statement);
        self::assertStringNotContainsString(';', $statement);
    }

    public function testATableNameIsRenderedAsALiteralRatherThanInterpolated(): void
    {
        $fix = BadQuery::unbounded('DELETE', "docs'); DROP TABLE users; --")->fix;

        // var_export escapes the quote, so the payload cannot close the string
        // it is inside and become a second statement.
        self::assertStringContainsString("\\'", $fix);
        self::assertStringNotContainsString("statement('DELETE docs');", $fix);
    }

    public function testTheHostileNameIsStillAvailableWhereItIsData(): void
    {
        // Nothing is hidden: the context carries the real value, which is where
        // a reader (or a tool) should take it from.
        self::assertSame(self::HOSTILE, MigrationFailed::missingFile(self::HOSTILE, '/app/migrations', 3)->context['migration']);
    }
}
