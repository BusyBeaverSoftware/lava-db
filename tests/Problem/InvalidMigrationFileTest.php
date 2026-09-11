<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Db\Problem\InvalidMigrationFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every way a migration file can be wrong, asserted as a report rather than as
 * prose.
 *
 * There are five of these and they are the pack's most user-facing refusals:
 * they fire while someone is writing a migration, which is the worst moment to
 * be told only "something went wrong". Each is a factory on one class, so they
 * are cheap to cover as a table — and covering them here is the difference
 * between `InvalidMigrationFile` reading as 16% covered and reading as what it
 * is, a well-specified error surface.
 *
 * **The provider yields closures, not problems.** A `LavaProblem` constructed
 * in a data provider is built during PHPUnit's test ENUMERATION, before the
 * coverage driver opens its first window — so the factory bodies would read as
 * uncovered no matter how many cases asserted on them. Calling the closure
 * inside the test keeps the construction in the measured window. That is the
 * same reason `QueryBuilderChainTest` and `QueryBuilderTest` yield closures.
 *
 * **What this class does NOT claim.** It pins the contract of each factory.
 * Whether each factory is REACHABLE from a real command is a different
 * question, answered for two of them in {@see \Lava\Db\Tests\Cli\DbCommandsTest}
 * and recorded as unreachable-by-design for the other two in DECISIONS.md —
 * `alreadyExists` needs two `db:new` runs inside one second, and `unwritable`
 * needs a directory the test process cannot write, which as root it always can.
 */
final class InvalidMigrationFileTest extends TestCase
{
    private const FILE = '/app/app/Database/Migrations/2026_01_01_000000_create_users_table.php';

    /**
     * @return array<string, array{\Closure(): LavaProblem, string, string, list<string>, string|null}>
     */
    public static function problems(): array
    {
        return [
            'a file that returns something else' => [
                static fn (): LavaProblem => InvalidMigrationFile::notAMigration(self::FILE, 'int'),
                'returned int instead of a Migration',
                'return new class extends Migration',
                ['file', 'returned'],
                self::FILE,
            ],
            'a file whose name is not a migration name' => [
                static fn (): LavaProblem => InvalidMigrationFile::badName(self::FILE),
                'is not named like a migration',
                '<YYYY_MM_DD_HHMMSS>_<snake_case_description>.php',
                ['file'],
                self::FILE,
            ],
            'a file that throws while being loaded' => [
                static fn (): LavaProblem => InvalidMigrationFile::threw(
                    self::FILE,
                    new \RuntimeException('boom'),
                ),
                'threw: boom',
                'no queries, no side effects at load time',
                ['file', 'exception'],
                self::FILE,
            ],
            'a file db:new would overwrite' => [
                static fn (): LavaProblem => InvalidMigrationFile::alreadyExists(self::FILE),
                'already exists',
                'the timestamp advances',
                ['file'],
                self::FILE,
            ],
            'a migrations directory that cannot be created' => [
                static fn (): LavaProblem => InvalidMigrationFile::unwritable(
                    '/app/app/Database/Migrations',
                    true,
                ),
                'Could not create the migrations directory',
                'ls -ld /app/app/Database',
                ['path', 'is_directory'],
                null,
            ],
            'a migration file that cannot be written' => [
                static fn (): LavaProblem => InvalidMigrationFile::unwritable(self::FILE, false),
                'Could not write the migration file',
                'ls -ld /app/app/Database/Migrations',
                ['path', 'is_directory'],
                null,
            ],
        ];
    }

    /**
     * @param \Closure(): LavaProblem $make
     * @param list<string> $contextKeys
     */
    #[DataProvider('problems')]
    public function testTheRefusalNamesTheFileTheCauseAndTheFix(
        \Closure $make,
        string $message,
        string $fix,
        array $contextKeys,
        ?string $sourceFile,
    ): void {
        $problem = $make();

        self::assertSame('invalid_migration_file', $problem->code());
        self::assertStringContainsString($message, $problem->getMessage());
        self::assertStringContainsString($fix, $problem->fix);
        self::assertSame($contextKeys, array_keys($problem->context));
        self::assertSame(500, $problem->httpStatus());

        // The source is a real file and line 1, so an editor can jump to the
        // file that has to change. The two permission failures have no source
        // because there is no user-authored artifact at fault — the directory
        // is the environment's, not the app's.
        if ($sourceFile === null) {
            self::assertNull($problem->source);
        } else {
            self::assertNotNull($problem->source);
            self::assertSame($sourceFile, $problem->source->file);
            self::assertSame(1, $problem->source->line);
        }
    }

    /**
     * The three that are ABOUT a file point at it, and the two that are about
     * a permission do not. Asserted as a set because the distinction is a
     * decision, not an accident: `source` means "the user-authored artifact at
     * fault", and a read-only checkout is not one.
     */
    public function testOnlyTheFileProblemsCarryASource(): void
    {
        self::assertNotNull(InvalidMigrationFile::badName(self::FILE)->source);
        self::assertNotNull(InvalidMigrationFile::alreadyExists(self::FILE)->source);
        self::assertNull(InvalidMigrationFile::unwritable(self::FILE, false)->source);
        self::assertNull(InvalidMigrationFile::unwritable('/app/app/Database/Migrations', true)->source);
    }

    /**
     * The returned TYPE is reported, never the value. A migration file that
     * returns something unexpected may have returned anything at all — an
     * object with credentials in it, a huge array, a resource — and a problem
     * report is written to terminals and CI logs. `get_debug_type` is what
     * makes that safe, and this is the test that keeps it from being replaced
     * with `var_export` by someone trying to be helpful.
     */
    public function testAnUnexpectedReturnValueIsReportedAsATypeNotAValue(): void
    {
        $problem = InvalidMigrationFile::notAMigration(self::FILE, 'array');

        self::assertSame('array', $problem->context['returned']);
        self::assertStringContainsString('returned array', $problem->getMessage());
    }

    /**
     * The load-time throw keeps the original exception chained, so a
     * `--verbose` path can print the stack that actually explains the failure
     * rather than only the fact that loading failed.
     */
    public function testALoadTimeThrowKeepsTheOriginalException(): void
    {
        $previous = new \RuntimeException('boom');
        $problem = InvalidMigrationFile::threw(self::FILE, $previous);

        self::assertSame($previous, $problem->getPrevious());
        self::assertSame(\RuntimeException::class, $problem->context['exception']);
    }

    /**
     * The fix for a collision tells the reader to WAIT rather than to force it,
     * which is the whole point of refusing instead of overwriting: the file
     * that is already there may be someone's half-written work. A fix that
     * suggested `--force` would undo the safety the refusal exists for.
     */
    public function testTheCollisionFixDoesNotOfferToOverwrite(): void
    {
        $fix = InvalidMigrationFile::alreadyExists(self::FILE)->fix;

        self::assertStringContainsString('Wait a second', $fix);
        self::assertStringNotContainsString('--force', $fix);
        self::assertStringNotContainsString('overwrite', $fix);
    }
}
