<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\CustomControllerLookup;
use Gcob\LaraSpecFirst\Tests\Fixtures\CustomControllers\WrittenController;

/*
 * The question the build asks about an `x-controller` value, asked against the
 * real autoloader rather than a stand-in: whether a file for that class can be
 * found. A mocked loader would prove the mock answers.
 *
 * @see docs/guide/controllers.md — "Two classes, found by name rather than by a scan"
 */

it('finds a class the autoloader knows', function (): void {
    expect(CustomControllerLookup::fromAutoloader()->exists(WrittenController::class))->toBeTrue();
});

it('does not find a class nobody has written', function (): void {
    expect(CustomControllerLookup::fromAutoloader()->exists('App\\Http\\Controllers\\NotWrittenYetController'))
        ->toBeFalse();
});

// The file, not the class: the answer must not depend on whether PHP could load
// it, because a child extending a parent this build has not written yet is exactly
// what a first build meets. Asserted as the absence of a load — the class is found,
// and afterwards it is still undeclared.
it('answers without loading the class', function (): void {
    // Named as a string rather than through `::class`, so that nothing about
    // referencing it can be confused with loading it.
    $class = 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\NeverLoadedController';

    expect(class_exists($class, autoload: false))->toBeFalse('something loaded the fixture already')
        ->and(CustomControllerLookup::fromAutoloader()->exists($class))->toBeTrue()
        ->and(class_exists($class, autoload: false))->toBeFalse('the lookup loaded the class');
});
