<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Live;

use Lava\Db\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The base for tests that need a real database.
 *
 * Everything else in this pack runs without one — the compiler, the schema
 * compiler, and the builders are pure, and that is deliberate. But purity has
 * a blind spot: a `CREATE TABLE` string can be exactly what the compiler meant
 * to write and still be rejected by the database, and a snapshot's idea of
 * what a catalogue returns can be wrong in a way no fixture catches. These
 * tests are the ones that would notice.
 *
 * They are skipped unless `DB_TEST_DSN` is set, so a machine with no driver
 * runs the suite green and honestly — a skip is a visible "not checked here",
 * which is the truth, rather than a green tick that means nothing.
 *
 * ```
 * DB_TEST_DSN=sqlite::memory: vendor/bin/phpunit --testsuite db
 * DB_TEST_DSN='mysql:host=127.0.0.1;dbname=lava_test' DB_TEST_USER=root vendor/bin/phpunit --testsuite db
 * ```
 *
 * **Point this at a scratch database.** The tests create and drop tables, and
 * `--testsuite db` runs them all against whatever the DSN names. They never
 * drop a table they did not create, but they do write.
 */
abstract class LiveDatabaseTestCase extends TestCase
{
    protected Connection $db;

    /** @var list<string> every table this test created, dropped again on the way out */
    private array $created = [];

    protected function setUp(): void
    {
        $dsn = getenv('DB_TEST_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped(
                'Set DB_TEST_DSN to run the live database tests — e.g. DB_TEST_DSN=sqlite::memory:.'
            );
        }

        $user = getenv('DB_TEST_USER');
        $password = getenv('DB_TEST_PASSWORD');

        $this->db = new Connection(
            $dsn,
            $user === false || $user === '' ? null : $user,
            $password === false || $password === '' ? null : $password,
        );
    }

    protected function tearDown(): void
    {
        // Only ever the tables this test made. A shared scratch database is
        // someone's, and a test that clears it out is a test that ruins the
        // next run for reasons nobody can see.
        foreach (array_reverse($this->created) as $table) {
            $this->db->schema()->dropIfExists($table);
        }

        $this->created = [];
    }

    /** Records a table for cleanup, so the test body does not have to. */
    protected function remember(string $table): string
    {
        $this->created[] = $table;

        return $table;
    }
}
