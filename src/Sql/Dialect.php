<?php

declare(strict_types=1);

namespace Lava\Db\Sql;

use Lava\Db\Problem\UnsupportedDialect;

/**
 * How a dialect *names* things — and the one place a query's shape differs
 * between databases. Everything about declaring a table (types,
 * auto-increment, index syntax) lives in {@see SchemaCompiler}, because those
 * are decisions about a statement rather than about a name, and splitting
 * them across two files made neither readable.
 *
 * Quoting is not cosmetic. `order`, `key`, and `groups` are reserved in one
 * or more of these three, and an identifier that collides is a syntax error
 * at the worst possible moment — inside a migration, halfway through. So
 * every identifier the compiler emits goes through {@see quote()}, and the
 * one escape hatch is {@see \Lava\Db\Query\Condition::raw()}, which is
 * explicitly the caller's own SQL.
 */
enum Dialect: string
{
    case Sqlite = 'sqlite';
    case Mysql = 'mysql';
    case Pgsql = 'pgsql';

    /**
     * The dialect a PDO DSN targets. The scheme is everything before the
     * first colon: `mysql:host=…`, `sqlite:/var/app.sqlite`.
     *
     * @throws UnsupportedDialect
     */
    public static function fromDsn(string $dsn): self
    {
        $scheme = strstr($dsn, ':', true);
        $scheme = $scheme === false ? '' : strtolower($scheme);
        return self::tryFrom($scheme) ?? throw UnsupportedDialect::of(
            $scheme,
            array_map(static fn (self $d): string => $d->value, self::cases()),
        );
    }

    /**
     * Quotes an identifier, or a dotted path of them: `users.id` becomes
     * `"users"."id"`. A `*` segment is left bare, so `select('users.*')`
     * compiles to `"users".*` rather than the invalid `"users"."*"`.
     *
     * An identifier containing the quote character has it doubled — the
     * standard escape — rather than being rejected, so no input can end the
     * quoted run early and inject SQL.
     */
    public function quote(string $identifier): string
    {
        return implode('.', array_map(
            fn (string $segment): string => $segment === '*' ? '*' : $this->wrap($segment),
            explode('.', $identifier),
        ));
    }

    /** The character this dialect wraps identifiers in. */
    public function quoteChar(): string
    {
        return $this === self::Mysql ? '`' : '"';
    }

    /**
     * The LIMIT/OFFSET clause, including its leading space, or '' when
     * neither is set.
     *
     * This is a dialect method rather than a compiler detail because
     * `OFFSET` without `LIMIT` is not portable: PostgreSQL accepts it,
     * SQLite and MySQL do not, and each spells the "no cap" limit
     * differently (`-1` and a 64-bit maximum respectively). Getting this
     * wrong produces SQL that works on the developer's PostgreSQL and fails
     * in the app's SQLite.
     */
    public function limitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }
        if ($offset === null) {
            return ' LIMIT ' . $limit;
        }
        $cap = match (true) {
            $limit !== null => (string) $limit,
            $this === self::Sqlite => '-1',
            $this === self::Mysql => '18446744073709551615',
            default => '',
        };
        $clause = $cap === '' ? '' : ' LIMIT ' . $cap;
        return $clause . ' OFFSET ' . $offset;
    }

    /**
     * Renders a string as a SQL literal.
     *
     * This is the ONLY place in the pack where a value becomes SQL text, and
     * it exists because DDL cannot be parameterized: `DEFAULT ?` is not a
     * thing, so a column default has to be spelled out. Everything else goes
     * through a placeholder.
     *
     * The escaping is dialect-specific rather than universal because the two
     * rules disagree. Standard SQL (SQLite, PostgreSQL with the default
     * `standard_conforming_strings`) treats a backslash as an ordinary
     * character, so doubling the quote is the complete answer. MySQL instead
     * treats backslash as an escape character by default, so a lone trailing
     * backslash would escape the closing quote and let the rest of the
     * string become SQL — there, backslashes must be doubled too.
     */
    public function escapeString(string $value): string
    {
        $escaped = $this === self::Mysql
            ? str_replace(['\\', "'"], ['\\\\', "''"], $value)
            : str_replace("'", "''", $value);

        return "'" . $escaped . "'";
    }

    private function wrap(string $identifier): string
    {
        $char = $this->quoteChar();
        return $char . str_replace($char, $char . $char, $identifier) . $char;
    }
}
