<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\Checks\InstallationCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;

function installationRoot(): string
{
    static $root = null;

    return $root ??= sys_get_temp_dir().'/lsf-install-'.bin2hex(random_bytes(6));
}

beforeEach(function (): void {
    exec('rm -rf '.escapeshellarg(installationRoot()));
    mkdir(installationRoot(), 0o777, true);
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(installationRoot()));
});

function initGitRepo(): void
{
    exec('git init --quiet '.escapeshellarg(installationRoot()));
}

/**
 * @param  array<string, string>  $psr4
 * @param  array<string, string>  $psr4Dev
 */
function composerJsonWith(array $psr4, array $psr4Dev = []): void
{
    file_put_contents(installationRoot().'/composer.json', json_encode(array_filter([
        'autoload' => ['psr-4' => $psr4],
        'autoload-dev' => $psr4Dev === [] ? null : ['psr-4' => $psr4Dev],
    ])));
}

// --- The vendored directory not gitignored ---

it('is silent outside a git repository', function (): void {
    mkdir(installationRoot().'/vendor-refs');

    expect(InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Generated', 'app/Generated'))->toBe([]);
});

it('is silent when the vendored directory is not ignored', function (): void {
    initGitRepo();

    expect(InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Generated', 'app/Generated'))->toBe([]);
});

// `git check-ignore` can only resolve a directory-only pattern once the
// directory exists on disk — a limit of git itself, documented on the check
// — so this pins the realistic case the section exists for: a directory that
// already holds a vendored copy.
it('flags a vendored directory excluded by .gitignore', function (): void {
    initGitRepo();
    file_put_contents(installationRoot().'/.gitignore', "vendor-refs/\n");
    mkdir(installationRoot().'/vendor-refs');

    $findings = InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Generated', 'app/Generated');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->section)->toBe('Installation')
        ->and($findings[0]->message)->toContain('vendor-refs')
        ->and($findings[0]->message)->toContain('excluded by .gitignore');
})->skip(fn (): bool => trim((string) shell_exec('command -v git')) === '', 'git is not available');

// --- generated.path and generated.namespace against composer.json's PSR-4 map ---

it('is silent with no composer.json to check against', function (): void {
    expect(InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Http\\Generated', 'app/Http/Generated'))->toBe([]);
});

it('is silent when the configured path matches the PSR-4 map', function (): void {
    composerJsonWith(['App\\' => 'app/']);

    expect(InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Http\\Generated', 'app/Http/Generated'))->toBe([]);
});

it('flags a generated.path that does not match what PSR-4 actually maps', function (): void {
    composerJsonWith(['App\\' => 'app/']);

    $findings = InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Http\\Generated', 'somewhere/else');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->section)->toBe('Installation')
        ->and($findings[0]->message)->toContain('somewhere/else')
        ->and($findings[0]->message)->toContain('app/Http/Generated');
});

it('flags a namespace no PSR-4 prefix maps at all', function (): void {
    composerJsonWith(['App\\' => 'app/']);

    $findings = InstallationCheck::check(installationRoot(), 'vendor-refs', 'Somewhere\\Else', 'app/Http/Generated');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('no `autoload.psr-4` or `autoload-dev.psr-4` prefix');
});

// The longest prefix wins, Composer's own rule — a project mapping both
// `App\` and `App\Http\` is judged against the one it would actually use.
it('resolves the longest matching PSR-4 prefix rather than the first one', function (): void {
    composerJsonWith(['App\\' => 'app/', 'App\\Http\\' => 'src/Http/']);

    expect(InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Http\\Generated', 'src/Http/Generated'))
        ->toBe([]);
});

// --- "Cannot be determined" is never a finding ---

// Composer merges `autoload` and `autoload-dev` into one classloader, so a
// generated tree under a dev-only root autoloads perfectly well. Reading one
// map and reporting "nothing would autoload the generated tree at all" was
// wrong about a layout Composer supports — this package's own Workbench being
// the instance that surfaced it — in the loudest class of finding this report
// has.
it('reads autoload-dev as well, because Composer does', function (): void {
    composerJsonWith(
        ['App\\' => 'app/'],
        ['Workbench\\App\\' => 'workbench/app/'],
    );

    $findings = InstallationCheck::check(
        installationRoot(),
        'vendor-refs',
        'Workbench\\App\\Http\\Generated',
        'workbench/app/Http/Generated',
    );

    expect($findings)->toBe([]);
});

it('reports a real mismatch under autoload-dev the same way it would under autoload', function (): void {
    composerJsonWith(
        ['App\\' => 'app/'],
        ['Workbench\\App\\' => 'workbench/app/'],
    );

    $findings = InstallationCheck::check(
        installationRoot(),
        'vendor-refs',
        'Workbench\\App\\Http\\Generated',
        'somewhere/else',
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('workbench/app/Http/Generated');
});

// A generated tree outside the application root is a layout
// `GeneratedRoutesLocator::directory()` supports on purpose — a monorepo — and
// this check has no way to know which `composer.json` governs a directory
// outside the one it is reading. Comparing an out-of-tree absolute path against
// a relative PSR-4 directory can only ever mismatch, which is how "the
// generated tree would not autoload" came to be printed about every monorepo
// doing exactly what the locator documents.
it('is silent when the generated path is absolute and outside the application root', function (): void {
    composerJsonWith(['App\\' => 'app/']);

    $findings = InstallationCheck::check(
        installationRoot(),
        'vendor-refs',
        'Somewhere\\Else',
        '/srv/monorepo/api/app/Http/Generated',
    );

    expect($findings)->toBe([]);
});

// Absolute but *inside* the root is a different question, and one this check can
// answer: it makes the path relative and compares as usual.
it('still checks an absolute generated path that sits inside the application root', function (): void {
    composerJsonWith(['App\\' => 'app/']);

    $findings = InstallationCheck::check(
        installationRoot(),
        'vendor-refs',
        'App\\Http\\Generated',
        installationRoot().'/app/Http/Generated',
    );

    expect($findings)->toBe([]);
});

it('reports an absolute generated path inside the root that disagrees with the map', function (): void {
    composerJsonWith(['App\\' => 'app/']);

    $findings = InstallationCheck::check(
        installationRoot(),
        'vendor-refs',
        'App\\Http\\Generated',
        installationRoot().'/src/Http/Generated',
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->message)->toContain('app/Http/Generated');
});

it('is silent when composer.json declares no PSR-4 map at all', function (): void {
    file_put_contents(installationRoot().'/composer.json', json_encode(['name' => 'acme/app']));

    expect(InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Generated', 'app/Generated'))->toBe([]);
});
