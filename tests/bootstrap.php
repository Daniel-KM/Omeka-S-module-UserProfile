<?php declare(strict_types=1);

/**
 * Bootstrap file for UserProfile module tests.
 *
 * Use Common module Bootstrap helper for test setup.
 */

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    [
        'Common',
        'UserProfile',
    ],
    'UserProfileTest',
    __DIR__ . '/UserProfileTest'
);
