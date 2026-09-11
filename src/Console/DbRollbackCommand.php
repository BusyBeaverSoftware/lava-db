<?php

declare(strict_types=1);

namespace Lava\Db\Console;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Core\Problem\BadUsage;

/**
 * `lava db:rollback [--batches=N]` — undoes the last N batches.
 *
 * The unit is the batch, not the migration, because the batch is what a run
 * produced: "undo the last deploy" is the question, and it has an answer that
 * does not depend on remembering how many migrations that deploy contained.
 * `--batches` is named for what it counts — `--steps` would invite the
 * reading that it counts migrations, which is a different operation and one
 * this command does not offer.
 */
final class DbRollbackCommand extends DbCommand
{
    public function name(): string
    {
        return 'db:rollback';
    }

    public function summary(): string
    {
        return 'Undo the last batch of migrations (or the last N with --batches).';
    }

    public function flags(): array
    {
        return ['json', 'env', 'batches'];
    }

    public function usage(): string
    {
        return 'lava db:rollback [--batches=<n>] [--env=<name>] [--json]';
    }

    protected function usageProblem(Args $args): ?BadUsage
    {
        $raw = $args->value('batches');
        if ($raw === null) {
            return null;
        }

        // Refused rather than coerced. `--batches=all` becoming 0 (or 1, or
        // PHP_INT_MAX) would silently pick a different amount of undo than
        // the caller asked for, on the one operation where that is hardest to
        // notice and most expensive to get wrong.
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            return BadUsage::invalid('batches', $raw, 'a positive whole number, e.g. --batches=2', $this->usage());
        }

        return null;
    }

    protected function emptyPayload(Args $args): array
    {
        return ['rolled_back' => [], 'rolled_back_count' => 0, 'batches' => []];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $batches = (int) ($args->value('batches') ?? '1');

        /** @var list<string> $rolledBack */
        $rolledBack = [];
        /** @var list<int> $touched */
        $touched = [];

        return $this->guarded(
            $io,
            function () use ($io, $app, $batches, &$rolledBack, &$touched): void {
                $run = $this->runner($app)->rollback(
                    $batches,
                    static function (string $name, int $batch) use (&$rolledBack, &$touched): void {
                        $rolledBack[] = $name;
                        $touched[] = $batch;
                    },
                );
                $rolledBack = $run['names'];
                $touched = $run['batches'];

                if ($rolledBack === []) {
                    $io->line('Nothing to roll back.');
                    return;
                }

                $io->line('Rolled back ' . count($rolledBack) . ' migration(s) from batch '
                    . implode(', ', array_map(strval(...), array_unique($touched))) . ':');
                $io->text((new Table(
                    ['migration'],
                    array_map(static fn (string $name): array => [$name], $rolledBack),
                ))->render());
            },
            // Outside the work, so a run refused part-way still names the
            // migrations it had already undone. Claiming `rolled_back: []`
            // after the database has lost a table is the report an agent
            // must not be given.
            function () use ($io, &$rolledBack, &$touched): void {
                $io->data('rolled_back', $rolledBack);
                $io->data('rolled_back_count', count($rolledBack));
                $io->data('batches', array_values(array_unique($touched)));
            },
        );
    }
}
