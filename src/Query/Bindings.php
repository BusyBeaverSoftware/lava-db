<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * Turns PHP values into values PDO can bind, or refuses them with a fix.
 *
 * The refusal is the interesting half. PDO will happily accept an object and
 * stringify it — an `\App\Money` becomes `"Object of class App\Money could
 * not be converted to string"` or, worse, `"{$value}"` via a `__toString`
 * that was never meant for a database. That is a silent wrong value in a
 * column, which is the failure mode this framework exists to eliminate. So
 * the conversions that ARE meant are named here (dates, enums, bools) and
 * everything else is a `bad_query` with the fix.
 *
 * `DateTimeInterface` keeps the wall-clock time and drops the zone, matching
 * what a `DATETIME` column means in all three dialects. Store UTC if you
 * care — the conversion is documented rather than clever.
 */
final class Bindings
{
    /** The format a DateTimeInterface is written in. Matches DATETIME on all three dialects. */
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    public static function normalize(mixed $value, string $column): int|float|string|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        // Bools become 1/0 rather than being bound as PDO::PARAM_BOOL: with
        // emulated prepares (which MySQL enables by default) a bound false
        // arrives as the empty string, which is neither 0 nor FALSE.
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value instanceof \BackedEnum) {
            return self::normalize($value->value, $column);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::DATE_FORMAT);
        }
        if (is_array($value)) {
            throw BadQuery::arrayValue($column);
        }
        throw BadQuery::unbindable($column, get_debug_type($value));
    }

    /**
     * @param array<mixed> $values
     * @return list<int|float|string|null>
     */
    public static function list(array $values, string $column): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = self::normalize($value, $column);
        }
        return $out;
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, int|float|string|null>
     */
    public static function map(array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $out[(string) $column] = self::normalize($value, (string) $column);
        }
        return $out;
    }
}
