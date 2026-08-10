<?php

declare(strict_types=1);

// A test directory that is not registered runs zero tests and reports nothing,
// which is the failure mode this package refuses everywhere else. It cost one
// round-trip to notice when tests/Conformance was added; the next one would
// cost however long nobody looked.

it('runs every test directory it has', function (): void {
    $root = dirname(__DIR__);

    $directories = array_values(array_filter(
        scandir($root) ?: [],
        static fn (string $entry): bool => $entry !== '.'
            && $entry !== '..'
            && $entry !== 'Fixtures'
            && is_dir($root.'/'.$entry)
    ));

    $configuration = file_get_contents(dirname($root).'/phpunit.xml.dist');
    assert(is_string($configuration));

    foreach ($directories as $directory) {
        // Not `toContain`, which takes several needles rather than a message.
        expect(str_contains($configuration, '<directory>tests/'.$directory.'</directory>'))
            ->toBeTrue("tests/{$directory} is not registered in phpunit.xml.dist, so nothing in it runs");
    }
});
