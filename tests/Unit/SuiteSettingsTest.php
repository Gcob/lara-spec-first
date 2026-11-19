<?php

declare(strict_types=1);

// CI's PHP setup action ships a production php.ini, where assertions are off, and a
// contributor's own PHP usually has them on. A suite that runs differently in each
// passed locally and failed in CI once, on an assert() the first run executed and
// the second one skipped. `composer test` now sets the value, so every run agrees.

it('runs with assertions on, as composer test sets them', function (): void {
    $assertions = ini_get('zend.assertions');

    expect($assertions)->toBe(
        '1',
        'zend.assertions is '.var_export($assertions, true).', not \'1\'. Run the suite through `composer test`, '
        .'which sets zend.assertions=1 on the command line, as CI does; vendor/bin/pest alone runs on whatever '
        .'your php.ini says.'
    );
});
