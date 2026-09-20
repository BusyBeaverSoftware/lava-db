<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Query;

use Lava\Db\Problem\BadQuery;
use Lava\Db\Query\ConditionGroup;
use Lava\Db\Query\DeleteQuery;
use Lava\Db\Query\Direction;
use Lava\Db\Query\InsertQuery;
use Lava\Db\Query\Operator;
use Lava\Db\Query\QueryBuilder;
use Lava\Db\Query\SelectQuery;
use Lava\Db\Query\UpdateQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The builder's refusals, which are the pack's most agent-facing behaviour.
 *
 * Every case here is a query that a permissive builder would have compiled
 * into SQL that runs and returns the wrong answer: `= NULL` matching nothing,
 * an empty `IN ()`, a `DELETE` with no `WHERE` emptying the table, a
 * multi-row INSERT whose rows disagree about their columns and therefore
 * shift values into the wrong columns. None of those throw at the database.
 * So the builder refuses them, and the test asserts the refusal names both
 * the input and the fix.
 */
final class QueryBuilderTest extends TestCase
{
    /** @return array<string, array{\Closure(QueryBuilder): void, string}> */
    public static function refusals(): array
    {
        return [
            // Quoted as an identifier, and SQLite answers an unknown quoted
            // identifier with the string itself (Lava Notes, B5).
            'an expression where a column goes' => [
                static fn (QueryBuilder $q) => $q->select('id', 'COUNT(*)'),
                'escape hatch',
            ],
            'comparing to null' => [
                static fn (QueryBuilder $q) => $q->where('deleted_at', Operator::Eq, null),
                'whereNull',
            ],
            'an operator that needs its own method' => [
                static fn (QueryBuilder $q) => $q->where('id', Operator::In, [1, 2]),
                'whereIn',
            ],
            'an empty IN' => [
                static fn (QueryBuilder $q) => $q->whereIn('id', []),
                'at least one value',
            ],
            'an array as a scalar value' => [
                static fn (QueryBuilder $q) => $q->where('id', Operator::Eq, [1, 2]),
                'whereIn',
            ],
            'an object that is not bindable' => [
                static fn (QueryBuilder $q) => $q->where('id', Operator::Eq, new \stdClass()),
                'stdClass',
            ],
            'an empty raw fragment' => [
                static fn (QueryBuilder $q) => $q->whereRaw('   '),
                'empty SQL fragment',
            ],
            'a negative limit' => [
                static fn (QueryBuilder $q) => $q->limit(-1),
                'Pass 0 or more',
            ],
            'a negative offset' => [
                static fn (QueryBuilder $q) => $q->offset(-5),
                'Pass 0 or more',
            ],
            'a delete with no where' => [
                static fn (QueryBuilder $q) => $q->delete(),
                "->whereRaw('1 = 1')",
            ],
            'an update with no where' => [
                static fn (QueryBuilder $q) => $q->update(['name' => 'ada']),
                "->whereRaw('1 = 1')",
            ],
            'an update with no columns' => [
                static fn (QueryBuilder $q) => $q->where('id', Operator::Eq, 1)->update([]),
                'no columns',
            ],
            'an insert with no columns' => [
                static fn (QueryBuilder $q) => $q->insert([]),
                'no columns',
            ],
            'an insert with no rows' => [
                static fn (QueryBuilder $q) => $q->insertMany([]),
                'no columns',
            ],
            'rows that disagree about their columns' => [
                static fn (QueryBuilder $q) => $q->insertMany([['name' => 'ada'], ['nome' => 'grace']]),
                'same columns',
            ],
            // Lava Notes (R2-B1): select() refused an expression and every other
            // column position quoted it into a string comparison instead.
            'an expression in where()' => [
                static fn (QueryBuilder $q) => $q->where('LOWER(email)', Operator::Eq, 'ada@example.test'),
                'whereRaw(',
            ],
            'an expression in whereIn()' => [
                static fn (QueryBuilder $q) => $q->whereIn('LOWER(email)', ['ada@example.test']),
                'whereRaw(',
            ],
            'an expression in whereNull()' => [
                static fn (QueryBuilder $q) => $q->whereNull('COALESCE(nickname, name)'),
                'whereRaw(',
            ],
            'an expression in whereBetween()' => [
                static fn (QueryBuilder $q) => $q->whereBetween('LENGTH(name)', 1, 5),
                'whereRaw(',
            ],
            'an expression in a group' => [
                static fn (QueryBuilder $q) => $q->whereGroup(
                    static fn (ConditionGroup $group) => $group->where('LOWER(email)', Operator::Eq, 'ada@example.test'),
                ),
                'whereRaw(',
            ],
            'a star in where()' => [
                static fn (QueryBuilder $q) => $q->where('*', Operator::Eq, 1),
                'whereRaw(',
            ],
            'an expression in orderBy()' => [
                static fn (QueryBuilder $q) => $q->orderBy('LENGTH(email)', Direction::Desc),
                'query(',
            ],
            'an alias as a join table' => [
                static fn (QueryBuilder $q) => $q->innerJoin('users AS u2', 'users.id', 'u2.id'),
                'query(',
            ],
            'an expression as a join column' => [
                static fn (QueryBuilder $q) => $q->leftJoin('posts', 'LOWER(users.email)', 'posts.email'),
                'query(',
            ],
            'an expression as an insert column' => [
                static fn (QueryBuilder $q) => $q->insert(['LOWER(email)' => 'ada@example.test']),
                'statement(',
            ],
            'an expression as an update column' => [
                static fn (QueryBuilder $q) => $q->where('id', Operator::Eq, 1)->update(['name || nickname' => 'x']),
                'statement(',
            ],
        ];
    }

