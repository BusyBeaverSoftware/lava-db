<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Sql;

use Lava\Db\Problem\BadSchema;
use Lava\Db\Schema\ColumnDef;
use Lava\Db\Schema\ColumnType;
use Lava\Db\Schema\ForeignAction;
use Lava\Db\Schema\Table;
use Lava\Db\Sql\Dialect;
use Lava\Db\Sql\SchemaCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The schema DSL, held to exact DDL — with no database anywhere in sight.
 *
 * The schema layer is where a mistake is most expensive: it runs once, against
 * a real database, and the failure mode is a half-applied migration. So the
 * whole of it is compiled and asserted as strings before any of it is
 * executed, which is the same trade {@see \Lava\Db\Tests\Sql\CompilerTest}
 * makes for queries and for the same reason.
 *
 * Every expectation here is written out by hand rather than derived from the
 * compiler. A derived expectation — "MySQL output is SQLite output with the
 * quotes swapped" — asserts that the compiler is self-consistent, which it
 * always is, and not that it is right.
 */
final class SchemaCompilerTest extends TestCase
{
    private static function compiler(Dialect $dialect): SchemaCompiler
    {
        return new SchemaCompiler($dialect);
    }

    /**
     * @param \Closure(Table): void $define
     */
    private static function table(string $name, \Closure $define): Table
    {
        $table = new Table($name);
        $define($table);

        return $table;
    }

    /** @return list<string> the SQL of every statement, in order */
    private static function sql(Dialect $dialect, Table $table): array
    {
        return array_map(
            static fn ($statement): string => $statement->sql,
            self::compiler($dialect)->create($table),
        );
    }

    /**
     * One case per column type, so a type that maps wrongly on any dialect
     * fails by name rather than as part of a wall of CREATE TABLE text.
     *
     * @return array<string, array{ColumnType, string, string, string}>
     */
    public static function types(): array
    {
        return [
            'int' => [ColumnType::Int, 'INTEGER', 'INT', 'INT'],
            'bigint' => [ColumnType::BigInt, 'INTEGER', 'BIGINT', 'BIGINT'],
            'string' => [ColumnType::String, 'VARCHAR(255)', 'VARCHAR(255)', 'VARCHAR(255)'],
            'text' => [ColumnType::Text, 'TEXT', 'TEXT', 'TEXT'],
            'bool' => [ColumnType::Bool, 'INTEGER', 'TINYINT(1)', 'BOOLEAN'],
            'float' => [ColumnType::Float, 'REAL', 'DOUBLE', 'DOUBLE PRECISION'],
            'decimal' => [ColumnType::Decimal, 'NUMERIC(10, 2)', 'DECIMAL(10, 2)', 'NUMERIC(10, 2)'],
            'date' => [ColumnType::Date, 'TEXT', 'DATE', 'DATE'],
            'datetime' => [ColumnType::DateTime, 'TEXT', 'DATETIME', 'TIMESTAMP'],
            'time' => [ColumnType::Time, 'TEXT', 'TIME', 'TIME'],
            'json' => [ColumnType::Json, 'TEXT', 'JSON', 'JSONB'],
            'uuid' => [ColumnType::Uuid, 'TEXT', 'CHAR(36)', 'UUID'],
            'binary' => [ColumnType::Binary, 'BLOB', 'BLOB', 'BYTEA'],
        ];
    }

    #[DataProvider('types')]
    public function testEachTypeMapsToItsNativeType(
        ColumnType $type,
        string $sqlite,
        string $mysql,
        string $pgsql,
    ): void {
        $column = new ColumnDef('c', $type);

        self::assertSame($sqlite, self::compiler(Dialect::Sqlite)->type($column));
        self::assertSame($mysql, self::compiler(Dialect::Mysql)->type($column));
        self::assertSame($pgsql, self::compiler(Dialect::Pgsql)->type($column));
    }

    public function testAnExplicitLengthAndPrecisionReachTheType(): void
    {
        self::assertSame(
            'VARCHAR(120)',
            self::compiler(Dialect::Sqlite)->type(new ColumnDef('c', ColumnType::String, length: 120)),
        );
        self::assertSame(
            'NUMERIC(3, 1)',
            self::compiler(Dialect::Pgsql)->type(new ColumnDef('c', ColumnType::Decimal, precision: 3, scale: 1)),
        );
    }

