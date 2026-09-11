<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * The join flavours lava/db emits. RIGHT and FULL OUTER joins are absent on
 * purpose: SQLite has never supported RIGHT JOIN, so a builder that offered
 * one would produce a query that works in development and fails in the
 * app's own SQLite file. Expressing the same result as a reordered LEFT JOIN
 * is always possible; a portability trap is not worth the convenience.
 */
enum JoinType: string
{
    case Inner = 'INNER JOIN';
    case Left = 'LEFT JOIN';
}
