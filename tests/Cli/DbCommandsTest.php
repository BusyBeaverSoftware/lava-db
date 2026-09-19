<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Cli;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\LavaCli;
use Lava\Core\Tests\Support\LavaResult;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests that run the REAL `bin/lava` against a real fixture app and a
 * real SQLite database file.
 *
 * The unit tests prove the compiler emits the right DDL and the live tests
 * prove the connection can execute it; only this proves the four `db:*`
 * commands an agent actually shells out to — that they resolve the app from
 * the working directory, find the migrations in app/Database/Migrations,
 * report the right payload under `--json`, and exit with the right code.
 *
 * **A file, not `sqlite::memory:`.** Each invocation is its own process, so an
 * in-memory database would be created and discarded by every command, and
 * `db:status` could never see what `db:migrate` did.
 *
 * **The app is copied per test.** Three of these tests write into the app —
 * `db:new` creates a migration, two others delete one — so the fixture on disk
 * stays pristine and the tests stay order-independent.
 *
 * **Skipped, not failed, without a driver.** These need `pdo_sqlite` in BOTH
 * this process (to assert the schema landed) and the `lava` subprocess (to do
 * the migrating). On a machine where it is loaded from php.ini both are
 * automatic; where it comes from `-d extension=...` the subprocess needs
 * `PHP_INI_SCAN_DIR` instead, because the harness forwards the environment and
 * not the command line. {@see self::setUp()} skips with that instruction
 * rather than failing, since neither state is a defect in this pack.
 */
final class DbCommandsTest extends TestCase
{
    private string $appDir = '';
    private string $database = '';

