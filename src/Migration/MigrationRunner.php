<?php

declare(strict_types=1);

namespace Lava\Db\Migration;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;
use Lava\Db\Connection;
use Lava\Db\Problem\MigrationFailed;
use Lava\Db\Query\Operator;
use Lava\Db\Schema\Table;

/**
 * Applies and undoes migrations, and remembers which ones it has.
 *
 * **No transaction around a migration.** Wrapping the batch would look
 * tidier and would work on SQLite and PostgreSQL — but MySQL commits
 * implicitly on every DDL statement, so there the "rollback" would undo only
 * the repository rows while the tables stayed. The result would be a
 * migration recorded as unapplied but actually applied, and the next run
 * would fail on `table already exists`. That is worse than no transaction at
 * all, so instead each migration is recorded the moment it succeeds: a
 * failure leaves everything before it applied *and* recorded, and the run is
 * resumable by fixing the failing migration and running again.
 *
 * The repository is a table this pack creates with its own schema DSL, which
 * makes it the first thing to exercise the DSL against a real database — if
 * `lava db:migrate` works at all, the DSL works.
 */
final class MigrationRunner
{
    /** The table that records what has been applied. */
    public const TABLE = 'lava_migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly MigrationFiles $files,
    ) {
    }

    public function repositoryExists(): bool
    {
        return $this->db->schema()->has(self::TABLE);
    }

    /**
     * Creates the repository if it is not there.
     *
     * `has()` then `create()` rather than `CREATE TABLE IF NOT EXISTS`,
     * because the DSL has no `if not exists` and adding one to the compiler
     * for this single caller would be a feature the schema layer does not
     * otherwise offer. The window between the two calls is a race two
     * concurrent `db:migrate` runs could lose; migrations are a single-writer
     * operation for many other reasons, and losing that race produces
     * "table already exists", which says what happened.
     */
    public function ensureRepository(): void
    {
        if ($this->repositoryExists()) {
            return;
        }

        $this->db->schema()->create(self::TABLE, function (Table $t): void {
            // The name is the filename, which is unique by construction and
            // is what a human will grep for. It is the primary key rather
            // than a surrogate id so a double-apply is refused by the
            // database instead of silently inserting a second row.
            $t->string('name', 255)->primary();
            $t->int('batch');
            $t->string('applied_at', 32);
        });
    }

    /**
     * Every applied migration, oldest first.
     *
     * @return list<MigrationRecord>
     */
    public function applied(): array
    {
        if (!$this->repositoryExists()) {
            return [];
        }

        $quote = $this->db->dialect()->quote(...);
        $rows = $this->db->query(
            'SELECT ' . $quote('name') . ', ' . $quote('batch') . ', ' . $quote('applied_at')
            . ' FROM ' . $quote(self::TABLE)
            . ' ORDER BY ' . $quote('batch') . ', ' . $quote('name')
        );

        $records = [];
        foreach ($rows as $row) {
            $records[] = new MigrationRecord(
                (string) $row['name'],
                (int) $row['batch'],
                (string) $row['applied_at'],
            );
        }

        return $records;
    }

    /**
     * Migrations on disk that the repository has not recorded, in order.
     *
     * @return array<string, Migration>
     */
    public function pending(): array
    {
        $applied = [];
        foreach ($this->applied() as $record) {
            $applied[$record->name] = true;
        }

        $pending = [];
        foreach ($this->files->all() as $name => $migration) {
            if (!isset($applied[$name])) {
                $pending[$name] = $migration;
            }
        }

        return $pending;
    }

    /**
     * Migrations the repository says are applied but whose file is gone.
     *
     * Worth naming rather than ignoring: the schema carries a change nothing
     * can undo, and `db:rollback` will refuse to go past it. Reporting it in
     * `db:status` is how someone finds out before they need it.
     *
     * @return list<MigrationRecord>
     */
    public function orphaned(): array
    {
        $orphans = [];
        foreach ($this->applied() as $record) {
            if (!$this->files->has($record->name)) {
                $orphans[] = $record;
            }
        }

        return $orphans;
    }

    /** The highest batch number recorded, or 0 when nothing has been applied. */
    public function lastBatch(): int
    {
        $batch = 0;
        foreach ($this->applied() as $record) {
            $batch = max($batch, $record->batch);
        }

        return $batch;
    }

    /**
     * Applies every pending migration as one batch.
     *
     * `$onApplied` is called the moment each migration is both run and
     * recorded — not at the end. A run that stops part-way has still changed
     * the database, and the caller's report has to say so; the only moment
     * that fact is reliably knowable is here, because afterwards the caller
     * would have to re-query a database that may be exactly what is broken.
     *
     * @param null|\Closure(string, int): void $onApplied name, then batch
     * @return array{names: list<string>, batch: int} the migrations applied and
     *         the batch they went into — empty and the current batch when
     *         there was nothing to do
     * @throws LavaProblem
     */
    public function migrate(?\Closure $onApplied = null): array
    {
        $pending = $this->pending();

        // Nothing to do means nothing to create, either: a migrate that
        // applies no migrations should not leave a repository table behind
        // as the only trace that it ran.
        if ($pending === []) {
            return ['names' => [], 'batch' => $this->lastBatch()];
        }

        $this->ensureRepository();
        $batch = $this->lastBatch() + 1;

        $names = [];
        foreach ($pending as $name => $migration) {
            $this->run($name, $migration, 'up');
            $this->record($name, $batch);
            $names[] = $name;

            if ($onApplied !== null) {
                $onApplied($name, $batch);
            }
        }

        return ['names' => $names, 'batch' => $batch];
    }

    /**
     * Undoes the last `$batches` batches, newest migration first.
     *
     * Newest first within a batch matters: a batch is one run, and a run's
     * migrations may depend on each other in the order they were written, so
     * undoing them in the order they were applied would undo the dependency
     * before the thing that depends on it.
     *
     * `$onRolledBack` exists for the same reason `migrate()`'s callback does:
     * this loop can stop part-way — it refuses when a migration's file is gone
     * — and by then earlier migrations in the same batch are already undone.
     * The caller's report has to name them, and here is the only moment that
     * is knowable without re-querying a database that may be what is broken.
     *
     * @param null|\Closure(string, int): void $onRolledBack name, then batch
     * @return array{names: list<string>, batches: list<int>} the migrations
     *         undone and the batches they came from, highest first
     * @throws LavaProblem
     */
    public function rollback(int $batches = 1, ?\Closure $onRolledBack = null): array
    {
        if ($batches < 1) {
            $batches = 1;
        }

        $applied = $this->applied();
        if ($applied === []) {
            return ['names' => [], 'batches' => []];
        }

        $present = [];
        foreach ($applied as $record) {
            $present[$record->batch] = true;
        }
        $present = array_keys($present);
        rsort($present);
        $target = array_slice($present, 0, $batches);

        $selected = [];
        foreach ($applied as $record) {
            if (in_array($record->batch, $target, true)) {
                $selected[] = $record;
            }
        }

        // Descending by batch, then descending by name — the reverse of the
        // order they went in.
        usort($selected, static fn (MigrationRecord $a, MigrationRecord $b): int => [$b->batch, $b->name] <=> [$a->batch, $a->name]);

        $names = [];
        $done = [];
        foreach ($selected as $record) {
            // Refused rather than skipped. The repository says this change is
            // in the database and the file that knows how to undo it is gone;
            // carrying on would roll back the migrations around it and leave
            // the schema in a state no migration describes.
            if (!$this->files->has($record->name)) {
                throw MigrationFailed::missingFile($record->name, $this->files->directory(), $record->batch);
            }

            $this->run($record->name, $this->files->get($record->name), 'down');
            $this->forget($record->name);
            $names[] = $record->name;
            $done[$record->batch] = true;

            if ($onRolledBack !== null) {
                $onRolledBack($record->name, $record->batch);
            }
        }

        // Derived from what actually came off rather than from `$target`, so
        // that a run which stopped part-way reports the batches it touched
        // instead of the ones it aimed at.
        $touched = array_keys($done);
        rsort($touched);

        return ['names' => $names, 'batches' => $touched];
    }

    /**
     * @throws LavaProblem
     */
    private function run(string $name, Migration $migration, string $direction): void
    {
        try {
            if ($direction === 'up') {
                $migration->up($this->db);
            } else {
                $migration->down($this->db);
            }
        } catch (LavaProblem $problem) {
            // Already a diagnosis with its own fix — `bad_schema` naming the
            // column, `bad_query` naming the missing condition. Wrapping it
            // in "a migration failed" would replace the answer with the fact
            // that there is a question.
            throw $problem;
        } catch (\Throwable $previous) {
            throw MigrationFailed::of($name, $direction, $previous, $this->location($name, $migration, $direction));
        }
    }

    /**
     * Where to point the report: the migration method that was running.
     *
     * Not the throw site — that is often inside this pack, and the pack is
     * not what the reader has to change. `up()` or `down()` is the
     * user-authored thing at fault, and reflection gives its real line in the
     * file rather than a placeholder.
     */
    private function location(string $name, Migration $migration, string $direction): SourceLocation
    {
        $method = new \ReflectionMethod($migration, $direction);
        $line = $method->getStartLine();

        return SourceLocation::of($this->files->path($name), $line === false ? 1 : $line);
    }

    private function record(string $name, int $batch): void
    {
        $this->db->run($this->db->table(self::TABLE)->insert([
            'name' => $name,
            'batch' => $batch,
            'applied_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]));
    }

    private function forget(string $name): void
    {
        $this->db->run($this->db->table(self::TABLE)->where('name', Operator::Eq, $name)->delete());
    }
}
