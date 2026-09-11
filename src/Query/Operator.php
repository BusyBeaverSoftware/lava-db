<?php

declare(strict_types=1);

namespace Lava\Db\Query;

/**
 * The operators a condition may use, as an enum rather than a string.
 *
 * A string operator is a typo waiting to happen: `->where('id', '==' , 1)`
 * compiles to `id == ?`, which SQLite accepts and PostgreSQL rejects, and
 * nothing tells you until a request fails. An enum makes the misspelling
 * impossible to write, and {@see isComparison()} draws the line between the
 * operators that take one bound value and the ones that need their own
 * method — so `->where('id', Operator::In, [1, 2])` is refused with a fix
 * rather than compiled into nonsense.
 *
 * `Raw` is the escape hatch's marker. It is never rendered as SQL: it tells
 * the compiler "the expression is already SQL, do not quote it".
 */
enum Operator: string
{
    case Eq = '=';
    case Ne = '<>';
    case Gt = '>';
    case Gte = '>=';
    case Lt = '<';
    case Lte = '<=';
    case Like = 'LIKE';
    case NotLike = 'NOT LIKE';
    case In = 'IN';
    case NotIn = 'NOT IN';
    case IsNull = 'IS NULL';
    case IsNotNull = 'IS NOT NULL';
    case Between = 'BETWEEN';
    case Raw = '';

    /** True for the operators that compare one column against one bound value. */
    public function isComparison(): bool
    {
        return match ($this) {
            self::Eq, self::Ne, self::Gt, self::Gte, self::Lt, self::Lte, self::Like, self::NotLike => true,
            self::In, self::NotIn, self::IsNull, self::IsNotNull, self::Between, self::Raw => false,
        };
    }
}
