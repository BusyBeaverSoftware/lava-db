<?php

declare(strict_types=1);

namespace Lava\Db\Schema;

/**
 * The column types the schema DSL offers — a portable set, not a per-dialect
 * one.
 *
 * Each case is a *meaning* that {@see \Lava\Db\Sql\SchemaCompiler} translates
 * into the right native type for the dialect in use: `Bool` is `INTEGER` on
 * SQLite, `TINYINT(1)` on MySQL, and `BOOLEAN` on PostgreSQL. That is the
 * whole value of the abstraction — a migration written once runs on all
 * three, and a column that means "true or false" keeps meaning that.
 *
 * The set is deliberately short. `ColumnType::Money` or `::IpAddress` would
 * each save a line and add a permanent portability question, so those are
 * `Decimal` and `String` with a documented convention instead.
 */
enum ColumnType: string
{
    case Int = 'int';
    case BigInt = 'bigint';
    case String = 'string';
    case Text = 'text';
    case Bool = 'bool';
    case Float = 'float';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Json = 'json';
    case Uuid = 'uuid';
    case Binary = 'binary';
}
