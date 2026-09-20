<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * PDO could not open the connection.
 *
 * Both the DSN and PDO's own message are redacted before they are reported.
 * The DSN is the one that matters in practice — it is the field that carries a
 * credential, and it goes into `context.dsn` — while the driver's message is
 * redacted because we cannot know what a given driver will print. Neither
 * driver installed in this repository quotes the DSN back (pdo_sqlite says
 * `unable to open database file`, and an unusable scheme says `could not find
 * driver`), so that half is a posture rather than a response to an observed
 * leak: a problem report is written to terminals, log files, and CI output, and
 * the cost of being wrong about a third-party message is a password in a build
 * log. {@see self::redact()}.
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

    /**
     * Masks any inline credential before the text reaches a log or a terminal.
     *
     * Two shapes, because a credential hides in two places:
     *
     *  - `password=hunter2` — the key/value form, whether it is in the DSN or
     *    arrives inside the driver's own message;
     *  - `scheme://user:hunter2@host/db` — the URL form. There is no
     *    `password=` key to find in it, so a key/value match alone leaves it
     *    untouched. It is also the shape `DATABASE_URL=…` tends to have when it
     *    is carried over from another framework, and the shape PDO cannot open
     *    at all (`could not find driver`) — so the one report that carries it is
     *    a report nobody has ever seen succeed. That report is the `context`
     *    field, which is exactly where the credential would have been printed.
     *
     * The userinfo is masked up to the `@`, not the whole URL, so the host and
     * database stay legible — a redacted report that no longer says WHERE it
     * failed trades a leak for a useless error.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace('/(password|passwd|pwd|pass|secret)\s*=\s*[^;\s]*/i', '$1=***', $text);

        // Up to the LAST `@` before the path, not the first: a password may
        // contain one, and stopping at the first left everything after it —
        // `://user:p@ssw0rd@host` used to mask `p` and print the rest.
        return (string) preg_replace('#(://[^:/@\s]*:)[^\s/]*(?=@)#', '$1***', $text);
    }

    public function code(): string
    {
        return 'db_connection_failed';
    }
}
