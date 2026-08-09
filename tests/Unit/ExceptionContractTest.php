<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\SpecException;

/**
 * Every class the package declares, derived from the file tree.
 *
 * Deliberately not filtered by filename: keying off a `*Exception.php`
 * convention would make this test depend on a rule nothing else asserts, and
 * the first exception named otherwise would slip through while the suite stayed
 * green. Asking PHP what is a Throwable costs the same and cannot drift.
 *
 * @return list<class-string>
 */
function classesInSrc(): array
{
    $src = dirname(__DIR__, 2).'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
    $classes = [];

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($src) + 1, -4);
        /** @var class-string $class */
        $class = 'Gcob\LaraSpecFirst\\'.str_replace('/', '\\', $relative);
        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

it('finds the classes it is about to check', function (): void {
    expect(classesInSrc())->not->toBeEmpty();
});

// SpecException promises a consuming application that one `catch` is enough.
// Walking the tree rather than naming a namespace is what makes the promise
// survive the first exception born in Generation\ or Console\ — an assertion
// scoped to Parsing\Exceptions would stay green while the promise broke.
it('makes every throwable in the package catchable as one type', function (): void {
    foreach (classesInSrc() as $class) {
        if (! is_a($class, Throwable::class, true)) {
            continue;
        }

        expect(is_a($class, SpecException::class, true))
            ->toBeTrue("{$class} is throwable, so it must implement ".SpecException::class);
    }
});
