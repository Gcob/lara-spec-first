<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\SpecException;

/**
 * @return list<class-string>
 */
function exceptionClassesInSrc(): array
{
    $src = dirname(__DIR__, 2).'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
    $classes = [];

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Exception.php')) {
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

// SpecException promises a consuming application that one `catch` is enough.
// Scanning the tree rather than naming a namespace is what makes the promise
// survive the first exception born in Generation\ or Console\ — an assertion
// scoped to Parsing\Exceptions would stay green while the promise broke.
it('finds every exception in the package', function (): void {
    expect(exceptionClassesInSrc())->not->toBeEmpty();
});

it('makes every exception catchable as one type', function (): void {
    foreach (exceptionClassesInSrc() as $class) {
        expect(interface_exists($class) || is_a($class, SpecException::class, true))
            ->toBeTrue("{$class} must implement ".SpecException::class);
    }
});
