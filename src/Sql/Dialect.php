<?php

declare(strict_types=1);

namespace Lava\Db\Sql;

use Lava\Db\Problem\BadSchema;
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
     * Doubling the quote is the whole of the escaping, and it is the same on
     * all three dialects. The backslash — which used to be doubled for MySQL
     * and left alone elsewhere — is refused instead, because whether it is an
     * escape character is a property of the SERVER (MySQL's default, and
     * PostgreSQL's `standard_conforming_strings`) and of the connection
     * charset, none of which the compiler can see. See {@see escapeString()}.
     */
    public function escapeString(string $value): string
    {
        // A backslash is refused rather than escaped, because escaping it is
        // exactly what is unsafe. Doubling `\` on MySQL turns `BF 5C` — one
        // character under a GBK/BIG5/SJIS connection charset — into that
        // character followed by a lone `\`, which escapes the closing quote and
        // lets the literal run on into the statement: the classic multibyte
        // break-out. On PostgreSQL the opposite assumption is baked in, since
        // not doubling is correct only while `standard_conforming_strings` is
        // on. Neither assumption is checkable from here, and a column default
        // holding a backslash is rare enough that refusing it costs a migration
        // one call to `defaultExpression()` (security review).
        if (str_contains($value, '\\')) {
            throw BadSchema::unsafeDefault($value);
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function wrap(string $identifier): string
    {
        $char = $this->quoteChar();
        return $char . str_replace($char, $char . $char, $identifier) . $char;
    }
}
