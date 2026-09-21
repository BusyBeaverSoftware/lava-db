<?php

declare(strict_types=1);

namespace Lava\Db;

use Lava\Db\Problem\BadQuery;
use Lava\Db\Problem\DbConnectionFailed;
use Lava\Db\Problem\DbNotConfigured;
use Lava\Db\Problem\QueryFailed;
use Lava\Db\Query\QueryBuilder;
use Lava\Db\Query\SelectQuery;
use Lava\Db\Query\Statement;
use Lava\Db\Schema\Schema;
use Lava\Db\Sql\Compiled;
use Lava\Db\Sql\Compiler;
use Lava\Db\Sql\Dialect;

/**
 * The PDO edge: the only class in the pack that talks to a driver.
 *
 * **Connect on first query.** The constructor stores a DSN and nothing else.
 * That ordering is deliberate: an app with a broken or absent database must
 * still boot, because `lava routes`, `lava services`, `lava env`, and
 * `lava check` are how someone finds out what is wrong — a pack that refused
 * to boot without a live database would take away the tools needed to fix it.
 * The first real query raises `db_not_configured` or `db_connection_failed`
 * with the fix.
 *
 * **Everything is prepared, never interpolated.** Reads and writes both go
 * through `prepare()` and a binding list, so there is no code path here that
 * builds SQL from a value. The escape hatches (`query()` and `statement()`)
 * exist for statements the builder cannot express — a PRAGMA, an
 * `information_schema` lookup — and they take bindings too.
 *
 * The verbs are split by intent rather than by SQL verb: `fetch` returns
 * rows, `run` returns a row count and refuses a SELECT, `execute` takes an
 * already-compiled statement. A caller never has to know which PDO method a
 * shape maps to.
 */
final class Connection
{
    private ?\PDO $pdo = null;

    /** @var array<int, mixed> */
    private array $options;

    /**
     * @param string|null $dsn a PDO DSN; null means "not configured yet", which is
     *                         reported on first use rather than here
     * @param array<int, mixed> $options PDO driver options, merged over the defaults
     */
    public function __construct(
        private readonly ?string $dsn = null,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        array $options = [],
    ) {
        $this->options = $options;
    }

