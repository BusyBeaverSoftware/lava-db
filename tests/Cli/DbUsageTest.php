<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Cli;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\LavaCli;
use Lava\Core\Tests\Support\LavaResult;
use PHPUnit\Framework\TestCase;

/**
 * A flag the db pack declares, and two flags it does not.
 *
 * The kernel refuses an undeclared flag for every command, core or pack, so
 * what only this package can show is the pair of edges a blanket rule gets
 * wrong: a flag DECLARED BY A PACK must keep working (the rule must not have
 * become a list of core's flags), and a flag belonging to the pack's OTHER
 * command must be refused. `lava db:status --batches` is `lava env --strict`
 * in pack form — the mistake a helpful-sounding flag invites.
 *
 * No database is needed, which is why this is separate from `DbCommandsTest`
 * and does not skip with it: a refusal happens before the command runs, and an
 * accepted flag is reported as `db_not_configured` — which is itself the
 * evidence that it reached the command rather than being rejected.
 */
final class DbUsageTest extends TestCase
{
    public function testADeclaredPackFlagIsAccepted(): void
    {
        $result = $this->lava(['db:rollback', '--batches=1', '--json']);

        // Not exit 2: the pack declared `batches`, so the kernel passed it
        // through, and the command ran far enough to find no database.
        self::assertNotSame(ExitCode::Usage, $result->exit, $result->stdout);
        self::assertSame(['db_not_configured'], $result->codes());
    }

    public function testASiblingCommandsFlagIsRefusedWithTheListThatWouldWork(): void
    {
        $result = $this->lava(['db:status', '--batches=1', '--json']);

        self::assertSame(ExitCode::Usage, $result->exit);
        self::assertSame(['bad_usage'], $result->codes());
        self::assertSame('batches', $result->context('bad_usage', 'flag'));

        // The accepted list is `db:status`'s own. The sibling declaring the
        // flag does not make it usable here, and the fix names the command to
        // ask rather than listing the sibling's flags.
        $accepted = $result->context('bad_usage', 'accepted');
        self::assertIsArray($accepted);
        self::assertNotContains('batches', $accepted);
        self::assertStringContainsString(
            'lava db:status --help',
            (string) $result->problem('bad_usage')['fix'],
        );
    }

    /**
     * The pack's flag, refused by a CORE command.
     *
     * One flag map, two namespaces: `batches` is a real flag in this process —
     * a pack command declares it — and `routes` still does not accept it.
     */
    public function testAPackFlagIsRefusedByACoreCommand(): void
    {
        $result = $this->lava(['routes', '--batches=1', '--json']);

        self::assertSame(ExitCode::Usage, $result->exit);
        self::assertSame(['bad_usage'], $result->codes());
        self::assertSame('batches', $result->context('bad_usage', 'flag'));
    }

    /** @param list<string> $args */
    private function lava(array $args): LavaResult
    {
        // With no DSN the app still boots — the pack builds its Connection
        // without connecting — so a pack command resolves, and a refusal cannot
        // be mistaken for a boot that never got that far.
        return LavaCli::run($args, dirname(__DIR__) . '/fixtures/apps/db-app', ['DATABASE_DSN' => null]);
    }
}
