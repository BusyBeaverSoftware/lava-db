<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/** Sort direction. An enum for the same reason {@see Operator} is one. */
enum Direction: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
