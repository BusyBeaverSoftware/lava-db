<?php

declare(strict_types=1);

namespace Lava\Db\Console;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Problem\BadUsage;
use Lava\Db\Migration\MigrationFiles;
use Lava\Db\Problem\InvalidMigrationFile;

/**
 * `lava db:new <description>` — writes a migration file with the next
 * timestamp.
 *
 * The name is the ordering, so the command owns it and the caller does not
 * type it: `<description>` becomes `<YYYY_MM_DD_HHMMSS>_<description>.php`,
 * and two people generating migrations on separate branches cannot collide on
 * a number they were both told to increment.
 *
 * CamelCase is accepted and converted (`CreateUsersTable` and
 * `create_users_table` produce the same filename), because both are things a
 * person types and neither is more correct than the other.
 *
 * The generated migration is a working one when the description follows the
 * `create_<table>_table` shape — it creates that table, and its `down()`
 * drops it, so the file is reversible the moment it is written. Any other
 * description gets a template whose body is a commented example and whose
 * `up()` is empty; the command says so on the way out rather than leaving a
 * migration that quietly does nothing to be discovered later.
 */
final class DbNewCommand extends DbCommand
{
    public function name(): string
    {
        return 'db:new';
    }

    public function summary(): string
    {
        return 'Write a new migration file with the next timestamp.';
    }

    public function arguments(): array
    {
        return ['description'];
    }

    public function usage(): string
    {
        return 'lava db:new <description> [--env=<name>] [--json]';
    }

    protected function usageProblem(Args $args): ?BadUsage
    {
        $raw = $args->arg(0);
        if ($raw === null) {
            return BadUsage::missing('description', $this->usage());
        }

        if (self::describe($raw) === null) {
            return BadUsage::invalidArgument(
                'description',
                $raw,
                "letters, digits and underscores, starting with a letter — e.g. create_users_table",
                $this->usage(),
            );
        }

        return null;
    }

    public function emptyPayload(Args $args): array
    {
        return ['name' => null, 'file' => null, 'table' => null, 'is_empty' => true];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        return $this->guarded($io, function () use ($io, $args, $app): void {
            $description = self::describe((string) $args->arg(0));
            if ($description === null) {
                return; // usageProblem already refused this; unreachable in practice
            }

            $files = MigrationFiles::inApp($app->appDir);
            $name = self::nextStamp($files->directory(), new \DateTimeImmutable()) . '_' . $description;
            $path = $files->directory() . DIRECTORY_SEPARATOR . $name . '.php';

            if (is_file($path)) {
                throw InvalidMigrationFile::alreadyExists($path);
            }

            $table = self::tableFor($description);

            $directory = $files->directory();
            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw InvalidMigrationFile::unwritable($directory, true);
            }

            // The `@` suppresses PHP's warning, not the failure: the return
            // value is checked, and the problem below carries the path, the
            // cause, and the fix. Letting the warning through as well would
            // print the same fact twice, once without a fix.
            if (@file_put_contents($path, self::template($table)) === false) {
                throw InvalidMigrationFile::unwritable($path, false);
            }

            $io->data('name', $name);
            $io->data('file', $path);
            $io->data('table', $table);
            $io->data('is_empty', $table === null);

            $io->line('Created ' . $path);
            if ($table === null) {
                $io->line('The migration is empty — open it and describe the change.');
            } else {
                $io->line("It creates the table '{$table}' and drops it in down().");
            }
        });
    }

    /**
     * The timestamp for a new migration: now, or one second after the newest
     * migration already in `$directory`, whichever is later.
     *
     * The filename is the order, so two migrations generated inside one second
     * must not share a stamp: `create_monitors_table` then `create_checks_table`
     * would sort `checks` first by its description, and a foreign key to a table
     * that does not exist yet is an error on MySQL and PostgreSQL that SQLite
     * never raises. Stepping past the newest stamp keeps generation order as run
     * order — including when this machine's clock is behind a stamp someone else
     * already committed.
     *
     * Read from the file names alone. Loading every migration to find the newest
     * would make `db:new` fail on a half-written one, which is exactly the file
     * someone is likely to have open.
     */
    private static function nextStamp(string $directory, \DateTimeImmutable $now): string
    {
        // Whole seconds: the stamp has no fraction, so a `now` carrying
        // microseconds would compare later than a stamp from the same second.
        $next = \DateTimeImmutable::createFromFormat('!Y_m_d_His', $now->format('Y_m_d_His')) ?: $now;

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $match) !== 1) {
                continue;
            }
            $stamp = \DateTimeImmutable::createFromFormat('!Y_m_d_His', $match[1]);
            if ($stamp !== false && $stamp >= $next) {
                $next = $stamp->add(new \DateInterval('PT1S'));
            }
        }

        return $next->format('Y_m_d_His');
    }

    /**
     * The snake_case description, or null when the input cannot become one.
     *
     * CamelCase is split on its capitals before the check, so `CreateUsersTable`
     * and `create_users_table` both land on `create_users_table`.
     */
    private static function describe(string $raw): ?string
    {
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', trim($raw)));

        return preg_match('/^[a-z][a-z0-9_]*$/D', $snake) === 1 ? $snake : null;
    }

    /**
     * The table a `create_<table>_table` description names, or null for any
     * other shape.
     *
     * Only this one shape is understood. `add_avatar_to_users` could be read
     * as "add a column to users", but guessing which column, of which type,
     * with which default is how a generator writes a migration nobody asked
     * for — and a wrong table name is worse than an empty template, because
     * it looks finished.
     */
    private static function tableFor(string $description): ?string
    {
        if (preg_match('/^create_([a-z][a-z0-9_]*)_table$/D', $description, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private static function template(?string $table): string
    {
        $body = $table === null
            ? <<<'PHP'
                    // Describe the change. For example:
                    //
                    //     $db->schema()->create('users', function (Table $t): void {
                    //         $t->id();
                    //         $t->string('email')->unique();
                    //         $t->timestamps();
                    //     });
                    //
                    //     $db->schema()->table('users', function (Table $t): void {
                    //         $t->string('nickname')->nullable();
                    //     });
                    //
                    // Or, for anything the DSL cannot express:
                    //
                    //     $db->statement('ALTER TABLE "users" ADD CONSTRAINT …');
            PHP
            : <<<PHP
                    \$db->schema()->create('{$table}', function (Table \$t): void {
                        \$t->id();
                        \$t->timestamps();
                    });
            PHP;

        $undo = $table === null
            ? <<<'PHP'
                    // Undo what up() did. An empty body means the change cannot
                    // be undone, which is a statement worth being deliberate about.
            PHP
            : <<<PHP
                    \$db->schema()->dropIfExists('{$table}');
            PHP;

        return <<<PHP
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
        {$undo}
            }
        };

        PHP;
    }
}
