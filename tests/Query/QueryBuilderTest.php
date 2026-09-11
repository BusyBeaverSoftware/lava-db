<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Query;

use Lava\Db\Problem\BadQuery;
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
        ];
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
        // line; nothing local could, which is why the floor is now parsed
        // locally too (tools/php-version-check.php).
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
