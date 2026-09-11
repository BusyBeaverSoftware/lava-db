<?php

declare(strict_types=1);

namespace Lava\Db\Migration;

/**
 * One row of the migration repository: what was applied, when, and in which
 * run.
 *
 * `batch` is the unit of undo. Every `lava db:migrate` applies its migrations
 * as one batch, so `lava db:rollback` can undo "the last run" without the
 * caller having to remember how many migrations that was — which is the only
 * question anyone actually has when they want to go back.
 */
final readonly class MigrationRecord
{
    public function __construct(
        public string $name,
        public int $batch,
        public string $appliedAt,
    ) {
    }

    /** @return array{name: string, batch: int, applied_at: string} */
    public function json(): array
    {
        return ['name' => $this->name, 'batch' => $this->batch, 'applied_at' => $this->appliedAt];
    }
}
