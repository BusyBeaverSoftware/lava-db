<?php

declare(strict_types=1);

namespace Lava\Db\Console;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava db:migrate` — applies every pending migration as one batch.
 *
 * One batch per run is what makes `db:rollback` able to undo "the last
 * deploy" without the caller counting migrations. The batch number is in the
 * payload because that is the argument the rollback will need if this run has
 * to be undone.
 */
final class DbMigrateCommand extends DbCommand
{
    public function name(): string
    {
        return 'db:migrate';
    }

    public function summary(): string
    {
        return 'Apply every pending migration as one batch.';
    }

    public function emptyPayload(Args $args): array
    {
        return ['applied' => [], 'applied_count' => 0, 'batch' => 0];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        /** @var list<string> $applied */
        $applied = [];
        $batch = 0;

        return $this->guarded(
            $io,
            function () use ($io, $app, &$applied, &$batch): void {
                $run = $this->runner($app)->migrate(
                    static function (string $name, int $batchNumber) use (&$applied, &$batch): void {
                        $applied[] = $name;
                        $batch = $batchNumber;
                    },
                );
                $applied = $run['names'];
                $batch = $run['batch'];

                if ($applied === []) {
                    $io->line('Nothing to migrate.');
                    return;
                }

                $io->line('Applied ' . count($applied) . ' migration(s) in batch ' . $batch . ':');
                $io->text((new Table(
                    ['migration'],
                    array_map(static fn (string $name): array => [$name], $applied),
                ))->render());
            },
            // Written outside the work so a run that stopped part-way still
            // reports what it applied before it stopped. `batch` is the batch
            // those rows went into, which is what `db:rollback` will need.
            function () use ($io, &$applied, &$batch): void {
                $io->data('applied', $applied);
                $io->data('applied_count', count($applied));
                $io->data('batch', $batch);
            },
        );
    }
}
