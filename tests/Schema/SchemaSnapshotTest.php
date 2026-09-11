<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Schema;

use Lava\Db\Schema\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * `SchemaSnapshot` as a value: what it holds, how it compares, how it prints.
 *
 * The live tests prove it can READ a database — `SchemaAndCrudTest` builds
 * snapshots with `of()` and asserts the columns that came back. This file is
 * the other half, and it exists because two public methods had no caller
 * anywhere in the repository: `equals()` and `json()`. The class docblock calls
 * a snapshot "the only thing that can answer 'did the migration do what it
 * said'", and the comparison it offers for exactly that question was, until
 * this test, a promise with nothing behind it.
 *
 * Snapshots here are built by hand rather than read from a database, which is
 * the point: these are assertions about the VALUE, and they hold on a machine
 * with no driver, no server and no migration. The shapes are the ones the
 * `@phpstan-type` aliases declare — see the class itself.
 */
final class SchemaSnapshotTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array{columns: array<string, array{type: string, nullable: bool, default: string|null, primary: bool}>, indexes: array<string, bool>}
     */
    private static function widgets(array $overrides = []): array
    {
        return [
            'columns' => [
                'id' => ['type' => 'INTEGER', 'nullable' => false, 'default' => null, 'primary' => true],
                'title' => ['type' => 'VARCHAR(255)', 'nullable' => false, 'default' => null, 'primary' => false],
            ],
            'indexes' => ['idx_widgets_title' => false],
            ...$overrides,
        ];
    }

    public function testItAnswersWhatItHolds(): void
    {
        $snapshot = new SchemaSnapshot(['widgets' => self::widgets(), 'tags' => self::widgets()]);

        // `tableNames` is a LIST, not the map: it is what a caller iterates, and
        // the order is the order the snapshot was built with — `of()` sorts, so
        // a snapshot read from a database is always in catalogue order.
        self::assertSame(['widgets', 'tags'], $snapshot->tableNames());
        self::assertTrue($snapshot->has('widgets'));
        self::assertFalse($snapshot->has('gadgets'));

        // A table that is not there answers with an empty shape rather than
        // null or an error: a caller asking "what columns does X have" about an
        // absent X wants the empty answer, not a branch.
        self::assertSame([], $snapshot->columns('gadgets'));
        self::assertSame([], $snapshot->indexes('gadgets'));
        self::assertSame(['idx_widgets_title' => false], $snapshot->indexes('widgets'));
    }

    public function testTwoSnapshotsOfTheSameSchemaAreEqual(): void
    {
        $before = new SchemaSnapshot(['widgets' => self::widgets()]);
        $after = new SchemaSnapshot(['widgets' => self::widgets()]);

        self::assertTrue($before->equals($after));
        self::assertTrue($after->equals($before), 'equality must not be one-way');
    }

    public function testAColumnThatDiffersMakesTheSnapshotsDifferent(): void
    {
        // The round-trip question, in miniature: one column gained a default.
        // Everything else is identical, so this fails if the comparison is
        // shallower than the shape it claims to compare.
        $before = new SchemaSnapshot(['widgets' => self::widgets()]);
        $after = new SchemaSnapshot([
            'widgets' => self::widgets([
                'columns' => [
                    'id' => ['type' => 'INTEGER', 'nullable' => false, 'default' => null, 'primary' => true],
                    'title' => ['type' => 'VARCHAR(255)', 'nullable' => false, 'default' => "'untitled'", 'primary' => false],
                ],
            ]),
        ]);

        self::assertFalse($before->equals($after));
    }

    public function testAnIndexThatDiffersMakesTheSnapshotsDifferent(): void
    {
        // Indexes are the half of a schema a migration is most likely to change
        // without changing a column, so they are compared on their own rather
        // than trusted to ride along with the columns.
        $before = new SchemaSnapshot(['widgets' => self::widgets()]);
        $after = new SchemaSnapshot(['widgets' => self::widgets(['indexes' => ['idx_widgets_title' => true]])]);

        self::assertFalse($before->equals($after));
    }

    public function testATableThatIsMissingMakesTheSnapshotsDifferent(): void
    {
        $before = new SchemaSnapshot(['widgets' => self::widgets()]);
        $after = new SchemaSnapshot(['widgets' => self::widgets(), 'tags' => self::widgets()]);

        self::assertFalse($before->equals($after));
    }

    public function testJsonIsTheTablesPlusTheirCount(): void
    {
        // `count` is not derivable by every consumer cheaply in every language,
        // and a report that has to walk the map to say how big it is is a report
        // that reimplements the class. So the count is part of the payload.
        $snapshot = new SchemaSnapshot(['widgets' => self::widgets(), 'tags' => self::widgets()]);

        self::assertSame(
            ['tables' => ['widgets' => self::widgets(), 'tags' => self::widgets()], 'count' => 2],
            $snapshot->json(),
        );
    }

    public function testAnEmptySnapshotIsAValidAnswer(): void
    {
        // The state a fresh database is in, and the one a "did anything get
        // created" check reads first.
        $snapshot = new SchemaSnapshot([]);

        self::assertSame([], $snapshot->tableNames());
        self::assertSame(['tables' => [], 'count' => 0], $snapshot->json());
        self::assertTrue($snapshot->equals(new SchemaSnapshot([])));
    }
}
