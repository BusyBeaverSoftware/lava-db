<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;

/**
 * A migration threw while it was running.
 *
 * This is the `unexpected_failure` of the migration system, and it follows the
 * same rule: it wraps only throwables that are NOT already LavaProblems. A
 * `bad_schema` or a `bad_query` raised inside `up()` is already a precise
 * diagnosis with its own fix, so the runner lets it through untouched —
 * wrapping it would replace "column 'nmae' is not declared, here are the
 * columns" with "a migration failed".
 */
final class MigrationFailed extends LavaProblem
{
    public static function of(string $name, string $direction, \Throwable $previous, SourceLocation $source): self
    {
        return new self(
            "Migration '{$name}' threw while running {$direction}: " . $previous->getMessage(),
            "Fix the migration, then re-run: lava db:migrate --json. Everything applied before it stays applied and "
            . 'recorded, so the run resumes rather than starting over.',
            ['migration' => $name, 'direction' => $direction, 'exception' => $previous::class],
            $source,
            $previous,
        );
    }

    /**
     * The repository records a migration whose file is no longer there.
     *
     * Reported instead of skipped because there is no safe way to carry on:
     * the schema contains a change nothing can undo, so a rollback that
     * stepped over it would leave the database in a state no migration
     * describes.
     */
    public static function missingFile(string $name, string $directory, int $batch): self
    {
        $expected = $directory . DIRECTORY_SEPARATOR . $name . '.php';

        return new self(
            "The migration repository records '{$name}' (batch {$batch}) as applied, but its file is missing.",
            "Restore {$expected}, or if the change is no longer wanted, remove the row yourself: "
            . "DELETE FROM " . \Lava\Db\Migration\MigrationRunner::TABLE . " WHERE name = '{$name}' — then roll back "
            . 'the batches above it by hand.',
            ['migration' => $name, 'batch' => $batch, 'expected_file' => $expected],
        );
    }

    public function code(): string
    {
        return 'migration_failed';
    }
}
