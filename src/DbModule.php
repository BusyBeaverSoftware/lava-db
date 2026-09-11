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
 * lava/db's entry point.
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
final class DbModule implements Module, ProvidesCommands
{
    public function pack(): PackInfo
    {
        return PackInfo::of(
            'lava/db',
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