    public function testAColumnNameOutsideAsciiOrQualifiedByASchemaIsAccepted(): void
    {
        // Lava Notes (R2-B8): both worked on 0.1.2, and 0.2.0's select() check
        // refused them with an explanation that did not apply to real columns.
        $select = (new QueryBuilder('users'))
            ->select('prénom', 'main.users.name', 'users.*')
            ->where('prénom', Operator::Eq, 'Ada')
            ->innerJoin('main.posts', 'main.posts.user_id', 'users.id')
            ->orderBy('main.users.name')
            ->toSelect();

        self::assertSame(['prénom', 'main.users.name', 'users.*'], $select->columns);
        self::assertSame(
            (new QueryBuilder('users'))->insert(['prénom' => 'Ada'])->rows,
            [['prénom' => 'Ada']],
        );
    }

    public function testATableNameIsCheckedLikeEveryOtherIdentifier(): void
    {
        // It was the one identifier position with no check at all: a join's
        // table went through the grammar and the FROM table took any string,
        // so this compiled (correctly quoted, so never an injection — the
        // asymmetry was the bug). Security review.
        foreach (["a\" b'c;--", "users\n", "users\0", 'users; DROP TABLE x', 'LOWER(users)', ''] as $table) {
            try {
                new QueryBuilder($table);
                self::fail("the builder took '" . addcslashes($table, "\0\r\n") . "' as a table");
            } catch (BadQuery $problem) {
                self::assertSame('bad_query', $problem->code());
                self::assertStringContainsString('table()', $problem->getMessage() . ' ' . $problem->fix);
            }
        }

        // The grammar every other position uses, unchanged: a qualified name is
        // still a name, as `innerJoin('main.posts', …)` has been since R2-B8.
        self::assertSame('users', (new QueryBuilder('users'))->table());
        self::assertSame('main.posts', (new QueryBuilder('main.posts'))->table());
    }

    public function testANameWithATrailingNewlineIsNotAName(): void
    {
        // PCRE's `$` also matches immediately before a final newline, so
        // `"owner\n"` passed the check everywhere a name is taken. It is still
        // quoted, so it was never an injection — but on SQLite an unresolvable
        // quoted identifier becomes the STRING of its own text, so
        // `where("owner\n", Eq, $x)` was a silently constant term, and an
        // authorisation filter written that way failed open or closed by
        // accident of its polarity (security review).
        foreach (["owner\n", "owner\r\n", "main.posts.title\n"] as $name) {
            try {
                (new QueryBuilder('users'))->where($name, Operator::Eq, 'x');
                self::fail("the builder took '" . addcslashes($name, "\r\n") . "' as a name");
            } catch (BadQuery $problem) {
                self::assertSame('bad_query', $problem->code());
            }
        }

        try {
            (new QueryBuilder('users'))->select(["author\n" => 'users.name']);
            self::fail('the builder took an alias with a trailing newline');
        } catch (BadQuery $problem) {
            self::assertSame('bad_query', $problem->code());
        }
    }

