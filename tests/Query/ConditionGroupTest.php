<?php

declare(strict_types=1);

namespace Lava\Db\Tests\Query;

use Lava\Core\Problem\LavaProblem;
use Lava\Db\Problem\BadQuery;
use Lava\Db\Query\Condition;
use Lava\Db\Query\ConditionGroup;
use Lava\Db\Query\Operator;
use Lava\Db\Query\QueryBuilder;
use Lava\Db\Sql\Compiler;
use Lava\Db\Sql\Dialect;
use PHPUnit\Framework\TestCase;

/**
 * `whereGroup` — the parentheses a chain could not write.
 *
 * Without it, `[a AND, b OR, c AND]` compiles to `a AND b OR c`, which SQL
 * reads as `(a AND b) OR c`: the chain reads like a grouped boolean expression
 * and is not one, and `whereRaw` was the only way to say what you meant. This
 * pins the four things that make the replacement honest rather than merely
 * available:
 *
 *  - the group is RENDERED by the compiler (parentheses, quoting, placeholders)
 *    and not pre-rendered by the builder, which has no dialect to quote with;
 *  - bindings stay in placeholder order across the nesting, which is the one
 *    thing a raw fragment could get wrong while looking right;
 *  - a group is refused when empty, because `()` is a syntax error on every
 *    dialect and a closure that added nothing meant something it did not say;
 *  - the closure is handed a {@see ConditionGroup}, which has the condition
 *    vocabulary and nothing else — a `limit()` there would have been accepted
 *    and dropped, and silently dropping a call is what this pack refuses.
 */
final class ConditionGroupTest extends TestCase
{
    /** @param list<Condition> $conditions */
    private static function compile(QueryBuilder $builder): string
    {
        return (new Compiler(Dialect::Sqlite))->compile($builder->toSelect())->sql;
    }

    public function testAGroupRendersParenthesesAndKeepsTheChainAroundIt(): void
    {
        $sql = self::compile(
            (new QueryBuilder('users'))
                ->where('active', Operator::Eq, 1)
                ->whereGroup(function (ConditionGroup $group): void {
                    $group->where('role', Operator::Eq, 'admin')->orWhere('plan', Operator::Eq, 'pro');
                })
                ->where('verified', Operator::Eq, 1),
        );

        // `(A OR B) AND C` — the reading a caller intends, and the one a flat
        // chain cannot express.
        self::assertSame(
            'SELECT * FROM "users" WHERE "active" = ? AND ("role" = ? OR "plan" = ?) AND "verified" = ?',
            $sql,
        );
    }

    public function testOrWhereGroupJoinsTheGroupWithOr(): void
    {
        $sql = self::compile(
            (new QueryBuilder('users'))
                ->where('active', Operator::Eq, 1)
                ->orWhereGroup(function (ConditionGroup $group): void {
                    $group->where('role', Operator::Eq, 'admin')->orWhere('plan', Operator::Eq, 'pro');
                }),
        );

        self::assertSame(
            'SELECT * FROM "users" WHERE "active" = ? OR ("role" = ? OR "plan" = ?)',
            $sql,
        );
    }

    public function testAGroupWithOneConditionStillGetsItsParenthesesAndAFirstTermWithNoPrefix(): void
    {
        // The first condition inside a group takes no AND/OR prefix, exactly as
        // the first condition of a chain does — the group body is the same
        // routine, so there is no second rule to learn.
        $sql = self::compile(
            (new QueryBuilder('users'))->whereGroup(function (ConditionGroup $group): void {
                $group->where('role', Operator::Eq, 'admin');
            }),
        );

        self::assertSame('SELECT * FROM "users" WHERE ("role" = ?)', $sql);
    }

