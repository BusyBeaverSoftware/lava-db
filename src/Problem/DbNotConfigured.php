<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * The db feature is on, but nothing told the pack where the database is.
 *
 * Raised on the first query rather than at boot, deliberately. Booting green
 * and failing on use is the right order here because the pack's CLI commands
 * are part of the diagnosis: `lava routes`, `lava services`, and `lava check`
 * all have to work on an app whose database is misconfigured — that is
 * exactly when someone is trying to find out why.
 */
final class DbNotConfigured extends LavaProblem
{
    public static function of(): self
    {
        return new self(
            'lava/db is enabled but no database DSN is configured, so there is nothing to connect to.',
            "Add DATABASE_DSN to config/.env (e.g. DATABASE_DSN=sqlite:" . DIRECTORY_SEPARATOR
            . "app.sqlite), or set the 'dsn' key in config/database.php.",
            ['looked_for' => ['DATABASE_DSN', 'config/database.php: dsn']],
        );
    }

    public function code(): string
    {
        return 'db_not_configured';
    }
}
