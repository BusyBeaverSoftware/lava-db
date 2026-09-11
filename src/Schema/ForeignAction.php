<?php

declare(strict_types=1);

namespace Lava\Db\Schema;

/**
 * What a foreign key does when the referenced row changes.
 *
 * `Restrict` is the default the compiler applies when a reference is declared
 * without one, and it is the deliberate choice: cascading deletes are the
 * single easiest way to lose data by accident, so they have to be asked for.
 * `NoAction` is spelled separately because it is not the same as `Restrict`
 * on a deferred-constraint database, even though SQLite and MySQL treat them
 * alike.
 */
enum ForeignAction: string
{
    case Cascade = 'CASCADE';
    case Restrict = 'RESTRICT';
    case SetNull = 'SET NULL';
    case NoAction = 'NO ACTION';
}
