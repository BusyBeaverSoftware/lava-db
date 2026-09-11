<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/** One ORDER BY term. */
final readonly class OrderBy
{
    public function __construct(
        public string $column,
        public Direction $direction = Direction::Asc,
    ) {
    }
}