    /**
     * The whole of a realistic table, on each dialect. This is the case that
     * catches clause *ordering* — the part a per-type test cannot see.
     *
     * @return array<string, array{Dialect, string}>
     */
    public static function tables(): array
    {
        return [
            'sqlite' => [Dialect::Sqlite, <<<'SQL'
            CREATE TABLE "posts" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "title" VARCHAR(200) NOT NULL, "body" TEXT, "published" INTEGER NOT NULL DEFAULT 0, "rating" NUMERIC(3, 1) NOT NULL DEFAULT 0, "meta" TEXT, "user_id" INTEGER NOT NULL, "created_at" TEXT, "updated_at" TEXT, CONSTRAINT "posts_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE)
            CREATE UNIQUE INDEX "posts_user_id_title_unique" ON "posts" ("user_id", "title")
            CREATE INDEX "posts_published_index" ON "posts" ("published")
            SQL],
            'mysql' => [Dialect::Mysql, <<<'SQL'
            CREATE TABLE `posts` (`id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` VARCHAR(200) NOT NULL, `body` TEXT, `published` TINYINT(1) NOT NULL DEFAULT 0, `rating` DECIMAL(3, 1) NOT NULL DEFAULT 0, `meta` JSON, `user_id` BIGINT NOT NULL, `created_at` DATETIME, `updated_at` DATETIME, CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE)
            CREATE UNIQUE INDEX `posts_user_id_title_unique` ON `posts` (`user_id`, `title`)
            CREATE INDEX `posts_published_index` ON `posts` (`published`)
            SQL],
            'pgsql' => [Dialect::Pgsql, <<<'SQL'
            CREATE TABLE "posts" ("id" BIGSERIAL PRIMARY KEY, "title" VARCHAR(200) NOT NULL, "body" TEXT, "published" BOOLEAN NOT NULL DEFAULT FALSE, "rating" NUMERIC(3, 1) NOT NULL DEFAULT 0, "meta" JSONB, "user_id" BIGINT NOT NULL, "created_at" TIMESTAMP, "updated_at" TIMESTAMP, CONSTRAINT "posts_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE)
            CREATE UNIQUE INDEX "posts_user_id_title_unique" ON "posts" ("user_id", "title")
            CREATE INDEX "posts_published_index" ON "posts" ("published")
            SQL],
        ];
    }

    #[DataProvider('tables')]
    public function testACreateCompilesToItsDdl(Dialect $dialect, string $expected): void
    {
        $table = self::table('posts', function (Table $t): void {
            $t->id();
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->bool('published')->default(false);
            $t->decimal('rating', 3, 1)->default(0);
            $t->json('meta')->nullable();
            $t->foreignId('user_id')->references('users')->onDelete(ForeignAction::Cascade);
            $t->timestamps();
            $t->unique(['user_id', 'title']);
            $t->index('published');
        });

        self::assertSame(
            array_values(array_filter(explode("\n", $expected))),
            self::sql($dialect, $table),
        );
    }

    public function testAPostgresBooleanDefaultIsSpelledAsABoolean(): void
    {
        // `DEFAULT 1` against a BOOLEAN column is a type error on PostgreSQL:
        // a DDL literal is typed, unlike a bound parameter, so the rendering
        // of a bool depends on the dialect rather than only on the escaping.
        $table = self::table('flags', fn (Table $t) => $t->bool('on')->default(true));

        self::assertStringContainsString('DEFAULT TRUE', self::sql(Dialect::Pgsql, $table)[0]);
        self::assertStringContainsString('DEFAULT 1', self::sql(Dialect::Sqlite, $table)[0]);
        self::assertStringContainsString('DEFAULT 1', self::sql(Dialect::Mysql, $table)[0]);
    }

    public function testACompositePrimaryKeyIsATableConstraint(): void
    {
        $table = self::table('user_roles', function (Table $t): void {
            $t->bigInt('user_id');
            $t->bigInt('role_id');
            $t->primary('user_id', 'role_id');
        });

        self::assertStringContainsString(
            'PRIMARY KEY ("user_id", "role_id")',
            self::sql(Dialect::Sqlite, $table)[0],
        );
    }

    public function testAColumnLevelUniqueBecomesAnIndexWithADeterministicName(): void
    {
        $table = self::table('users', fn (Table $t) => $t->string('email')->unique());

        self::assertSame(
            [
                'CREATE TABLE "users" ("email" VARCHAR(255) NOT NULL)',
                'CREATE UNIQUE INDEX "users_email_unique" ON "users" ("email")',
            ],
            self::sql(Dialect::Sqlite, $table),
        );
    }

    public function testAForeignKeyCanBeSpelledOutByHandAndStillBeNamed(): void
    {
        $table = self::table('sessions', function (Table $t): void {
            $t->bigInt('user_uuid')->references('users', 'uuid')
                ->onDelete(ForeignAction::SetNull)
                ->onUpdate(ForeignAction::Cascade);
        });

        self::assertStringContainsString(
            'CONSTRAINT "sessions_user_uuid_foreign" FOREIGN KEY ("user_uuid")'
            . ' REFERENCES "users" ("uuid") ON DELETE SET NULL ON UPDATE CASCADE',
            self::sql(Dialect::Sqlite, $table)[0],
        );
    }

    public function testANullableReferenceIsStillNotNullUnlessAskedOtherwise(): void
    {
        $table = self::table('sessions', fn (Table $t) => $t->foreignId('user_id')->references('users'));

        // `foreignId()` does not imply nullable: a foreign key that may be
        // absent is a decision, and the DSL makes it be made explicitly.
        self::assertStringContainsString('"user_id" INTEGER NOT NULL', self::sql(Dialect::Sqlite, $table)[0]);

        $optional = self::table('sessions', fn (Table $t) => $t->foreignId('user_id')->nullable()->references('users'));
        self::assertStringContainsString('"user_id" INTEGER,', self::sql(Dialect::Sqlite, $optional)[0]);
    }

    public function testAnIndexCanBeNamedExplicitly(): void
    {
        $table = self::table('users', function (Table $t): void {
            $t->string('email');
            $t->index('email', 'users_by_email');
        });

        self::assertSame(
            'CREATE INDEX "users_by_email" ON "users" ("email")',
            self::sql(Dialect::Sqlite, $table)[1],
        );
    }

    /**
     * @return array<string, array{Dialect, mixed, string}>
     */
    public static function literals(): array
    {
        return [
            'null' => [Dialect::Sqlite, null, 'NULL'],
            'int' => [Dialect::Sqlite, 42, '42'],
            'float' => [Dialect::Sqlite, 1.5, '1.5'],
            'string' => [Dialect::Sqlite, 'hello', "'hello'"],
            'a quote is doubled' => [Dialect::Sqlite, "O'Brien", "'O''Brien'"],
            'a backslash is ordinary on sqlite' => [Dialect::Sqlite, 'C:\\path', "'C:\\path'"],
            'a backslash is escaped on mysql' => [Dialect::Mysql, 'C:\\path', "'C:\\\\path'"],
            'a quote is doubled on mysql too' => [Dialect::Mysql, "O'Brien", "'O''Brien'"],
            'true on postgres' => [Dialect::Pgsql, true, 'TRUE'],
            'false on postgres' => [Dialect::Pgsql, false, 'FALSE'],
            'true elsewhere' => [Dialect::Sqlite, true, '1'],
            'false elsewhere' => [Dialect::Sqlite, false, '0'],
        ];
    }

    #[DataProvider('literals')]
    public function testAValueRendersAsItsLiteral(Dialect $dialect, mixed $value, string $expected): void
    {
        self::assertSame($expected, self::compiler($dialect)->literal($value));
    }

    public function testADateTimeAndABackedEnumRenderAsTheirValue(): void
    {
        $compiler = self::compiler(Dialect::Sqlite);

        self::assertSame(
            "'2026-09-11 08:30:00'",
            $compiler->literal(new \DateTimeImmutable('2026-09-11 08:30:00')),
        );
        self::assertSame("'draft'", $compiler->literal(PostStatus::Draft));
    }

    public function testAValueWithNoLiteralFormIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(BadSchema::class);

        self::compiler(Dialect::Sqlite)->literal(['not', 'a', 'scalar']);
    }

