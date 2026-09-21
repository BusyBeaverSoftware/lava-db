<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Live;

use Lava\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The one driver option an app does not get to set.
 *
 * `Connection` merges an app's options over its defaults with `+`, which keeps
 * the LEFT key — so an app that passed `ATTR_EMULATE_PREPARES => true` (the
 * line copied out of a Laravel or Doctrine snippet for MySQL buffering)
 * silently turned off the single control this pack names as its guarantee that
 * a bound value never becomes SQL text. With emulation on, PDO interpolates
 * values itself and correctness rests on a connection charset this pack does
 * not set (security review).
 *
 * Asserted on the merge rather than through a handle: pdo_sqlite refuses to
 * report this attribute (`driver does not support that attribute`), so reading
 * it back would only ever be a skip on this project's own engine.
 */
final class DriverOptionsTest extends TestCase
{
    public function testEmulatedPreparesStayOffHoweverTheAppConfiguresTheDriver(): void
    {
        $options = Connection::driverOptions([\PDO::ATTR_EMULATE_PREPARES => true]);

        self::assertFalse($options[\PDO::ATTR_EMULATE_PREPARES]);
    }

    public function testTheDefaultsAreStillTheDefaults(): void
    {
        $options = Connection::driverOptions([]);

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $options[\PDO::ATTR_ERRMODE]);
        self::assertSame(\PDO::FETCH_ASSOC, $options[\PDO::ATTR_DEFAULT_FETCH_MODE]);
        self::assertFalse($options[\PDO::ATTR_EMULATE_PREPARES]);
    }

    public function testEveryOtherOptionIsStillTheApps(): void
    {
        $options = Connection::driverOptions([
            \PDO::ATTR_CASE => \PDO::CASE_UPPER,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT,
        ]);

        self::assertSame(\PDO::CASE_UPPER, $options[\PDO::ATTR_CASE]);
        self::assertSame(\PDO::ERRMODE_SILENT, $options[\PDO::ATTR_ERRMODE], 'the error mode stays the app\'s call');
    }
}
