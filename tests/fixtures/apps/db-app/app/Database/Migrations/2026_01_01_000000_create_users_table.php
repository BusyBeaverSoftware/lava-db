<?php

declare(strict_types=1);

use Lava\Db\Connection;
use Lava\Db\Migration\Migration;
use Lava\Db\Schema\Table;

return new class extends Migration
{
    public function up(Connection $db): void
    {
        $db->schema()->create('users', function (Table $t): void {
            $t->id();
            $t->string('email')->unique();
            $t->string('display_name', 120)->nullable();
            $t->bool('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(Connection $db): void
    {
        $db->schema()->dropIfExists('users');
    }
};
