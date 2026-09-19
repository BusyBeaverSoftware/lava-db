<?php

declare(strict_types=1);

namespace Lava\Db;

use Lava\Core\Boot\AppContext;
use Lava\Core\Config\ProcessEnv;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Container\Container;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Modules\ProvidesCommands;
use Lava\Db\Console\DbMigrateCommand;
use Lava\Db\Console\DbNewCommand;
use Lava\Db\Console\DbRollbackCommand;
use Lava\Db\Console\DbStatusCommand;

/**
 * lavaphp/db's entry point.
 *
 * The pack registers exactly one service and four commands. The service is
 * the {@see Connection}, registered as a singleton and built from settings
 * read at boot — not on first use — because a factory that reads config when
 * it runs is a factory whose result depends on when it ran.
 *
 * **The connection is built without connecting.** Its constructor stores a
 * DSN; nothing talks to a driver until the first query. That is what lets
 * `lava db:status` explain an unreachable database instead of failing to
 * exist, and it is why the pack can be enabled on an app whose database has
 * not been created yet.
 *
 * The settings come from three places, in order: the real environment (which
 * by boot time includes anything `config/.env` promoted), then
 * `config/database.php`. A DSN in the environment beats one in the file,
 * because the environment is what a deploy changes.
 */
final class DbModule implements Module, ProvidesCommands, \Lava\Core\Modules\ProvidesFacts
{
    public function pack(): PackInfo
    {
        return PackInfo::of(
            'lavaphp/db',
            'db',
            configFiles: ['database'],
            envVars: ['DATABASE_DSN', 'DATABASE_USER', 'DATABASE_PASSWORD'],
        );
    }

    public function register(Container $container, AppContext $ctx): void
    {
        $container->singleton(Connection::class, static fn (): Connection => new Connection(
            self::setting($ctx, 'DATABASE_DSN', 'dsn'),
            self::setting($ctx, 'DATABASE_USER', 'user'),
            self::setting($ctx, 'DATABASE_PASSWORD', 'password'),
        ));
    }

    public function commands(CommandRegistry $registry): void
    {
        $registry->add(new DbStatusCommand());
        $registry->add(new DbMigrateCommand());
        $registry->add(new DbRollbackCommand());
        $registry->add(new DbNewCommand());
    }

    /**
     * How many migrations are waiting, for `lava about` and any page that reads
     * {@see \Lava\Core\Boot\RuntimeFacts} (Lava Notes, R3-G4).
     *
     * It is the one fact about a database worth having beside the PHP facts:
     * "the code is deployed and the schema is not" explains a class of failure
     * that no other command reports unless someone thinks to run `db:status`.
     * The count, not the names — `db:status` lists those, and facts printed
     * beside every other pack's should stay one line.
     *
     * This queries, which is why {@see \Lava\Core\Modules\ProvidesFacts} is
     * asked when the facts are read rather than at boot. An unreachable or
     * unconfigured database raises its own LavaProblem here, and `RuntimeFacts`
     * records it as an `error` fact: `about` still reports everything else,
     * which is the whole point of the command.
     *
     * Every core and pack class this method names is written out in full,
     * deliberately: an import would move the `Connection` registration below,
     * and every committed AGENTS.md records that line.
     *
     * @return array<string, mixed>
     */
    public function facts(\Lava\Core\Boot\App $app): array
    {
        $connection = $app->container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw \Lava\Core\Problem\InvalidConfig::wrongService(Connection::class, Connection::class, $connection);
        }

        $runner = new \Lava\Db\Migration\MigrationRunner(
            $connection,
            \Lava\Db\Migration\MigrationFiles::inApp($app->appDir),
        );

        return ['pending_migrations' => count($runner->pending())];
    }

    /**
     * One setting, from the environment first and the config file second.
     *
     * An empty string counts as unset in both places. `DATABASE_DSN=` is how
     * a deploy template spells "leave this blank", and treating it as a DSN
     * would produce "the scheme '' is not supported" — a diagnosis of the
     * wrong problem.
     */
    private static function setting(AppContext $ctx, string $envVar, string $configKey): ?string
    {
        $fromEnv = ProcessEnv::real($envVar);
        if ($fromEnv !== null && $fromEnv !== '') {
            return $fromEnv;
        }

        $fromFile = $ctx->config->string("database.{$configKey}", '');

        return $fromFile === '' ? null : $fromFile;
    }
}
