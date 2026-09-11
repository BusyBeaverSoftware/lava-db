<?php

declare(strict_types=1);

use Lava\Db\Connection;
use Lava\Db\Migration\Migration;
use Lava\Db\Schema\ForeignAction;
use Lava\Db\Schema\Table;

/**
 * The second migration on purpose: it references a table the first one
 * creates, so applying them out of filename order fails at DDL time, and
 * rolling them back in the order they were applied fails too. One fixture
 * file therefore proves ordering in both directions.
 */
return new class extends Migration
{
    public function up(Connection $db): void
    {
        $db->schema()->create('posts', function (Table $t): void {
            $t->id();
            $t->bigInt('user_id')->references('users')->onDelete(ForeignAction::Cascade);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->timestamps();

            $t->index('user_id');
        });
    }

    public function down(Connection $db): void
    {
        $db->schema()->dropIfExists('posts');
    }
};
