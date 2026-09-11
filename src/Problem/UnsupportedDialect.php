<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * The DSN names a database this pack does not compile for.
 *
 * Only the SCHEME is reported, never the whole DSN: a DSN can carry a
 * password (`pgsql:host=…;password=…` is a legal spelling), and a problem
 * report is written to logs, terminals, and CI output. The scheme is the
 * only part of the string that is a diagnosis rather than a secret.
 */
final class UnsupportedDialect extends LavaProblem
{
    /** @param list<string> $supported */
    public static function of(string $scheme, array $supported): self
    {
        $readable = $scheme === '' ? '(none)' : "'{$scheme}'";
        return new self(
            "Unsupported database scheme {$readable}: lava/db compiles for " . implode(', ', $supported) . '.',
            "Set DATABASE_DSN (or the 'dsn' key of config/database.php) to 'sqlite:" . DIRECTORY_SEPARATOR
            . "path/to/app.sqlite', 'mysql:host=127.0.0.1;dbname=app', or 'pgsql:host=127.0.0.1;dbname=app'.",
            ['scheme' => $scheme, 'supported' => $supported],
        );
    }

    public function code(): string
    {
        return 'unsupported_dialect';
    }
}
