<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Http\Controllers\SpecController;

/*
 * The base class every generated controller extends. Small enough to look like it
 * needs no test, and each fact below is a promise this package makes in writing.
 *
 * That it implements Laravel's `HasMiddleware` is asserted in
 * tests/Unit/ArchitectureTest.php instead: it is a structural convention rather
 * than a behaviour, and asserting it here with `is_subclass_of` was a tautology
 * static analysis could prove — a test that cannot fail is not a guard.
 */

// The load-bearing half: nothing the specification derives lives in here.
// Laravel combines route middleware with controller middleware rather than
// replacing one with the other, so a child that overrides `middleware()` and
// forgets `parent::middleware()` cannot drop its own security — because there is
// nothing of ours to preserve. That only stays true while this returns nothing.
it('contributes no middleware of its own', function (): void {
    expect(SpecController::middleware())->toBe([]);
});

it('cannot be instantiated on its own', function (): void {
    expect((new ReflectionClass(SpecController::class))->isAbstract())->toBeTrue();
});
