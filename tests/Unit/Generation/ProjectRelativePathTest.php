<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\ProjectRelativePath;

/*
 * An absolute path must never reach a generated file. Whether a project commits
 * its generated tree is its own choice, so an absolute path is a defect waiting
 * for the first project that does: it differs between every developer and every
 * CI runner, which is a diff nobody made, and it publishes one machine's
 * directory layout — username included — into a repository.
 */

function projectAt(string $root): string
{
    mkdir($root.'/nested/deeper', 0o777, true);
    file_put_contents($root.'/composer.json', '{}');

    return $root;
}

it('names a path from the directory holding composer.json', function (): void {
    $root = projectAt(sys_get_temp_dir().'/lsf-rel-'.bin2hex(random_bytes(6)));

    try {
        expect(ProjectRelativePath::from($root.'/openapi.yaml'))->toBe('openapi.yaml')
            ->and(ProjectRelativePath::from($root.'/nested/openapi.yaml'))->toBe('nested/openapi.yaml')
            ->and(ProjectRelativePath::from($root.'/nested/deeper/openapi.yaml'))
            ->toBe('nested/deeper/openapi.yaml');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

// The nearest root wins, which is what makes the rule work in a monorepo and in
// this package's own Workbench: a `composer.json` further up is not the project
// the file belongs to.
it('stops at the nearest project root rather than the outermost', function (): void {
    $outer = projectAt(sys_get_temp_dir().'/lsf-rel-'.bin2hex(random_bytes(6)));
    $inner = projectAt($outer.'/packages/inner');

    try {
        expect(ProjectRelativePath::from($inner.'/openapi.yaml'))->toBe('openapi.yaml');
    } finally {
        exec('rm -rf '.escapeshellarg($outer));
    }
});

// Deliberate rather than a gap: a file genuinely outside any project has no
// shorter honest name, and a `../../..` chain would be less readable than the
// truth.
it('leaves a path with no project above it alone', function (): void {
    $orphan = sys_get_temp_dir().'/lsf-orphan-'.bin2hex(random_bytes(6));
    mkdir($orphan, 0o777, true);

    try {
        expect(ProjectRelativePath::from($orphan.'/openapi.yaml'))->toBe($orphan.'/openapi.yaml');
    } finally {
        exec('rm -rf '.escapeshellarg($orphan));
    }
});
