<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * PDO could not open the connection.
 *
 * Both the DSN and PDO's own message are redacted before they are reported:
 * a DSN may carry `password=…` inline, and some drivers echo the connection
 * string back inside their error text. A problem report is written to
 * terminals, log files, and CI output, so a credential that reaches it has
 * leaked — the redaction is applied to the driver's message as well as to the
 * DSN, because the driver's text is not ours to trust.
 */
final class DbConnectionFailed extends LavaProblem
{
    public static function of(string $dsn, \PDOException $previous): self
    {
        $scheme = strstr($dsn, ':', true);
        $scheme = $scheme === false ? '' : $scheme;

        return new self(
            "Could not connect to the {$scheme} database: " . self::redact($previous->getMessage()),
            'Check the DSN and that the database exists and is reachable. Credentials come from '
            . 'DATABASE_USER/DATABASE_PASSWORD or the same keys in config/database.php.',
            ['scheme' => $scheme, 'dsn' => self::redact($dsn)],
            null,
            $previous,
        );
    }

    /** Masks any inline credential before the text reaches a log or a terminal. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/(password|passwd|pwd)\s*=\s*[^;\s]*/i', '$1=***', $text);
    }

    public function code(): string
    {
        return 'db_connection_failed';
    }
}
