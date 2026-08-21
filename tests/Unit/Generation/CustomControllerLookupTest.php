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

// Composer's loader memoizes what it failed to find, so a class asked about before
// its file existed would answer missing for the rest of the process. That used to be
// harmless and stopped being so when `spec:make` started running a build after
// scaffolding: two answers about one class in one process, with a file created
// between them.
it('answers again after the file appears', function (): void {
    $class = 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\AppearsLater';
    $path = dirname(__DIR__, 2).'/Fixtures/CustomControllers/AppearsLater.php';
    $lookup = CustomControllerLookup::fromAutoloader();

    expect($lookup->exists($class))->toBeFalse();

    file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n");

    try {
        expect($lookup->exists($class))->toBeTrue()
            ->and(CustomControllerLookup::fromAutoloader()->exists($class))->toBeTrue();
    } finally {
        @unlink($path);
    }
});

// Where a class would go, which is the question `spec:make` asks: the class does not
// exist yet, so there is no file to find and the prefixes are all there is.
it('says where a class the project maps would live', function (): void {
    expect(CustomControllerLookup::fromAutoloader()
        ->pathFor('Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\NotWrittenYet'))
        ->toBe(dirname(__DIR__, 2).'/Fixtures/CustomControllers/NotWrittenYet.php');
});

// Null rather than a guess: a path invented from an unmapped namespace would put a
// file where nothing ever looks for it.
it('says nothing about a class in a namespace nothing maps', function (): void {
    expect(CustomControllerLookup::fromAutoloader()->pathFor('Acme\\Nowhere\\Thing'))->toBeNull();
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
