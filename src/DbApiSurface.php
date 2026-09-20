<?php

declare(strict_types=1);

namespace Lava\Db;

use Lava\Core\Map\ApiSurface;

/** `lavaphp/db`'s public surface: the connection, the query builder, the schema and migrations. */
final class DbApiSurface extends ApiSurface
{
    public function pack(): string
    {
        return 'db';
    }

    public function package(): string
    {
        return 'lavaphp/db';
    }

    public function feature(): string
    {
        return 'db';
    }

    public function namespacePrefix(): string
    {
        return 'Lava\\Db\\';
    }

    public function sourceRoot(): string
    {
        return __DIR__;
    }

    public function groups(): array
    {
        return [
            '(root)' => 'the connection and the module',
            'Query' => 'the query builder and the value objects it composes',
            'Schema' => 'creating and changing tables',
            'Migration' => 'migrations and the runner behind db:migrate',
        ];
    }

    public function exclusions(): array
    {
        return [
            'Problem/' => 'every problem is catalogued in docs/problem-codes.md, under its own drift guard',
            'Console/' => '`lava list` names every command with its flags and the schema its envelope claims',
            'Sql/' => 'the compiler turns a query object into SQL for one dialect; an app builds the query and never the SQL',
        ];
    }

    public function examples(): array
    {
        return [
            \Lava\Db\Connection::class => <<<'PHP'
                use Lava\Db\Connection;

                function recent(Connection $db): array
                {
                    // The connection is a singleton built WITHOUT connecting: nothing
                    // reaches the driver until the first query.
                    return $db->fetch($db->table('posts')->limit(10)->toSelect());
                }
                PHP,

            \Lava\Db\Query\QueryBuilder::class => <<<'PHP'
                use Lava\Db\Connection;
                use Lava\Db\Query\Direction;
                use Lava\Db\Query\Operator;
                use Lava\Db\Query\QueryBuilder;

                function published(Connection $db): array
                {
                    // $db->table() hands back a QueryBuilder; every method on it
                    // returns the builder, so a query reads as one sentence.
                    $query = $db->table('posts')
                        ->select('id', 'title', ['author' => 'users.name'])
                        ->innerJoin('users', 'users.id', 'posts.user_id')
                        ->where('status', Operator::Eq, 'published')
                        ->orderBy('published_at', Direction::Desc)
                        ->limit(10);

                    // Two columns that would come back under one name is bad_query,
                    // not a silently dropped value — alias one, as above.
                    return $db->fetch($query->toSelect());
                }
                PHP,

            \Lava\Db\Schema\Schema::class => <<<'PHP'
                use Lava\Db\Connection;
                use Lava\Db\Schema\Table;

                function define(Connection $db): void
                {
                    $db->schema()->create('posts', function (Table $table): void {
                        $table->id();
                        $table->string('title', 200);
                        $table->timestamps();
                    });
                }
                PHP,

            \Lava\Db\Migration\Migration::class => <<<'PHP'
                use Lava\Db\Connection;
                use Lava\Db\Migration\Migration;
                use Lava\Db\Schema\Table;

                return new class () extends Migration {
                    public function up(Connection $db): void
                    {
                        $db->schema()->table('posts', function (Table $table): void {
                            $table->unique('slug');
                        });
                    }

                    // Every up() has a down(), so `lava db:rollback` is not a guess.
                    public function down(Connection $db): void
                    {
                        $db->schema()->dropIndex('posts', 'posts_slug_unique');
                    }
                };
                PHP,
        ];
    }
}
