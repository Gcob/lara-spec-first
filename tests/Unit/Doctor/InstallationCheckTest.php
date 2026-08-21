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
 */
function composerJsonWith(array $psr4): void
{
    file_put_contents(installationRoot().'/composer.json', json_encode([
        'autoload' => ['psr-4' => $psr4],
    ]));
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
        ->and($findings[0]->message)->toContain('no `autoload.psr-4` prefix');
});

// The longest prefix wins, Composer's own rule — a project mapping both
// `App\` and `App\Http\` is judged against the one it would actually use.
it('resolves the longest matching PSR-4 prefix rather than the first one', function (): void {
    composerJsonWith(['App\\' => 'app/', 'App\\Http\\' => 'src/Http/']);

    expect(InstallationCheck::check(installationRoot(), 'vendor-refs', 'App\\Http\\Generated', 'src/Http/Generated'))
        ->toBe([]);
});
