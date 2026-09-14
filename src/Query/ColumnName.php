<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * What the builder accepts where a column or a table goes, checked in one place.
 *
 * The compiler quotes every such string as an identifier path, and SQLite
 * answers an unknown quoted identifier with the string itself. So `COUNT(*)`,
 * `LOWER(email)` or `name AS author` in a column position did not fail: it
 * compared against, or came back as, its own text. `select()` refused that
 * first (DECISIONS 264); every other position the compiler quotes refuses it
 * here — conditions, `orderBy()`, joins and write keys (Lava Notes, R2-B1).
 *
 * A name is letters of any script, digits and underscores, not starting with a
 * digit (`prénom` is a name), qualified by up to two more (`posts.title`,
 * `main.posts.title`). The check is on the shape, not on existence: a typo that
 * is still a valid name reaches the database.
 */
final class ColumnName
{
    private const SEGMENT = '[\p{L}_][\p{L}\p{N}_]*';

    /**
     * @param string $call the builder call that was given the name, for the message — `where()`
     * @param bool $star whether `*` and `posts.*` are names here, as they are in `select()`
     * @throws BadQuery when the string is not a name
     */
    public static function check(string $name, string $call, bool $star = false): string
    {
        $last = $star ? '(?:' . self::SEGMENT . '|\*)' : self::SEGMENT;

        // `u`, so a letter is a letter in any script; a string that is not
        // valid UTF-8 fails to match and is refused like any other non-name.
        if (preg_match('/^(?:' . self::SEGMENT . '\.){0,2}' . $last . '$/u', $name) !== 1) {
            throw BadQuery::notAColumn($name, $call);
        }

        return $name;
    }

    /**
     * An alias for a selected column: one name, as a column's own last part is
     * (`author`), never qualified and never `*`.
     *
     * @throws BadQuery when the string is not one name
     */
    public static function alias(string $alias): string
    {
        if (preg_match('/^' . self::SEGMENT . '$/u', $alias) !== 1) {
            throw BadQuery::notAnAlias($alias);
        }

        return $alias;
    }
}