    public function testAGroupNestsInsideAGroup(): void
    {
        // `(a AND (b OR c))` — recursion, not a special case.
        $sql = self::compile(
            (new QueryBuilder('users'))->whereGroup(function (ConditionGroup $group): void {
                $group->where('active', Operator::Eq, 1)->whereGroup(function (ConditionGroup $inner): void {
                    $inner->where('role', Operator::Eq, 'admin')->orWhere('plan', Operator::Eq, 'pro');
                });
            }),
        );

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = ? AND ("role" = ? OR "plan" = ?))',
            $sql,
        );
    }

    public function testBindingsStayInPlaceholderOrderAcrossTheNesting(): void
    {
        // The failure a raw fragment invites: right SQL, values in the wrong
        // slots. Asserting the pairs makes the order the claim, not the text.
        $compiled = (new Compiler(Dialect::Sqlite))->compile(
            (new QueryBuilder('users'))
                ->where('a', Operator::Eq, 'first')
                ->whereGroup(function (ConditionGroup $group): void {
                    $group->where('b', Operator::Eq, 'second')->orWhere('c', Operator::Eq, 'third');
                })
                ->where('d', Operator::Eq, 'fourth')
                ->toSelect(),
        );

        self::assertSame(['first', 'second', 'third', 'fourth'], $compiled->bindings);
    }

    public function testTheGroupBodyIsQuotedByTheCompilerNotByTheCaller(): void
    {
        // `group` is reserved in all three dialects. If a group were rendered by
        // the builder (which has no dialect) the column would arrive unquoted
        // and this SQL would be a syntax error; that it IS quoted is the proof
        // the compiler rendered the group.
        $sql = self::compile(
            (new QueryBuilder('order'))->whereGroup(function (ConditionGroup $group): void {
                $group->where('group', Operator::Eq, 'a');
            }),
        );

        self::assertSame('SELECT * FROM "order" WHERE ("group" = ?)', $sql);
    }

    public function testAnEmptyGroupIsRefusedWithTheFixRatherThanCompiledAsEmptyParentheses(): void
    {
        $problem = null;
        try {
            (new QueryBuilder('users'))->whereGroup(static function (ConditionGroup $group): void {});
        } catch (LavaProblem $caught) {
            $problem = $caught;
        }

        self::assertInstanceOf(BadQuery::class, $problem);
        self::assertSame('bad_query', $problem->code());
        self::assertStringContainsString('no conditions', $problem->getMessage());
        self::assertStringContainsString('whereGroup', $problem->fix);
        self::assertStringContainsString('at least one condition', $problem->fix);
        self::assertSame([], $problem->context);
    }

    public function testAGroupIsAConditionAndReadsBackAsOne(): void
    {
        // `conditions()` is how a diagnostic (or a test) reads a chain back, and
        // a group has to appear there — otherwise the two things the query does
        // depend on differ: the compiled SQL would carry the group while the
        // readable form did not.
        $builder = (new QueryBuilder('users'))->whereGroup(function (ConditionGroup $group): void {
            $group->where('role', Operator::Eq, 'admin');
        });

        $conditions = $builder->conditions();
        self::assertCount(1, $conditions);
        self::assertTrue($conditions[0]->isGroup());
        self::assertFalse($conditions[0]->or);
        self::assertCount(1, $conditions[0]->group);
        self::assertFalse($conditions[0]->group[0]->isGroup());
    }

    public function testTheGroupClosureCanOnlyAddConditions(): void
    {
        // The restriction is a TYPE, not a rule to remember: a QueryBuilder in
        // the closure would accept `->limit(5)` and the group would drop it.
        // Asserting the absence of the terminal vocabulary is what keeps this
        // from regressing into "hand it the builder, it's easier".
        $shared = ['where', 'orWhere', 'whereNull', 'whereIn', 'whereBetween', 'whereRaw', 'whereGroup'];
        foreach ($shared as $method) {
            self::assertTrue(
                method_exists(ConditionGroup::class, $method),
                "a group must accept {$method}(), like a chain does",
            );
        }

        foreach (['select', 'innerJoin', 'orderBy', 'limit', 'offset', 'toSelect', 'insert', 'update', 'delete'] as $method) {
            self::assertFalse(
                method_exists(ConditionGroup::class, $method),
                "{$method}() means nothing inside a group, so a group must not accept it",
            );
        }

        // And the two share the bodies rather than a copy of them, which is what
        // keeps `IN ()` and `= NULL` refused identically in both.
        self::assertContains(
            \Lava\Db\Query\HasConditions::class,
            class_uses(QueryBuilder::class),
        );
    }

    public function testAGroupIsNotAQueryBuilder(): void
    {
        // Stated because the alternative design was to hand the closure a
        // QueryBuilder and document what it must not call. Sharing the methods
        // is the point; sharing the type would be the bug.
        self::assertFalse(is_a(ConditionGroup::class, QueryBuilder::class, true));
        self::assertFalse(is_a(QueryBuilder::class, ConditionGroup::class, true));
    }

    public function testWhereRawInsideAGroupIsStillRawAndStillBound(): void
    {
        // The escape hatch is available inside a group too, and it does not
        // become a second rendering path: its bindings join the same list in
        // the same order.
        $compiled = (new Compiler(Dialect::Sqlite))->compile(
            (new QueryBuilder('users'))
                ->whereGroup(function (ConditionGroup $group): void {
                    $group->whereRaw('LOWER("email") = ?', ['ada@example.com'])
                        ->orWhere('role', Operator::Eq, 'admin');
                })
                ->toSelect(),
        );

        self::assertSame(
            'SELECT * FROM "users" WHERE (LOWER("email") = ? OR "role" = ?)',
            $compiled->sql,
        );
        self::assertSame(['ada@example.com', 'admin'], $compiled->bindings);
    }

    public function testAGroupIsRefusedAsAnEmptyConditionListForADelete(): void
    {
        // The group counts as a condition even when the query would otherwise be
        // unbounded — `->whereGroup(...)->delete()` is bounded, and this asserts
        // the refusal that guards an unbounded DELETE does not fire here.
        $query = (new QueryBuilder('users'))->whereGroup(function (ConditionGroup $group): void {
            $group->where('id', Operator::Eq, 1);
        })->delete();

        self::assertSame(
            'DELETE FROM "users" WHERE ("id" = ?)',
            (new Compiler(Dialect::Sqlite))->compile($query)->sql,
        );
    }
}