    /**
     * @param \Closure(QueryBuilder): void $build
     */
    #[DataProvider('refusals')]
    public function testARefusalNamesTheInputAndTheFix(\Closure $build, string $fixHint): void
    {
        $builder = new QueryBuilder('users');

        try {
            $build($builder);
            self::fail('the builder should have refused to build a statement');
        } catch (BadQuery $problem) {
            self::assertSame('bad_query', $problem->code());
            self::assertNotSame('', $problem->fix, 'a problem without a fix is not actionable');
            self::assertStringContainsString(
                $fixHint,
                $problem->getMessage() . ' ' . $problem->fix,
                'the diagnosis should name what to write instead',
            );
        }
    }

    public function testTheEscapeHatchIsExplicitRatherThanAbsent(): void
    {
        // Refusing an unbounded DELETE is only reasonable if there is a way to
        // say "yes, every row" — and it must be visible in the diff. This is
        // that way, and it compiles.
        $query = (new QueryBuilder('users'))->whereRaw('1 = 1')->delete();

        self::assertInstanceOf(DeleteQuery::class, $query);
    }

    public function testAChainFreezesIntoTheQueryItsTerminalNames(): void
    {
        $builder = new QueryBuilder('users');

        self::assertInstanceOf(SelectQuery::class, $builder->toSelect());
        self::assertInstanceOf(SelectQuery::class, $builder->query());
        self::assertInstanceOf(InsertQuery::class, $builder->insert(['name' => 'ada']));
        self::assertInstanceOf(InsertQuery::class, $builder->insertMany([['name' => 'ada']]));
        self::assertInstanceOf(UpdateQuery::class, $builder->where('id', Operator::Eq, 1)->update(['name' => 'grace']));
        self::assertInstanceOf(DeleteQuery::class, $builder->where('id', Operator::Eq, 1)->delete());
    }

    public function testConditionsAreReadableWithoutFreezingTheQuery(): void
    {
        // `(new QueryBuilder('users'))->…`, never `new QueryBuilder('users')->…`:
        // the parentheses-free form is PHP 8.4 syntax and a PARSE error on 8.3,
        // which the `^8.3` floor promises to support. CI's 8.3 job caught this
        // line; nothing local could, which is why the floor is now linted
        // locally too (composer check:floor, tools/php-floor-check.php).
        $builder = (new QueryBuilder('users'))->where('id', Operator::Eq, 1)->orWhereNull('deleted_at');

        self::assertCount(2, $builder->conditions());
        self::assertFalse($builder->conditions()[0]->or);
        self::assertTrue($builder->conditions()[1]->or);
    }

    public function testTheBuilderKeepsItsConditionsForEveryTerminal(): void
    {
        // The builder is mutable by design, and the honest consequence is that
        // conditions survive reuse. Asserting it here means the behaviour is
        // documented rather than discovered.
        // Parenthesised for the same 8.3 reason as the test above.
        $builder = (new QueryBuilder('users'))->where('id', Operator::Eq, 1);

        self::assertCount(1, $builder->update(['name' => 'ada'])->conditions);
        self::assertCount(1, $builder->delete()->conditions);
    }

    public function testSelectReplacesTheColumnList(): void
    {
        $builder = new QueryBuilder('users');

        self::assertSame(['*'], $builder->toSelect()->columns);
        self::assertSame(['id'], $builder->select('id')->toSelect()->columns);
        self::assertSame(['*'], $builder->select()->toSelect()->columns);
    }

    public function testOrderByDefaultsToAscending(): void
    {
        $orders = (new QueryBuilder('users'))->orderBy('name')->orderBy('id', Direction::Desc)->toSelect()->orders;

        self::assertSame(Direction::Asc, $orders[0]->direction);
        self::assertSame(Direction::Desc, $orders[1]->direction);
    }
}
