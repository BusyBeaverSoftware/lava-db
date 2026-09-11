<?php

declare(strict_types=1);

namespace Lava\Db\Migration;

use Lava\Db\Connection;

/**
 * One migration.
 *
 * A migration file *is* the migration: it ends with
 * `return new class extends Migration { … };` rather than declaring a class
 * whose name has to be derived from the filename. That is a deliberate
 * reduction in machinery — no class-name parsing out of a timestamp, no
 * reflection to instantiate one — and it makes the file the only thing that
 * has to be understood. Re-`require`-ing such a file yields a fresh instance
 * of the same class entry, so discovery is a plain `require`.
 *
 * Both directions are abstract. A migration that genuinely cannot be undone
 * says so with an empty `down()` body, which is a visible statement in the
 * file rather than an omission — `lava db:new` generates a `down()` that
 * undoes what its `up()` did, so the default is reversible and anything else
 * is something the author chose.
 *
 * Migrations get a {@see Connection} and nothing else. There is no app, no
 * container, and no request: a migration that needs configuration should have
 * read it when the migration was written, not when it runs — otherwise a
 * migration stops being reproducible the moment a config value changes.
 */
abstract class Migration
{
    abstract public function up(Connection $db): void;

    abstract public function down(Connection $db): void;
}