    public function testAddingColumnsCompilesToOneAlterEach(): void
    {
        $table = self::table('users', function (Table $t): void {
            $t->string('nickname')->nullable();
            $t->bool('verified')->default(false);
        });

        self::assertSame(
            [
                'ALTER TABLE "users" ADD COLUMN "nickname" VARCHAR(255)',
                'ALTER TABLE "users" ADD COLUMN "verified" INTEGER NOT NULL DEFAULT 0',
            ],
            array_map(
                static fn ($s): string => $s->sql,
                self::compiler(Dialect::Sqlite)->addColumns('users', $table->columns()),
            ),
        );
    }

    public function testAddingAReferencingColumnIsRefusedOnMysqlOnly(): void
    {
        $columns = self::table('sessions', fn (Table $t) => $t->foreignId('user_id')->references('users'))
            ->columns();

        // SQLite and PostgreSQL honour an inline REFERENCES on ADD COLUMN.
        self::assertCount(1, self::compiler(Dialect::Sqlite)->addColumns('sessions', $columns));
        self::assertCount(1, self::compiler(Dialect::Pgsql)->addColumns('sessions', $columns));

        // MySQL parses it and throws it away, so the constraint would exist
        // everywhere the developer tested and nowhere it mattered.
        $this->expectException(BadSchema::class);
        $this->expectExceptionMessage('MySQL parses an inline REFERENCES clause');
        self::compiler(Dialect::Mysql)->addColumns('sessions', $columns);
    }

