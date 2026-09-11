<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Sql;

use Lava\Db\Sql\Compiled;
use PHPUnit\Framework\TestCase;

/**
 * `Compiled` is the one value the pack hands to PDO, and this pins the two
 * things about it a caller can depend on: its binding types, and its JSON
 * shape.
 *
 * **Why `json()` is tested though nothing calls it.** No command in this
 * repository emits a `Compiled`, so the method has no caller in `src` — and
 * the same is true of `SchemaSnapshot::json()`, which is tested for the same
 * reason. It is a convention rather than dead code: every value object in this
 * pack knows its own JSON shape, so a diagnostic can print one without a
 * second implementation of "what a compiled statement looks like". The shape
 * is asserted here so that when a command does print it — a `db:explain`, or a
 * `--verbose` path — the contract is already fixed rather than invented at the
 * call site.
 *
 * The binding types are asserted because they are a promise the docblock
 * makes: a `Compiled` never holds a value the driver would stringify by
 * accident, so the only types that may appear are the four PDO binds directly.
 */
final class CompiledTest extends TestCase
{
    public function testItKeepsTheSqlAndTheBindingsApartAndInOrder(): void
    {
        $compiled = new Compiled('SELECT * FROM "users" WHERE "id" = ? AND "name" = ?', [1, 'ada']);

        self::assertSame('SELECT * FROM "users" WHERE "id" = ? AND "name" = ?', $compiled->sql);
        self::assertSame([1, 'ada'], $compiled->bindings);
    }

    public function testABareStatementHasNoBindings(): void
    {
        // The default is the empty list rather than null, because a caller
        // passing it to PDO must not have to decide what "no bindings" means.
        self::assertSame([], (new Compiled('SELECT 1'))->bindings);
    }

    /**
     * The JSON shape, including key ORDER. The framework's envelopes are read
     * by agents and asserted as literal JSON in the CLI golden tests, so a
     * value object's own `json()` has to be stable in order as well as in
     * content — a reordering is a schema change, not a cosmetic one.
     */
    public function testItsJsonShapeIsFixedIncludingKeyOrder(): void
    {
        $json = (new Compiled('SELECT * FROM "users" WHERE "id" = ?', [1]))->json();

        self::assertSame(['sql', 'bindings'], array_keys($json));
        self::assertSame('SELECT * FROM "users" WHERE "id" = ?', $json['sql']);
        self::assertSame([1], $json['bindings']);
    }
}
