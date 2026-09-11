<?php

declare(strict_types=1);

namespace Lava\Db\Console;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava db:status` — what is applied, what is pending, and what the repository
 * remembers that no longer has a file.
 *
 * Three lists rather than two, because the third is the one that bites:
 * a migration recorded as applied whose file has been deleted means the
 * schema contains a change nothing can undo, and `db:rollback` will refuse
 * to go past it. Reporting it here is how that is discovered before it
 * matters rather than during an incident.
 */
final class DbStatusCommand extends DbCommand
{
    public function name(): string
    {
        return 'db:status';
    }

    public function summary(): string
    {
        return 'Show applied and pending migrations, and any the repository remembers without a file.';
    }

    public function emptyPayload(Args $args): array
    {
        return ['applied' => [], 'pending' => [], 'orphaned' => [], 'batch' => 0, 'pending_count' => 0];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        return $this->guarded($io, function () use ($io, $app): void {
            $runner = $this->runner($app);
            $applied = $runner->applied();
            $pending = $runner->pending();
            $orphaned = $runner->orphaned();

            $io->data('applied', array_map(static fn ($record): array => $record->json(), $applied));
            $io->data('pending', array_keys($pending));
            $io->data('orphaned', array_map(static fn ($record): array => $record->json(), $orphaned));
            $io->data('batch', $runner->lastBatch());
            $io->data('pending_count', count($pending));

            $rows = [];
            foreach ($applied as $record) {
                $rows[] = [(string) $record->batch, $record->name, $record->appliedAt];
            }
            $io->line('Applied (' . count($applied) . '):');
            $io->text((new Table(['batch', 'migration', 'applied_at'], $rows))->render());

            $io->line('Pending (' . count($pending) . '):');
            $io->text((new Table(['migration'], array_map(static fn (string $n): array => [$n], array_keys($pending))))->render());

            if ($orphaned !== []) {
                $io->line('Orphaned (' . count($orphaned) . ') — recorded as applied, but the file is gone:');
                $io->text((new Table(
                    ['batch', 'migration'],
                    array_map(static fn ($r): array => [(string) $r->batch, $r->name], $orphaned),
                ))->render());
            }
        });
    }
}
