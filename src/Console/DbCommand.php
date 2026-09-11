<?php

declare(strict_types=1);

namespace Lava\Db\Console;

use Lava\Core\Boot\App;
use Lava\Core\Console\Commands\AppCommand;
use Lava\Core\Console\IO;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Db\Connection;
use Lava\Db\Migration\MigrationFiles;
use Lava\Db\Migration\MigrationRunner;

/**
 * The four `db:*` commands' common ground.
 *
 * Two things every one of them needs and none of them should re-derive: the
 * app's own {@see Connection}, and a {@see MigrationRunner} pointed at this
 * app's migrations directory.
 *
 * The connection comes from the container rather than being rebuilt from
 * config, deliberately. app/Services.php is allowed to replace it — with
 * driver options, a different password source, a proxy — and a command that
 * built its own would then be describing a database the app does not use.
 * A diagnostic that disagrees with the thing it diagnoses is worse than no
 * diagnostic.
 */
abstract class DbCommand extends AppCommand
{
    public function pack(): string
    {
        return 'db';
    }

    /**
     * @throws InvalidConfig when the container's Connection id holds something else
     */
    protected function connection(App $app): Connection
    {
        $service = $app->container->get(Connection::class);

        if (!$service instanceof Connection) {
            throw InvalidConfig::wrongService(Connection::class, Connection::class, $service);
        }

        return $service;
    }

    protected function runner(App $app): MigrationRunner
    {
        return new MigrationRunner($this->connection($app), MigrationFiles::inApp($app->appDir));
    }

    /**
     * Runs the body and turns any problem it raises into the command's report.
     *
     * Every failure a db command can hit — no DSN, an unreachable server, a
     * migration that threw — is already a LavaProblem with its own fix. This
     * keeps that fix intact instead of letting the exception escape as an
     * unhandled error, and keeps the four inspect() bodies free of try/catch.
     *
     * `$payload` writes the keys that are true whether or not the work
     * finished, and runs after it either way. It exists for `db:migrate`:
     * a run that stopped on its third migration has still applied two, and an
     * envelope saying `applied: []` next to a database with two new tables is
     * the one report an agent must not be given. Putting those writes here
     * rather than in `$work` is what makes them survive the failure.
     *
     * It emits, so an inspect() that calls it must return its result rather
     * than emitting again.
     */
    protected function guarded(IO $io, \Closure $work, ?\Closure $payload = null): int
    {
        $report = new ProblemReport();

        try {
            $work();
        } catch (LavaProblem $problem) {
            $report->add($problem);
        }

        if ($payload !== null) {
            $payload();
        }

        return $io->emit($this->name(), $report);
    }
}
