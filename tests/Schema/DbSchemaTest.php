<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Schema;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\EnvelopeSchemas;
use Lava\Core\Tests\Support\LavaCli;
use Lava\Core\Tests\Support\LavaResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every `db:*` envelope, validated against the contract it claims.
 *
 * `JsonSchemaTest` does this for the core commands, but it runs them in an app
 * that enables no packs — so a pack's payloads would ship with a `schema` field
 * naming a file nobody validates. This is that validation, in the package that
 * owns the commands, which is also why it can reach the fixture app that
 * enables lava/db at all.
 *
 * It is the strong half of the pair: the core test lists the four pack schemas
 * by name so that `docs/schemas/` and the command set agree, and this test
 * asserts the same set in the other direction — a schema file for a command
 * that no longer exists, or a command whose schema file was never written,
 * fails here.
 */
final class DbSchemaTest extends TestCase
{
    /** The app that enables lava/db and has migrations to report on. */
    private static function app(): string
    {
        return dirname(__DIR__) . '/fixtures/apps/db-app';
    }

    /**
     * One entry per invocation. Every one is a FAILED or empty run on purpose:
     * a fresh database has nothing applied, so the interesting payloads here
     * are the ones with empty lists and the ones carrying a problem — and the
     * envelope promises its `data` keys on every exit path.
     *
     * @return array<string, array{list<string>}>
     */
    public static function invocations(): array
    {
        return [
            // No DATABASE_DSN: the app boots, the pack is enabled, and the
            // command fails with a diagnosis instead of an empty payload.
            'status without a database' => [['db:status', '--json']],
            'migrate without a database' => [['db:migrate', '--json']],
            'rollback without a database' => [['db:rollback', '--json']],
            // A usage error: the payload is seeded before the check, so the
            // shape has to hold even though nothing was attempted.
            'new with an unusable description' => [['db:new', '123 bad', '--json']],
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('invocations')]
    public function testTheEnvelopeObeysTheSchemaItClaims(array $args): void
    {
        $result = LavaCli::run($args, self::app(), ['DATABASE_DSN' => null]);

        EnvelopeSchemas::assertObeys($result, '`lava ' . implode(' ', $args) . '`');
    }

    public function testASuccessfulStatusObeysTheSchemaToo(): void
    {
        // The empty-list shape is checked above; this is the populated one —
        // applied records, a batch number, and a null problem list — which is
        // the payload an agent parses when things are working.
        $result = $this->withDatabase();

        self::assertSame(ExitCode::Ok, $result->exit, $result->stderr);
        EnvelopeSchemas::assertObeys($result, '`lava db:status --json` with migrations applied');

        $data = $result->data();
        self::assertNotSame([], $data['applied']);
        self::assertIsInt($data['batch']);
    }

    public function testTheSchemaNameIsFilenameSafe(): void
    {
        // `db:status`'s contract is `lava.db.status/1`, not `lava.db:status/1`.
        // The name is also a path under docs/schemas/, and a colon is illegal
        // there on Windows — so a colon would make the repository uncheckoutable
        // for a whole platform. The command itself keeps its colon, because
        // that is what an agent types.
        $result = LavaCli::run(['db:status', '--json'], self::app(), ['DATABASE_DSN' => null]);

        self::assertSame('lava.db.status/1', $result->schema());
        self::assertSame('db:status', $result->envelope()['command']);
    }

    public function testEveryDbCommandIsDocumentedAndNothingElseIs(): void
    {
        // Both directions. `lava list` in this app reports the four commands
        // this pack registers, and the schemas they claim must be exactly the
        // pack's schemas in docs/schemas/ — no missing file, no straggler for a
        // command that was renamed or removed.
        $listed = LavaCli::run(['list', '--json'], self::app(), ['DATABASE_DSN' => null]);

        $names = [];
        foreach ($listed->data()['commands'] as $command) {
            self::assertIsArray($command);
            if (($command['pack'] ?? null) === 'db') {
                $names[] = (string) $command['name'];
            }
        }
        sort($names);
        self::assertSame(['db:migrate', 'db:new', 'db:rollback', 'db:status'], $names);

        $expected = array_map(
            static fn (string $name): string => 'lava.' . str_replace(':', '.', $name) . '/1',
            $names,
        );

        $documented = array_values(array_filter(
            EnvelopeSchemas::schemaNames(),
            static fn (string $schema): bool => str_starts_with($schema, 'lava.db.'),
        ));

        sort($expected);
        sort($documented);
        self::assertSame($expected, $documented, 'docs/schemas/ and the db command set disagree');

        foreach ($expected as $schema) {
            self::assertFileExists(EnvelopeSchemas::file($schema), "{$schema} is claimed but not documented");
        }
    }

    /** A `db:status` run against a real, migrated SQLite database. */
    private function withDatabase(): LavaResult
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not loaded, so there is no database to report on.');
        }

        $database = sys_get_temp_dir() . '/lava-db-schema-' . bin2hex(random_bytes(6)) . '.sqlite';
        $env = ['DATABASE_DSN' => 'sqlite:' . $database];

        try {
            $migrate = LavaCli::run(['db:migrate', '--json'], self::app(), $env);
            self::assertSame(ExitCode::Ok, $migrate->exit, $migrate->stderr);

            return LavaCli::run(['db:status', '--json'], self::app(), $env);
        } finally {
            if (is_file($database)) {
                unlink($database);
            }
        }
    }
}