    public function testDroppingATableSaysWhetherItMustExist(): void
    {
        self::assertSame('DROP TABLE "users"', self::compiler(Dialect::Sqlite)->dropTable('users')->sql);
        self::assertSame(
            'DROP TABLE IF EXISTS "users"',
            self::compiler(Dialect::Sqlite)->dropTable('users', true)->sql,
        );
    }

    public function testDroppingAnIndexIsScopedToItsTableOnMysqlOnly(): void
    {
        self::assertSame(
            'DROP INDEX "users_email_unique"',
            self::compiler(Dialect::Sqlite)->dropIndex('users', 'users_email_unique')->sql,
        );
        self::assertSame(
            'DROP INDEX `users_email_unique` ON `users`',
            self::compiler(Dialect::Mysql)->dropIndex('users', 'users_email_unique')->sql,
        );
    }

    /**
     * @return array<string, array{\Closure(Table): void, string}>
     */
    public static function refusals(): array
    {
        return [
            'a duplicate column' => [
                function (Table $t): void {
                    $t->string('email');
                    $t->string('email');
                },
                'declares the column \'email\' twice',
            ],
            'an empty column name' => [
                fn (Table $t) => $t->string(''),
                'has a column with an empty name',
            ],
            'auto-increment on a string' => [
                fn (Table $t) => $t->string('id')->autoIncrement(),
                'cannot auto-increment',
            ],
            'two auto-increment columns' => [
                function (Table $t): void {
                    $t->id();
                    $t->bigInt('seq')->autoIncrement();
                },
                'more than one auto-increment column',
            ],
            'auto-increment beside a composite key' => [
                function (Table $t): void {
                    $t->id();
                    $t->bigInt('tenant_id');
                    $t->primary('id', 'tenant_id');
                },
                'cannot both be the primary key',
            ],
            'a primary key over an undeclared column' => [
                fn (Table $t) => $t->primary('nope'),
                'which the table does not declare',
            ],
            'an index over an undeclared column' => [
                fn (Table $t) => $t->index('nope'),
                'which the table does not declare',
            ],
            'an empty index' => [
                fn (Table $t) => $t->index([]),
                'covers no columns',
            ],
            'two indexes with one name' => [
                function (Table $t): void {
                    $t->string('a');
                    $t->index('a', 'shared');
                    $t->index('a', 'shared');
                },
                'declares an index named \'shared\' twice',
            ],
            'an empty table name' => [
                fn (Table $t) => $t->string('a'),
                'has an empty name',
            ],
        ];
    }

    /**
     * @param \Closure(Table): void $define
     */
    #[DataProvider('refusals')]
    public function testABadDefinitionIsRefusedWithAnImperativeFix(\Closure $define, string $expected): void
    {
        $name = $expected === 'has an empty name' ? '' : 'widgets';

        try {
            // The definition is built inside the try because half of these
            // refusals are eager — Table::add() rejects a duplicate column at
            // the moment it is declared, not when the table is compiled. Both
            // halves are refusals; only the timing differs.
            self::compiler(Dialect::Sqlite)->create(self::table($name, $define));
            self::fail('Expected the definition to be refused.');
        } catch (BadSchema $problem) {
            self::assertSame('bad_schema', $problem->code());
            self::assertStringContainsString($expected, $problem->getMessage());
            self::assertNotSame('', trim($problem->fix), 'Every problem must say how to fix it.');
        }
    }

    public function testEveryColumnOfABadDefinitionIsExaminedBeforeAnythingIsEmitted(): void
    {
        // The refusal happens in validate(), before the first statement is
        // built, so a bad table never leaves a partially emitted migration.
        $table = self::table('widgets', function (Table $t): void {
            $t->string('ok');
            $t->string('bad')->autoIncrement();
        });

        $this->expectException(BadSchema::class);
        self::compiler(Dialect::Sqlite)->create($table);
    }
}

/**
 * A backed enum for the literal case. Named distinctly from the one in
 * CompilerTest because PHPUnit loads every file in a suite into one process,
 * and two enums sharing a name in one namespace is an uncatchable fatal.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
