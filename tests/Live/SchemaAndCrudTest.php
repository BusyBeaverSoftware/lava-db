<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Live;

use Lava\Core\Problem\LavaProblem;
use Lava\Db\Query\Operator;
use Lava\Db\Schema\ForeignAction;
use Lava\Db\Schema\SchemaSnapshot;
use Lava\Db\Schema\Table;

/**
 * The schema DSL and the query builder, against a real database.
 *
 * The pure tests assert that the DDL is what the compiler meant to write.
 * These assert that the database accepts it — which is a different claim, and
 * the only one that matters at runtime. The interesting cases are the ones
 * where the two could disagree: a foreign key the dialect silently discards,
 * a snapshot that reads a catalogue column it misremembered, a bound value
 * that the driver renders as SQL anyway.
 */
final class SchemaAndCrudTest extends LiveDatabaseTestCase
{
    public function testTheSchemaDslCreatesWhatTheSnapshotReadsBack(): void
    {
        $this->remember('users');
        $this->remember('posts');

        $this->db->schema()->create('users', function (Table $t): void {
            $t->id();
            $t->string('email')->unique();
            $t->string('name', 120);
        });

        $this->db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->bool('published')->default(false);
            $t->foreignId('user_id')->references('users')->onDelete(ForeignAction::Cascade);
            $t->timestamps();
            $t->unique(['user_id', 'title']);
            $t->index('published');
        });

        $snapshot = SchemaSnapshot::of($this->db);

        self::assertTrue($snapshot->has('users'));
        self::assertTrue($snapshot->has('posts'));

        $columns = $snapshot->columns('posts');
        self::assertSame(
            ['id', 'title', 'body', 'published', 'user_id', 'created_at', 'updated_at'],
            array_keys($columns),
        );
        self::assertFalse($columns['title']['nullable']);
        self::assertTrue($columns['body']['nullable']);
        self::assertTrue($columns['id']['primary']);
        self::assertFalse($columns['title']['primary']);

        // The index names are the ones the DSL chose, not ones the database
        // invented — which is what makes them droppable by a later migration.
        self::assertSame(
            ['posts_published_index' => false, 'posts_user_id_title_unique' => true],
            $snapshot->indexes('posts'),
        );
    }

    public function testAColumnAddedLaterIsVisibleToTheSnapshot(): void
    {
        $this->remember('widgets');

        $this->db->schema()->create('widgets', fn (Table $t) => $t->string('name'));

        $before = SchemaSnapshot::of($this->db)->columns('widgets');
        self::assertSame(['name'], array_keys($before));

        $this->db->schema()->table('widgets', function (Table $t): void {
            $t->string('colour')->nullable();
            $t->bool('active')->default(true);
        });

        $after = SchemaSnapshot::of($this->db)->columns('widgets');
        self::assertSame(['name', 'colour', 'active'], array_keys($after));
        self::assertTrue($after['colour']['nullable']);
        self::assertFalse($after['active']['nullable']);
    }

    public function testAnIndexDeclaredWhileAddingColumnsIsCreatedAndEnforced(): void
    {
        $this->remember('widgets');

        $this->db->schema()->create('widgets', fn (Table $t) => $t->string('name'));

        // 0.2.0 ran this without a problem and created neither index, so the
        // "unique" slug took duplicates.
        $this->db->schema()->table('widgets', function (Table $t): void {
            $t->string('slug')->nullable()->unique();
            $t->index('name');
        });

        self::assertSame(
            ['widgets_name_index' => false, 'widgets_slug_unique' => true],
            SchemaSnapshot::of($this->db)->indexes('widgets'),
        );

        $this->db->run($this->db->table('widgets')->insert(['name' => 'first', 'slug' => 'same']));

        try {
            $this->db->run($this->db->table('widgets')->insert(['name' => 'second', 'slug' => 'same']));
            self::fail('A column added with ->unique() accepted a duplicate.');
        } catch (LavaProblem $problem) {
            self::assertSame('query_failed', $problem->code());
        }
    }

    public function testAnIndexOverAColumnTheTableWillNotHaveIsRefusedBeforeTheDdlRuns(): void
    {
        $this->remember('widgets');

        $this->db->schema()->create('widgets', fn (Table $t) => $t->string('name'));

        try {
            $this->db->schema()->table('widgets', function (Table $t): void {
                $t->string('colour')->nullable();
                $t->unique('slug');
            });
            self::fail('An index over a column that does not exist should be refused.');
        } catch (LavaProblem $problem) {
            self::assertSame('bad_schema', $problem->code());
            self::assertStringContainsString("names the column 'slug'", $problem->getMessage());
            self::assertStringContainsString('name, colour', $problem->fix);
        }

        // The column the same closure added is not there either: nothing ran.
        self::assertSame(['name'], array_keys(SchemaSnapshot::of($this->db)->columns('widgets')));
    }

    public function testAPrimaryKeyDeclaredOnAnExistingTableIsRefused(): void
    {
        $this->remember('widgets');

        $this->db->schema()->create('widgets', fn (Table $t) => $t->string('name'));

        try {
            $this->db->schema()->table('widgets', fn (Table $t) => $t->primary('name'));
            self::fail('A primary key cannot be added to an existing table, and saying nothing is a lie.');
        } catch (LavaProblem $problem) {
            self::assertSame('bad_schema', $problem->code());
            self::assertStringContainsString("existing table 'widgets'", $problem->getMessage());
        }
    }

    public function testDroppingATableRemovesItFromTheSnapshot(): void
    {
        $this->db->schema()->create('temporary', fn (Table $t) => $t->string('name'));

        self::assertTrue($this->db->schema()->has('temporary'));

        $this->db->schema()->dropIfExists('temporary');

        self::assertFalse($this->db->schema()->has('temporary'));
    }

    public function testAReferenceToAColumnTheTargetDoesNotHaveIsCaughtBeforeTheDdlRuns(): void
    {
        $this->remember('users');
        $this->remember('posts');

        $this->db->schema()->create('users', function (Table $t): void {
            $t->id();
            $t->string('email', 120);
        });

        try {
            $this->db->schema()->create('posts', function (Table $t): void {
                $t->id();
                $t->foreignId('user_id')->references('users', 'uuid');
            });
            self::fail('A reference to a column that does not exist should be refused.');
        } catch (LavaProblem $problem) {
            self::assertSame('bad_schema', $problem->code());
            self::assertStringContainsString("has no column 'uuid'", $problem->getMessage());
            self::assertStringContainsString('id, email', $problem->fix);
        }

        // Nothing was created, so the migration is not half-applied.
        self::assertFalse($this->db->schema()->has('posts'));
    }

    public function testAReferenceToATableThatDoesNotExistYetIsLeftToTheDatabase(): void
    {
        $this->remember('users');
        $this->remember('posts');

        // `posts` before `users` is an ordinary migration; refusing it would
        // reject working code, so only a target that is *there* is checked.
        $this->db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->foreignId('user_id')->references('users');
        });
        $this->db->schema()->create('users', fn (Table $t) => $t->id());

        self::assertTrue($this->db->schema()->has('posts'));
        self::assertTrue($this->db->schema()->has('users'));
    }

    public function testTheFullCrudRoundTrip(): void
    {
        $this->remember('users');
        $this->remember('posts');

        $this->db->schema()->create('users', function (Table $t): void {
            $t->id();
            $t->string('email')->unique();
        });
        $this->db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->string('title', 200);
            $t->bool('published')->default(false);
            $t->foreignId('user_id')->references('users');
        });

        $this->db->run($this->db->table('users')->insert(['email' => 'grace@example.com']));
        $userId = (int) $this->db->lastInsertId();

        // insertMany writes several rows in one statement — which means one
        // column list, so every row must set the same columns. A row that
        // omits one is refused rather than defaulted, because `INSERT` cannot
        // say "default" for one row of a multi-row VALUES list.
        $this->db->run($this->db->table('posts')->insertMany([
            ['title' => 'Draft', 'published' => false, 'user_id' => $userId],
            ['title' => 'Live', 'published' => true, 'user_id' => $userId],
        ]));

        self::assertSame(
            [['title' => 'Live']],
            $this->db->fetch($this->db->table('posts')->select('title')->where('published', Operator::Eq, true)),
        );

        self::assertSame(
            'grace@example.com',
            $this->db->scalar($this->db->table('users')->select('email')),
        );

        self::assertSame(
            1,
            $this->db->run($this->db->table('posts')->where('title', Operator::Eq, 'Draft')->update(['title' => 'Draft 2'])),
        );

        // An unbounded write has to be asked for by name; see
        // BadQuery::unbounded(). The explicit `1 = 1` is the caller saying
        // "yes, every row" rather than a condition that happens to be absent.
        self::assertSame(
            2,
            $this->db->run($this->db->table('posts')->whereRaw('1 = 1')->delete()),
        );

        self::assertSame([], $this->db->fetch($this->db->table('posts')->select('*')));
    }

    public function testABoundValueIsNeverExecutedAsSql(): void
    {
        $this->remember('notes');

        $this->db->schema()->create('notes', fn (Table $t) => $t->string('body', 255));

        $attack = "'); DROP TABLE notes; --";
        $this->db->run($this->db->table('notes')->insert(['body' => $attack]));

        // The table is still there, and the value came back byte for byte.
        self::assertTrue($this->db->schema()->has('notes'));
        self::assertSame($attack, $this->db->scalar($this->db->table('notes')->select('body')));
    }

    public function testAForeignKeyIsEnforcedByTheDatabase(): void
    {
        $this->remember('users');
        $this->remember('posts');

        $this->db->schema()->create('users', fn (Table $t) => $t->id());
        $this->db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->foreignId('user_id')->references('users');
        });

        // The DSL emits a table-level CONSTRAINT precisely so this holds on
        // MySQL as well as here — MySQL parses an inline REFERENCES on a
        // column and then ignores it.
        try {
            $this->db->run($this->db->table('posts')->insert(['user_id' => 999999]));
            self::fail('The database accepted a foreign key that references nothing.');
        } catch (LavaProblem $problem) {
            self::assertSame('query_failed', $problem->code());
            self::assertArrayHasKey('sql', $problem->context);
            self::assertArrayHasKey('bindings', $problem->context);
        }
    }

    public function testOnDeleteCascadeRemovesTheDependentRows(): void
    {
        $this->remember('users');
        $this->remember('posts');

        $this->db->schema()->create('users', function (Table $t): void {
            $t->id();
            $t->string('email', 120);
        });
        $this->db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->foreignId('user_id')->references('users')->onDelete(ForeignAction::Cascade);
        });

        $this->db->run($this->db->table('users')->insert(['email' => 'cascade@example.com']));
        $userId = (int) $this->db->lastInsertId();
        $this->db->run($this->db->table('posts')->insert(['user_id' => $userId]));

        $this->db->run($this->db->table('users')->where('id', Operator::Eq, $userId)->delete());

        self::assertSame([], $this->db->fetch($this->db->table('posts')->select('*')));
    }

    public function testARolledBackTransactionLeavesNothingBehind(): void
    {
        $this->remember('notes');

        $this->db->schema()->create('notes', fn (Table $t) => $t->string('body', 255));

        try {
            $this->db->transaction(function ($tx): void {
                $tx->run($tx->table('notes')->insert(['body' => 'doomed']));
                throw new \RuntimeException('changed my mind');
            });
            self::fail('The transaction should have rethrown.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame([], $this->db->fetch($this->db->table('notes')->select('*')));
    }

    public function testACommittedTransactionKeepsItsRows(): void
    {
        $this->remember('notes');

        $this->db->schema()->create('notes', fn (Table $t) => $t->string('body', 255));

        $result = $this->db->transaction(function ($tx): string {
            $tx->run($tx->table('notes')->insert(['body' => 'kept']));

            return 'returned';
        });

        self::assertSame('returned', $result);
        self::assertSame('kept', $this->db->scalar($this->db->table('notes')->select('body')));
    }

    public function testTheRawEscapeHatchesStillBind(): void
    {
        $this->remember('notes');

        $this->db->schema()->create('notes', fn (Table $t) => $t->string('body', 255));
        $this->db->statement('INSERT INTO ' . $this->db->dialect()->quote('notes') . ' ("body") VALUES (?)', ['raw']);

        self::assertSame(
            [['body' => 'raw']],
            $this->db->query('SELECT "body" FROM ' . $this->db->dialect()->quote('notes') . ' WHERE "body" = ?', ['raw']),
        );
    }
}