    /**
     * The options a connection is opened with: the app's, over the pack's
     * defaults, with one key the app does not get to set.
     *
     * Emulated prepares rewrite placeholders into literals inside the driver,
     * which is the one way a bound value can still end up as SQL text. "Off,
     * always" has to mean it, and it did not: options are merged with `+`,
     * which keeps the LEFT key, so an app that passed
     * `ATTR_EMULATE_PREPARES => true` — the line copied out of a Laravel or
     * Doctrine snippet for MySQL buffering — silently turned off the guarantee
     * this pack states as its own (security review). Everything else stays the
     * app's to choose, including the error mode and the fetch mode.
     *
     * A pure function so the rule can be asserted without a driver: pdo_sqlite
     * refuses to report this attribute at all.
     *
     * @param array<int, mixed> $options the app's driver options
     * @return array<int, mixed>
     */
    public static function driverOptions(array $options): array
    {
        $merged = $options + [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        $merged[\PDO::ATTR_EMULATE_PREPARES] = false;

        return $merged;
    }

    /** The dialect this connection's DSN targets. */
    public function dialect(): Dialect
    {
        return Dialect::fromDsn($this->requireDsn());
    }

    /**
     * The live PDO handle, opening it if this is the first call.
     *
     * The dialect is resolved BEFORE the driver is asked to connect, because
     * an unsupported scheme produces PDO's "could not find driver", which
     * names neither the problem nor the fix. Checking first turns a typo'd
     * scheme into a diagnosis.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = $this->requireDsn();
        $dialect = Dialect::fromDsn($dsn);

        try {
            $pdo = new \PDO($dsn, $this->user, $this->password, self::driverOptions($this->options));
        } catch (\PDOException $previous) {
            throw DbConnectionFailed::of($dsn, $previous);
        }

        if ($dialect === Dialect::Sqlite) {
            // SQLite does not enforce foreign keys unless asked, and the
            // default is off for backwards compatibility. A schema full of
            // REFERENCES clauses that nothing enforces is worse than one
            // without them, because the constraint is believed.
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $this->pdo = $pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Drops the handle. The next query reconnects.
     *
     * Note for tests: with an in-memory SQLite DSN each connection is a
     * separate, empty database, so a test must reuse one Connection instance
     * rather than close and reopen it.
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    public function compiler(): Compiler
    {
        return new Compiler($this->dialect());
    }

    /** A fresh builder for a table. The builder is pure; nothing is executed until a verb is called. */
    public function table(string $name): QueryBuilder
    {
        return new QueryBuilder($name);
    }

    /** The schema DSL, bound to this connection. */
    public function schema(): Schema
    {
        return new Schema($this);
    }

    /**
     * @return list<array<string, mixed>>
     * @throws QueryFailed
     */
    public function fetch(Statement $statement): array
    {
        return $this->fetchCompiled($this->compiler()->compile($statement->query()));
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(Statement $statement): ?array
    {
        return $this->fetch($statement)[0] ?? null;
    }

    /** The first column of the first row, or null when the query returned nothing. */
    public function scalar(Statement $statement): mixed
    {
        $row = $this->fetchOne($statement);

        return $row === null ? null : (array_values($row)[0] ?? null);
    }

    /**
     * How many rows a SELECT returns: what `count($db->fetch($query))` would
     * say, limit and offset included, without fetching them.
     *
     * `select('COUNT(*)')` is refused (DECISIONS 264), and this keeps the
     * commonest aggregate out of raw SQL. The others — `SUM`, `MAX`, a
     * `GROUP BY` — still go through query().
     *
     * @throws BadQuery when the statement is a write
     * @throws QueryFailed
     */
    public function count(Statement $statement): int
    {
        $query = $statement->query();
        if (!$query instanceof SelectQuery) {
            throw BadQuery::writeInRead('count');
        }

        // An int from SQLite's and PostgreSQL's drivers, a numeric string from MySQL's.
        $count = $this->fetchCompiled($this->compiler()->count($query))[0]['count'] ?? 0;
        if (is_int($count)) {
            return $count;
        }
        if (is_string($count) && ctype_digit($count)) {
            return (int) $count;
        }

        throw new \UnexpectedValueException('COUNT(*) came back as ' . get_debug_type($count) . ', not a whole number.');
    }

    /**
     * Executes a write and returns the number of affected rows.
     *
     * A SELECT is refused rather than executed: `PDO::rowCount()` on a SELECT
     * is driver-dependent — MySQL reports the row count, SQLite reports 0 —
     * so silently allowing it would make `run()` mean different things on
     * different databases.
     */
    public function run(Statement $statement): int
    {
        $query = $statement->query();
        if ($query instanceof SelectQuery) {
            throw BadQuery::readInWrite('run');
        }

        return $this->execute($this->compiler()->compile($query));
    }

    /**
     * Executes an already-compiled statement. The path DDL and migrations
     * take, where there is no Query to compile.
     */
    public function execute(Compiled $statement): int
    {
        $pdo = $this->pdo();

        try {
            $prepared = $pdo->prepare($statement->sql);
            $prepared->execute($statement->bindings);

            return $prepared->rowCount();
        } catch (\PDOException $previous) {
            throw QueryFailed::of($statement, $previous);
        }
    }

    /**
     * The raw read escape hatch, with bindings.
     *
     * @param list<int|float|string|null> $bindings
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $bindings = []): array
    {
        return $this->fetchCompiled(new Compiled($sql, $bindings));
    }

    /**
     * The raw write escape hatch, with bindings.
     *
     * @param list<int|float|string|null> $bindings
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->execute(new Compiled($sql, $bindings));
    }

    /**
     * Runs `$work` inside a transaction and returns whatever it returns.
     *
     * Nesting does NOT create a savepoint: a `transaction()` inside another
     * joins the outer one, so an inner failure rolls back the whole thing.
     * That is the honest behaviour of a layer with no savepoint support, and
     * it is stated rather than discovered.
     *
     * `$work` must not end the transaction itself — commit, roll back, or
     * close the handle. This method opened the transaction and is the only
     * thing that closes it; a closure that commits first leaves nothing for
     * the `commit()` below, and PDO's "There is no active transaction" is the
     * report of that mistake.
     *
     * @template T
     * @param \Closure(self): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $work($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $work($this);
        } catch (\Throwable $failure) {
            // This transaction is ours, so it is ours to close — but a
            // rollback that cannot run must not replace the failure that got
            // us here. That exception is the one carrying the diagnosis, and
            // losing it to a secondary PDO error is how a real cause gets
            // buried.
            try {
                $pdo->rollBack();
            } catch (\PDOException) {
                // The transaction ended without us; nothing left to undo.
            }

            throw $failure;
        }

        $pdo->commit();

        return $result;
    }

    /**
     * The id of the last inserted row, or null when the driver cannot report
     * one.
     *
     * Returned as a string because drivers disagree on whether it is numeric
     * — `PDO::lastInsertId()` is typed `string|false`, and the `false` is the
     * "this driver does not support it" case. Null is that case, kept
     * distinguishable from the string `"0"`, which is what a driver returns
     * when nothing has been inserted yet.
     */
    public function lastInsertId(): ?string
    {
        $id = $this->pdo()->lastInsertId();

        return $id === false ? null : $id;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws QueryFailed
     */
    private function fetchCompiled(Compiled $statement): array
    {
        $pdo = $this->pdo();

        try {
            $prepared = $pdo->prepare($statement->sql);
            $prepared->execute($statement->bindings);

            // array_values because fetchAll() is typed as returning a map
            // with unknown keys, and a row set is a list by construction.
            return array_values($prepared->fetchAll());
        } catch (\PDOException $previous) {
            throw QueryFailed::of($statement, $previous);
        }
    }

    private function requireDsn(): string
    {
        return $this->dsn ?? throw DbNotConfigured::of();
    }
}
