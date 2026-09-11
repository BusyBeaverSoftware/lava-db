<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;

/**
 * A file in app/Database/Migrations/ is not a migration.
 *
 * The convention is that the file *is* the migration: it returns an instance,
 * rather than declaring a class whose name has to be derived from the
 * filename. That removes the two things that make migration discovery
 * fragile elsewhere — parsing a class name out of a timestamp, and
 * instantiating a class by reflection — so what is left to get wrong is a
 * file that returns something else, which is what this problem reports.
 */
final class InvalidMigrationFile extends LavaProblem
{
    public static function notAMigration(string $file, string $returned): self
    {
        return new self(
            "The migration file {$file} returned {$returned} instead of a Migration.",
            'End the file with: return new class extends Migration { public function up(Connection $db): void {...} '
            . 'public function down(Connection $db): void {...} };',
            ['file' => $file, 'returned' => $returned],
            SourceLocation::of($file, 1),
        );
    }

    public static function badName(string $file): self
    {
        return new self(
            "The migration file {$file} is not named like a migration.",
            'Name it <YYYY_MM_DD_HHMMSS>_<snake_case_description>.php — the timestamp is what orders migrations. '
            . 'Run: lava db:new <Name> to generate one.',
            ['file' => $file],
            SourceLocation::of($file, 1),
        );
    }

    public static function threw(string $file, \Throwable $previous): self
    {
        return new self(
            "Loading the migration file {$file} threw: " . $previous->getMessage(),
            'The file must only declare and return a migration — no queries, no side effects at load time.',
            ['file' => $file, 'exception' => $previous::class],
            SourceLocation::of($file, 1),
            $previous,
        );
    }

    /**
     * `db:new` was asked to write a file that is already there.
     *
     * Refused rather than overwritten: the only way to collide is to generate
     * two migrations inside the same second, and the file that is already
     * there may be someone's half-written work. Losing it to a retry would be
     * the single worst thing this command could do.
     */
    public static function alreadyExists(string $file): self
    {
        return new self(
            "The migration file {$file} already exists.",
            'Wait a second so the timestamp advances, then run the same command again — or open the existing file '
            . 'and write the change there.',
            ['file' => $file],
            SourceLocation::of($file, 1),
        );
    }

    /**
     * The migrations directory could not be created, or the file could not be
     * written into it.
     *
     * Reported as a problem rather than letting PHP's warning and a bare
     * `false` escape, because the two things that cause it — a read-only
     * checkout and a permissions mistake — are fixed by different people.
     */
    public static function unwritable(string $path, bool $isDirectory): self
    {
        return new self(
            $isDirectory
                ? "Could not create the migrations directory {$path}."
                : "Could not write the migration file {$path}.",
            'Check that the app directory is writable: ls -ld ' . dirname($path) . '. If the checkout is '
            . 'read-only, make it writable or write the file yourself — the name only has to match '
            . '<YYYY_MM_DD_HHMMSS>_<snake_case_description>.php.',
            ['path' => $path, 'is_directory' => $isDirectory],
        );
    }

    public function code(): string
    {
        return 'invalid_migration_file';
    }
}
