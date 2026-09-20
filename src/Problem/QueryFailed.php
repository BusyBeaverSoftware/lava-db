<?php

declare(strict_types=1);

namespace Lava\Db\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Db\Sql\Compiled;

/**
 * The database rejected a statement that the compiler produced.
 *
 * This is the runtime counterpart of {@see BadQuery}: the statement was
 * well-formed enough to compile, but the database disagrees — an unknown
 * column, a unique-constraint violation, a syntax difference between
 * dialects. The SQL and its bindings travel in the context because they are
 * the entire diagnosis, and without them the message is a database's
 * complaint about a query the reader cannot see.
 *
 * The bindings are included even though a bound value can be sensitive: the
 * alternative is a report that names a failing statement without its inputs,
 * which is the one thing this framework promises never to do. Treat a
 * `query_failed` context as you would treat the data it queried.
 */
final class QueryFailed extends LavaProblem
{
    public static function of(Compiled $statement, \PDOException $previous): self
    {
        return new self(
            'The database rejected a statement.',
            'Read `driver_message` in the context for what the database objected to, and check the column '
            . 'and table names against the schema (`lava db:status` lists the tables). A constraint '
            . 'violation means the data, not the query, is at fault.',
            [
                // The driver's own sentence belongs here, not in the message.
                // Production withholds a 5xx's context but keeps its message, so
                // `no such table: admin_sessions` and `UNIQUE constraint failed:
                // users.email` were the default public 500 body — handing an
                // unauthenticated caller the schema, and on MySQL the offending
                // VALUE (`Duplicate entry 'alice@example.com' …`). Dev loses
                // nothing: the context is rendered there (security review).
                'driver_message' => DbConnectionFailed::redact($previous->getMessage()),
                'sql' => $statement->sql,
                'bindings' => $statement->bindings,
                'sqlstate' => (string) $previous->getCode(),
            ],
            null,
            $previous,
        );
    }

    public function code(): string
    {
        return 'query_failed';
    }
}