    /** null = not probed yet. Cached: the probe is a subprocess. */
    private static ?bool $childHasSqlite = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped(
                'pdo_sqlite is not loaded here, so the test cannot check what the commands did. '
                . 'Run the suite with -d extension=<path to pdo_sqlite.so>.',
            );
        }

        if (!self::childHasSqlite()) {
            self::markTestSkipped(
                'the lava subprocess has no pdo_sqlite. The harness forwards the environment, not the '
                . 'command line, so load it with PHP_INI_SCAN_DIR=<dir containing an ini with '
                . 'extension=<path to pdo_sqlite.so> instead of -d extension=...',
            );
        }

        $root = sys_get_temp_dir() . '/lava-db-cli-' . bin2hex(random_bytes(6));
        $this->appDir = $root . '/db-app';
        $this->database = $root . '/app.sqlite';

        mkdir($root, 0o755, true);
        self::copyTree(self::fixtureApp(), $this->appDir);
    }

    protected function tearDown(): void
    {
        if ($this->appDir !== '') {
            self::removeTree(dirname($this->appDir));
        }
    }

    public function testStatusOnAFreshDatabaseListsEveryMigrationAsPending(): void
    {
        $result = $this->lava(['db:status', '--json']);

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        self::assertSame('lava.db.status/1', $result->schema());
        self::assertSame('ok', $result->status());

        $data = $result->data();
        self::assertSame([], $data['applied']);
        self::assertSame(
            ['2026_01_01_000000_create_users_table', '2026_01_01_000001_create_posts_table'],
            $data['pending'],
        );
        self::assertSame(2, $data['pending_count']);
        self::assertSame([], $data['orphaned']);
        self::assertSame(0, $data['batch']);
    }

    public function testMigrateAppliesEverythingInOneBatchAndStatusAgrees(): void
    {
        $migrate = $this->lava(['db:migrate', '--json']);

        self::assertSame(ExitCode::Ok, $migrate->exit, $migrate->stderr);
        self::assertSame('lava.db.migrate/1', $migrate->schema());
        self::assertSame(
            ['2026_01_01_000000_create_users_table', '2026_01_01_000001_create_posts_table'],
            $migrate->data()['applied'],
        );
        self::assertSame(2, $migrate->data()['applied_count']);
        // One batch per run is what makes `db:rollback` able to undo "the last
        // deploy" without the caller counting migrations.
        self::assertSame(1, $migrate->data()['batch']);

        // The DDL actually reached the database — the repository saying so
        // would be true even if every CREATE TABLE had been skipped.
        self::assertSame(['lava_migrations', 'posts', 'users'], $this->tables());

        $status = $this->lava(['db:status', '--json']);
        self::assertSame(2, count($status->data()['applied']));
        self::assertSame([], $status->data()['pending']);
        self::assertSame(1, $status->data()['batch']);
        self::assertSame(
            '2026_01_01_000000_create_users_table',
            $status->data()['applied'][0]['name'],
        );
        self::assertIsString($status->data()['applied'][0]['applied_at']);
    }

    public function testMigrateIsIdempotent(): void
    {
        $this->lava(['db:migrate', '--json']);
        $again = $this->lava(['db:migrate', '--json']);

        self::assertSame(ExitCode::Ok, $again->exit, $again->stderr);
        self::assertSame([], $again->data()['applied']);
        self::assertSame(0, $again->data()['applied_count']);
        // The batch number stays put rather than advancing: nothing was
        // applied, so there is no new batch for a rollback to target.
        self::assertSame(1, $again->data()['batch']);
    }

    public function testRollbackUndoesTheBatchNewestFirstAndKeepsTheRepository(): void
    {
        $this->lava(['db:migrate', '--json']);
        $rollback = $this->lava(['db:rollback', '--json']);

        self::assertSame(ExitCode::Ok, $rollback->exit, $rollback->stderr);
        self::assertSame('lava.db.rollback/1', $rollback->schema());
        // Reverse of the order they were applied: `posts` references `users`,
        // so dropping `users` first would fail on a database that enforces it.
        self::assertSame(
            ['2026_01_01_000001_create_posts_table', '2026_01_01_000000_create_users_table'],
            $rollback->data()['rolled_back'],
        );
        self::assertSame(2, $rollback->data()['rolled_back_count']);
        self::assertSame([1], $rollback->data()['batches']);

        // The tables are gone; the repository is not. Keeping it is what lets
        // the next `db:status` say "pending" instead of "nothing has ever run".
        self::assertSame(['lava_migrations'], $this->tables());

        $status = $this->lava(['db:status', '--json']);
        self::assertSame([], $status->data()['applied']);
        self::assertSame(2, $status->data()['pending_count']);
    }

    public function testRollbackWithNothingAppliedIsAQuietSuccess(): void
    {
        $result = $this->lava(['db:rollback', '--json']);

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        self::assertSame([], $result->data()['rolled_back']);
        self::assertSame([], $result->data()['batches']);
        // Nothing ran, so nothing was created — not even the repository.
        self::assertSame([], $this->tables());
    }

    public function testRollbackRefusesWhenAMigrationFileIsGone(): void
    {
        $this->lava(['db:migrate', '--json']);
        unlink($this->appDir . '/app/Database/Migrations/2026_01_01_000001_create_posts_table.php');

        // Reported by status first: this is the finding that has to arrive
        // before it matters, not during the rollback that trips over it.
        $status = $this->lava(['db:status', '--json']);
        self::assertSame(ExitCode::Ok, $status->exit);
        self::assertSame(
            ['2026_01_01_000001_create_posts_table'],
            array_column($status->data()['orphaned'], 'name'),
        );

        $rollback = $this->lava(['db:rollback', '--json']);

        self::assertSame(ExitCode::Failure, $rollback->exit);
        self::assertSame(['migration_failed'], $rollback->codes());
        self::assertSame(
            '2026_01_01_000001_create_posts_table',
            $rollback->context('migration_failed', 'migration'),
        );
        self::assertStringContainsString(
            '2026_01_01_000001_create_posts_table.php',
            (string) $rollback->problem('migration_failed')['fix'],
        );

        // Refused rather than skipped: nothing was rolled back at all, so the
        // schema still matches what the repository claims.
        self::assertSame([], $rollback->data()['rolled_back']);
        self::assertSame(['lava_migrations', 'posts', 'users'], $this->tables());
    }

    public function testMigrateReportsWhatItAppliedWhenALaterMigrationFails(): void
    {
        $this->writeMigration(
            '2026_02_01_000000_break_things',
            <<<'PHP'
                    $db->schema()->create('things', function (Table $t): void {
                        $t->id();
                        $t->string('name');
                    });

                    $db->statement('INSERT INTO things (nonexistent_column) VALUES (1)');
            PHP,
        );

        $result = $this->lava(['db:migrate', '--json']);

        self::assertSame(ExitCode::Failure, $result->exit);
        // The raw diagnosis, not a wrapper: `query_failed` names the statement
        // and the column. Replacing it with "a migration failed" would swap
        // the answer for the fact that there is a question.
        self::assertSame(['query_failed'], $result->codes());

        // The two migrations before the failure are reported as applied. An
        // `applied: []` next to a database that now has `users` and `posts`
        // would be the one report an agent must not be given.
        self::assertSame(
            ['2026_01_01_000000_create_users_table', '2026_01_01_000001_create_posts_table'],
            $result->data()['applied'],
        );
        self::assertSame(2, $result->data()['applied_count']);
        self::assertSame(1, $result->data()['batch']);

        // `things` exists because the DDL half of the failing migration ran —
        // there is no transaction around a migration, deliberately, since
        // MySQL would have committed the DDL anyway. What matters is that the
        // failing migration was NOT recorded, so the run resumes.
        self::assertSame(['lava_migrations', 'posts', 'things', 'users'], $this->tables());
        $status = $this->lava(['db:status', '--json']);
        self::assertSame(
            ['2026_02_01_000000_break_things'],
            $status->data()['pending'],
        );
    }

    public function testNewWritesAReversibleMigrationThatMigrateThenApplies(): void
    {
        $new = $this->lava(['db:new', 'create_widgets_table', '--json']);

        self::assertSame(ExitCode::Ok, $new->exit, $new->stderr);
        self::assertSame('lava.db.new/1', $new->schema());

        $data = $new->data();
        self::assertIsString($data['name']);
        self::assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_widgets_table$/', $data['name']);
        self::assertSame('widgets', $data['table']);
        self::assertFalse($data['is_empty']);

        // Written into the app, not merely named in the payload.
        $path = $this->appDir . '/app/Database/Migrations/' . $data['name'] . '.php';
        self::assertFileExists($path);

        // The generated file is a working migration, not a stub to finish: it
        // migrates, and the table it claims to create is there afterwards.
        $migrate = $this->lava(['db:migrate', '--json']);
        self::assertSame(ExitCode::Ok, $migrate->exit, $migrate->stderr);
        self::assertContains($data['name'], $migrate->data()['applied']);
        self::assertContains('widgets', $this->tables());

        // And it is reversible, which is the property a generator is most
        // likely to get wrong by leaving `down()` empty.
        $rollback = $this->lava(['db:rollback', '--json']);
        self::assertSame(ExitCode::Ok, $rollback->exit, $rollback->stderr);
        self::assertNotContains('widgets', $this->tables());
    }

    public function testNewAcceptsCamelCaseAndWritesTheSameFile(): void
    {
        $new = $this->lava(['db:new', 'CreateUsersTable', '--json']);

        self::assertSame(ExitCode::Ok, $new->exit, $new->stderr);
        self::assertStringEndsWith('_create_users_table', (string) $new->data()['name']);
        self::assertSame('users', $new->data()['table']);
    }

    public function testNewNeverSortsBeforeTheNewestMigrationOnDisk(): void
    {
        // The filename is the run order. A migration already stamped later than
        // now — a colleague's clock, or one generated a moment ago — must not be
        // overtaken by the next one generated, or that one would run first.
        // `db:new` reads names only, so the file's content is never loaded.
        file_put_contents($this->appDir . '/app/Database/Migrations/2099_01_01_000000_create_future_table.php', "<?php\n");

        $new = $this->lava(['db:new', 'create_after_table', '--json']);

        self::assertSame(ExitCode::Ok, $new->exit, $new->stderr);
        self::assertSame('2099_01_01_000001_create_after_table', $new->data()['name']);
    }

    public function testTwoMigrationsGeneratedBackToBackRunInTheOrderTheyWereMade(): void
    {
        // `checks` sorts before `monitors` by description; generated after it,
        // inside the same second or not, it must still sort after it.
        $first = $this->lava(['db:new', 'create_monitors_table', '--json']);
        $second = $this->lava(['db:new', 'create_checks_table', '--json']);

        self::assertSame(ExitCode::Ok, $second->exit, $second->stderr);
        $names = [(string) $first->data()['name'], (string) $second->data()['name']];
        $sorted = $names;
        sort($sorted);
        self::assertSame($names, $sorted);
    }

    public function testNewWithADescriptionItCannotNameIsARefusedArgument(): void
    {
        $result = $this->lava(['db:new', '123 bad name', '--json']);

        self::assertSame(ExitCode::Usage, $result->exit);
        self::assertSame(['bad_usage'], $result->codes());
        // `<description>`, not `--description`: it is a positional, and an
        // agent told otherwise retries with a flag and gets a second, different
        // failure. The name in the report is the name in the usage line.
        self::assertStringContainsString('for <description>', (string) $result->problem('bad_usage')['problem']);
        self::assertSame('description', $result->context('bad_usage', 'argument'));

        // Nothing was written on the way to refusing.
        self::assertSame(
            ['2026_01_01_000000_create_users_table.php', '2026_01_01_000001_create_posts_table.php'],
            self::migrationFiles($this->appDir),
        );
    }

    public function testRollbackRefusesABatchesValueItCannotHonour(): void
    {
        $result = $this->lava(['db:rollback', '--batches=all', '--json']);

        self::assertSame(ExitCode::Usage, $result->exit);
        self::assertSame(['bad_usage'], $result->codes());
        self::assertSame('batches', $result->context('bad_usage', 'flag'));
    }

    public function testAboutReportsHowManyMigrationsAreWaiting(): void
    {
        // Lava Notes R3-G4: `lava about` had no fact a pack holds, so "the code is
        // deployed and the schema is not" was invisible unless someone ran db:status.
        $fresh = $this->lava(['about', '--json']);

        self::assertSame(ExitCode::Ok, $fresh->exit, $fresh->stderr);
        self::assertSame('lava.about/2', $fresh->schema());
        $pack = self::dbPack($fresh->data());
        self::assertSame(['pending_migrations' => 2], $pack['facts']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $pack['version']);

        self::assertSame(ExitCode::Ok, $this->lava(['db:migrate', '--json'])->exit);

        $migrated = $this->lava(['about', '--json']);
        self::assertSame(['pending_migrations' => 0], self::dbPack($migrated->data())['facts']);
    }

    public function testAboutSaysWhyItCannotCountMigrationsInsteadOfFailing(): void
    {
        $result = $this->lava(['about', '--json'], ['DATABASE_DSN' => null]);

        // Still a success: `about` is the command someone runs when the app is
        // already broken, so a pack that cannot answer reports why and the rest
        // of the report stands.
        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        $facts = self::dbPack($result->data())['facts'];
        self::assertIsArray($facts);
        self::assertSame('db_not_configured', $facts['error'] ?? null);
        self::assertIsString($facts['message'] ?? null);
    }

    /**
     * lavaphp/db's entry in an `about` payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function dbPack(array $data): array
    {
        $packs = $data['packs'] ?? null;
        self::assertIsArray($packs);
        foreach ($packs as $pack) {
            if (is_array($pack) && ($pack['package'] ?? null) === 'lavaphp/db') {
                return $pack;
            }
        }

        self::fail('about reported no lavaphp/db pack.');
    }

    public function testWithoutADsnTheCommandsSayWhatToConfigure(): void
    {
        // The pack is enabled and the app is fine; the database is simply not
        // configured yet. Naming the variable and the file is the whole
        // diagnosis — an agent can act on that without reading any source.
        $result = $this->lava(['db:migrate', '--json'], ['DATABASE_DSN' => null]);

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame(['db_not_configured'], $result->codes());
        self::assertStringContainsString('DATABASE_DSN', (string) $result->problem('db_not_configured')['fix']);
        self::assertSame(
            ['DATABASE_DSN', 'config/database.php: dsn'],
            $result->context('db_not_configured', 'looked_for'),
        );
    }

    /**
     * The one path that reaches `DbConnectionFailed`: the `catch` around
     * `new \PDO(...)`. Without it a driver failure exits 255 with a stack
     * trace, which is the failure mode this pack exists to not have.
     *
     * The DSN points into a directory that does not exist, so SQLite cannot
     * open the file and the driver raises. No network, no second driver, and
     * the same failure on every platform.
     */
    public function testAConnectionThatCannotBeOpenedIsAProblemNotAStackTrace(): void
    {
        $result = $this->lava(['db:status', '--json'], [
            'DATABASE_DSN' => 'sqlite:' . dirname($this->appDir) . '/no-such-dir/app.sqlite',
        ]);

        self::assertSame(ExitCode::Failure, $result->exit, $result->stderr);
        self::assertSame(['db_connection_failed'], $result->codes());
        self::assertSame('sqlite', $result->context('db_connection_failed', 'scheme'));
        self::assertStringContainsString(
            'sqlite',
            (string) $result->problem('db_connection_failed')['problem'],
        );
        // The driver's own words survive the redaction: an agent needs to know
        // the file could not be opened, not merely that something went wrong.
        self::assertStringContainsString(
            'unable to open database file',
            (string) $result->problem('db_connection_failed')['problem'],
        );
    }

    /**
     * The redaction, asserted end to end — on the WHOLE envelope rather than on
     * `context.dsn`.
     *
     * `DbConnectionFailedTest` covers the two shapes the redaction knows; what
     * only a real invocation can show is that nothing downstream puts the
     * unredacted DSN back: the problem's `context`, the `--json` envelope, the
     * `fix`, or any field added later. Asserting on the one field that is
     * redacted today is how this leaks again tomorrow.
     */
    public function testAConnectionFailureNeverPrintsTheCredentialItWasGiven(): void
    {
        $result = $this->lava(['db:status', '--json'], [
            'DATABASE_DSN' => 'sqlite:' . dirname($this->appDir) . '/no-such-dir/app.sqlite;password=hunter2',
        ]);

        self::assertSame(['db_connection_failed'], $result->codes());
        self::assertStringNotContainsString('hunter2', $result->stdout);
        self::assertStringNotContainsString('hunter2', $result->stderr);
        self::assertSame(
            'sqlite:' . dirname($this->appDir) . '/no-such-dir/app.sqlite;password=***',
            $result->context('db_connection_failed', 'dsn'),
        );
    }

    /**
     * `oops.php` is refused because the NAME is not one of ours — a migration
     * has to be `YYYY_MM_DD_HHMMSS_snake_case` for `db:status` to order it.
     */
    public function testABadlyNamedMigrationFileIsRefusedWithItsPath(): void
    {
        file_put_contents($this->appDir . '/app/Database/Migrations/oops.php', "<?php\n\nreturn 1;\n");

        $result = $this->lava(['db:status', '--json']);

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame(['invalid_migration_file'], $result->codes());
        self::assertSame(
            $this->appDir . '/app/Database/Migrations/oops.php',
            $result->context('invalid_migration_file', 'file'),
        );
        // The source is a real file and a real line, so an editor can jump to it.
        self::assertSame(
            $this->appDir . '/app/Database/Migrations/oops.php',
            $result->problem('invalid_migration_file')['source']['file'],
        );
    }

    /**
     * The OTHER half of "not a migration": the name is correct, and the file
     * returns the wrong thing. Both end in `invalid_migration_file` and they
     * are different mistakes with different fixes — one is "rename the file",
     * this one is "end the file with a returned Migration" — so both are
     * asserted through the real binary rather than inferred from a factory.
     */
    public function testAValidNameThatReturnsSomethingElseIsRefusedWithItsType(): void
    {
        file_put_contents(
            $this->appDir . '/app/Database/Migrations/2026_01_01_000002_returns_a_number.php',
            "<?php\n\nreturn 1;\n",
        );

        $result = $this->lava(['db:migrate', '--json']);

        self::assertSame(ExitCode::Failure, $result->exit);
        self::assertSame(['invalid_migration_file'], $result->codes());
        self::assertSame(
            $this->appDir . '/app/Database/Migrations/2026_01_01_000002_returns_a_number.php',
            $result->context('invalid_migration_file', 'file'),
        );
        // The type, not the value — a report is written to logs, and the value
        // could be anything the file chose to return.
        self::assertSame('int', $result->context('invalid_migration_file', 'returned'));
        self::assertStringContainsString(
            'return new class extends Migration',
            (string) $result->problem('invalid_migration_file')['fix'],
        );
    }

    /**
     * @param array<string, string|null> $env
     */
    private function lava(array $args, array $env = []): LavaResult
    {
        // array_merge, not `+`: the caller's values have to win, and `+` keeps
        // the left-hand operand — which silently ignored the `null` that
        // removes DATABASE_DSN, turning a not-configured test into a passing one.
        return LavaCli::run($args, $this->appDir, array_merge(
            ['DATABASE_DSN' => 'sqlite:' . $this->database],
            $env,
        ));
    }

    /**
     * The fixture app's own `up()`/`down()` body, wrapped in the same shape a
     * real migration has — so the test's subject stays the body, and the
     * fixture's own files stay untouched.
     */
    private function writeMigration(string $name, string $body): void
    {
        $path = $this->appDir . '/app/Database/Migrations/' . $name . '.php';

        file_put_contents($path, <<<PHP
        <?php

        declare(strict_types=1);

        use Lava\\Db\\Connection;
        use Lava\\Db\\Migration\\Migration;
        use Lava\\Db\\Schema\\Table;

        return new class extends Migration
        {
            public function up(Connection \$db): void
            {
        {$body}
            }

            public function down(Connection \$db): void
            {
                \$db->schema()->dropIfExists('things');
            }
        };

        PHP);
    }

    /**
     * The user tables in the database, read directly.
     *
     * Deliberately not through `db:status`: the repository's rows say what the
     * pack *believes*, and these tests are about whether the DDL landed. The
     * repository table and SQLite's own bookkeeping are excluded so the
     * assertion names the app's schema and nothing else.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $pdo = new \PDO('sqlite:' . $this->database);
        $rows = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        self::assertNotFalse($rows);

        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row['name'];
        }

        return $names;
    }

    /** @return list<string> */
    private static function migrationFiles(string $appDir): array
    {
        $files = scandir($appDir . '/app/Database/Migrations');
        self::assertNotFalse($files);

        return array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.php')));
    }

    private static function fixtureApp(): string
    {
        return dirname(__DIR__) . '/fixtures/apps/db-app';
    }

    /**
     * Whether the `lava` subprocess can open a SQLite database.
     *
     * Probed rather than assumed: the harness forwards the environment, so an
     * extension loaded through `PHP_INI_SCAN_DIR` reaches the child, while one
     * loaded through `-d extension=...` does not. Asking the child is the only
     * answer that is true in both cases.
     */
    private static function childHasSqlite(): bool
    {
        if (self::$childHasSqlite !== null) {
            return self::$childHasSqlite;
        }

        $process = proc_open(
            [PHP_BINARY, '-r', 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
        );
        self::assertIsResource($process, 'could not probe the child process for pdo_sqlite');

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return self::$childHasSqlite = proc_close($process) === 0;
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o755, true);

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;

            if (is_dir($source)) {
                self::copyTree($source, $target);
                continue;
            }

            copy($source, $target);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
