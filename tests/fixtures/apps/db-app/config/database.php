<?php

declare(strict_types=1);

/**
 * The pack's defaults, all deliberately blank.
 *
 * There is no useful default DSN: a relative SQLite path would depend on the
 * working directory, and a checked-in file path would make the fixture write
 * into the repository. Every test supplies DATABASE_DSN instead, which is also
 * what a deploy does — the environment overrides the file. The keys are here
 * so that `lava config` has something to show and so the pack's
 * `configFiles: ['database']` declaration is exercised rather than merely
 * asserted.
 *
 * Blank means unset, not "connect to nothing": DbModule treats '' as absent, so
 * an app with these values gets `db_not_configured` naming DATABASE_DSN, rather
 * than a driver error about the scheme ''.
 */
return [
    'dsn' => '',
    'user' => '',
    'password' => '',
];
