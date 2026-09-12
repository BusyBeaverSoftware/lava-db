<?php

declare(strict_types=1);

use Lava\Core\Modules\ModuleRef;

return [
    // The pack this fixture exists to exercise, enabled unconditionally: the
    // interesting failures in lavaphp/db are the ones the database reports, not
    // the ones the feature gate reports.
    ModuleRef::of(\Lava\Db\DbModule::class, package: 'lavaphp/db', feature: 'db'),
];
