<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

it('resolves a relative configured path against the application root', function (): void {
    $locator = new GeneratedRoutesLocator('/srv/app', 'app/Http/Generated');

    expect($locator->path())->toBe('/srv/app/app/Http/Generated/routes.php');
});

// A trailing separator in either value is a typo, not an intent, and joining
// them naively produces a path that is wrong rather than one that fails.
it('joins cleanly whatever trailing separators the two values carry', function (): void {
    $locator = new GeneratedRoutesLocator('/srv/app/', 'app/Http/Generated/');

    expect($locator->path())->toBe('/srv/app/app/Http/Generated/routes.php');
});

// A generated tree outside the application root is a real monorepo layout. The
// alternative is joining an absolute setting under the base path anyway, which
// silently produces a path nothing is at.
it('takes an absolute configured path as written', function (): void {
    $locator = new GeneratedRoutesLocator('/srv/app', '/mnt/shared/Generated');

    expect($locator->path())->toBe('/mnt/shared/Generated/routes.php');
});

// The filename is public API surface the moment the build writes it: the reader
// here and the writer in `spec:build` have to agree, and this is the only thing
// that says which name they agree on.
it('reads the routes out of a file named routes.php', function (): void {
    expect(GeneratedRoutesLocator::FILE)->toBe('routes.php');
});

it('reports the generated routes present when the build has written them', function (): void {
    $locator = new GeneratedRoutesLocator(dirname(__DIR__, 2), 'Fixtures/Generated');

    expect($locator->exists())->toBeTrue();
});

// The fresh-clone state, and the one the provider must survive: .gitignore
// decides what a project commits, so an absent tree is legitimate rather than
// a fault.
it('reports them absent when nothing has been generated', function (): void {
    $locator = new GeneratedRoutesLocator(dirname(__DIR__, 2), 'Fixtures/NothingGeneratedHere');

    expect($locator->exists())->toBeFalse();
});

// `is_file` rather than `file_exists`, because the two read alike and only one
// of them is right: the provider is about to `require` whatever this approves,
// and requiring a directory is a fatal error rather than a missing route.
it('does not mistake a directory for the routes file', function (): void {
    $base = sys_get_temp_dir().'/lsf-'.bin2hex(random_bytes(6));
    mkdir($base.'/Generated/'.GeneratedRoutesLocator::FILE, 0o777, true);

    $locator = new GeneratedRoutesLocator($base, 'Generated');

    try {
        expect($locator->exists())->toBeFalse();
    } finally {
        rmdir($base.'/Generated/'.GeneratedRoutesLocator::FILE);
        rmdir($base.'/Generated');
        rmdir($base);
    }
});
