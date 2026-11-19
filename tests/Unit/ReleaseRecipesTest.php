<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The two scripts behind `just release-prep` and `just release`, run the way a
 * maintainer runs them: as shell scripts, on the host, from inside a repository.
 *
 * Nothing here reaches GitHub. Each test builds a scratch repository whose
 * `origin` is a bare repository on disk, named like the GitHub one (`Gcob/lara-spec-first.git`)
 * so the scripts can read the owner and the repository from its path, and git may use
 * no transport but a local one: a remote that is not on disk fails instead of reaching
 * the network. A fake `gh` goes first on `PATH`.
 * The fake answers the few questions the scripts ask (who is logged in, what the
 * card says, whether CI is green) from files the test writes, and records every
 * call it gets, one file per call, so a test can say exactly what the scripts
 * asked GitHub to do, and what they did not. The scripts take no `gh` path from
 * their caller: the fake is found the way the real one is, through `PATH`.
 *
 * @see docs/contributing/releasing.md — "The tag is the maintainer's"
 */

const RELEASE_UNRELEASED_CHANGELOG = <<<'MD'
# Changelog

## [Unreleased]

### Added

- A thing, see [the guide](./docs/guide/thing.md), [its section](#a-section), [the site](https://example.com/x) and
  [a bare path](docs/other.md#fragment).

## [0.1.0] - 2026-09-11

### Added

- The first thing, see [the first guide](./docs/first.md).

## Why one file

Prose that is no release entry.

[unreleased]: https://github.com/Gcob/lara-spec-first/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.1.0

MD;

const RELEASE_PREPARED_CHANGELOG = <<<'MD'
# Changelog

## [Unreleased]

## [0.2.0] - __TODAY__

### Added

- A thing, see [the guide](./docs/guide/thing.md), [its section](#a-section), [the site](https://example.com/x) and
  [a bare path](docs/other.md#fragment).

## [0.1.0] - 2026-09-11

### Added

- The first thing, see [the first guide](./docs/first.md).

[unreleased]: https://github.com/Gcob/lara-spec-first/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.2.0
[0.1.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.1.0

MD;

const RELEASE_READY_CARD = <<<'MD'
## Why

A release.

## Ready when

- [x] Lot 1 is closed
- [X] The follow-ups are merged

## Done when

- [ ] 0.2.0 is on Packagist
MD;

const RELEASE_FAKE_GH = <<<'SH'
#!/bin/sh
# Records the call, then answers from the files the test wrote. Anything it was
# not taught is an error, so a script that asks GitHub something new fails loudly.
dir="$FAKE_GH_DIR"
mkdir -p "$dir/calls"
n=$(( $(ls "$dir/calls" | wc -l) + 1 ))
for argument in "$@"; do
    printf '%s\n---ARG---\n' "$argument"
done > "$dir/calls/$(printf '%04d' "$n")"

case "$1 $2" in
    "api user") cat "$dir/login" ;;
    "api --paginate") cat "$dir/ci" ;;
    "repo view") cat "$dir/slug" ;;
    "issue view") cat "$dir/card" ;;
    "pr create") echo "https://github.com/Gcob/lara-spec-first/pull/999" ;;
    "release create") ;;
    *) echo "fake gh: no answer for: $*" >&2; exit 99 ;;
esac
SH;

/**
 * The scratch directories of this file, removed by the hook below.
 *
 * @return array<int, string>
 */
function releaseScratchDirectories(?string $created = null, bool $forget = false): array
{
    static $directories = [];

    if ($created !== null) {
        $directories[] = $created;
    }

    $held = $directories;

    if ($forget) {
        $directories = [];
    }

    return $held;
}

afterEach(function (): void {
    foreach (releaseScratchDirectories(forget: true) as $directory) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
});

/**
 * A repository with a bare `origin`, a fake `gh`, and everything green: the work tree is `main`, pushed, clean.
 *
 * @return array{dir: string, work: string, origin: string, bin: string, state: string}
 */
function releaseProject(string $changelog = RELEASE_UNRELEASED_CHANGELOG): array
{
    $dir = realpath(sys_get_temp_dir()).'/lsf-release-'.bin2hex(random_bytes(6));
    $project = ['dir' => $dir, 'work' => $dir.'/work', 'origin' => $dir.'/Gcob/lara-spec-first.git', 'bin' => $dir.'/bin', 'state' => $dir.'/state'];

    foreach ([$dir, $dir.'/Gcob', $project['work'], $project['origin'], $project['bin'], $project['state']] as $path) {
        is_dir($path) || mkdir($path);
    }

    releaseScratchDirectories($dir);

    releaseGit($project['origin'], ['init', '--quiet', '--bare', '--initial-branch=main']);
    releaseGit($project['work'], ['init', '--quiet', '--initial-branch=main']);
    releaseGit($project['work'], ['config', 'user.name', 'Test']);
    releaseGit($project['work'], ['config', 'user.email', 'test@example.com']);

    mkdir($project['work'].'/scripts');

    foreach (['release.sh', 'release-prep.sh', 'release-lib.sh'] as $script) {
        copy(releaseRepository().'/scripts/'.$script, $project['work'].'/scripts/'.$script);
        chmod($project['work'].'/scripts/'.$script, 0755);
    }

    file_put_contents($project['work'].'/CHANGELOG.md', str_replace('__TODAY__', date('Y-m-d'), $changelog));
    releaseGit($project['work'], ['add', '.']);
    releaseGit($project['work'], ['commit', '--quiet', '-m', 'chore: start']);
    releaseGit($project['work'], ['remote', 'add', 'origin', $project['origin']]);
    releaseGit($project['work'], ['push', '--quiet', '--set-upstream', 'origin', 'main']);

    file_put_contents($project['bin'].'/gh', RELEASE_FAKE_GH);
    chmod($project['bin'].'/gh', 0755);
    file_put_contents($project['state'].'/login', 'Gcob');
    file_put_contents($project['state'].'/slug', 'Gcob/lara-spec-first');
    file_put_contents($project['state'].'/ci', '12 0 0');
    file_put_contents($project['state'].'/card', RELEASE_READY_CARD);

    return $project;
}

/**
 * Cut the release the way the factory does and merge it: a branch of its own with the heading dated `$date`, merged
 * into `main` with a merge commit, pushed. The fixture's root commit already carries a heading, so this one starts from
 * a project made with the default changelog.
 *
 * @param  array<string, string>  $project
 * @return string the merge commit
 */
function releaseMergeCut(array $project, string $branch, string $date): string
{
    releaseGit($project['work'], ['switch', '--quiet', '--create', $branch]);
    file_put_contents($project['work'].'/CHANGELOG.md', str_replace('__TODAY__', $date, RELEASE_PREPARED_CHANGELOG));
    releaseGit($project['work'], ['commit', '--quiet', '--all', '-m', 'docs(release): cut 0.2.0 in the changelog']);
    releaseGit($project['work'], ['switch', '--quiet', 'main']);
    releaseGit($project['work'], ['merge', '--quiet', '--no-ff', '--no-edit', $branch]);
    releaseGit($project['work'], ['push', '--quiet', 'origin', 'main']);

    return releaseGit($project['work'], ['rev-parse', 'HEAD']);
}

/**
 * Commit the given files on `main` and push.
 *
 * @param  array<string, string>  $project
 * @param  list<string>  $files
 */
function releaseLand(array $project, array $files, string $message = 'docs: land after the release'): void
{
    foreach ($files as $file) {
        file_put_contents($project['work'].'/'.$file, 'late');
        releaseGit($project['work'], ['add', $file]);
    }

    releaseGit($project['work'], ['commit', '--quiet', '-m', $message]);
    releaseGit($project['work'], ['push', '--quiet', 'origin', 'main']);
}

function releaseRepository(): string
{
    return dirname(__DIR__, 2);
}

/**
 * The environment of every git the tests run, and the one the scripts run: no user or system configuration, and no
 * transport but a local path, so a remote that is not on disk fails at once instead of reaching the network.
 *
 * @return array<string, string>
 */
function releaseGitEnvironment(): array
{
    return ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_ALLOW_PROTOCOL' => 'file'];
}

/**
 * @param  list<string>  $arguments
 */
function releaseGit(string $directory, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $directory, releaseGitEnvironment());
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * Run one of the scripts in the work tree, with the fake `gh` first on `PATH`.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 * @param  list<string>  $arguments
 * @param  string|null  $input  what to type, or null for a script whose standard input is not a terminal
 * @param  bool  $terminal  give the script a pseudo-terminal to read `$input` from
 * @return array{exit: int, out: string}
 */
function releaseRun(array $project, string $script, array $arguments, ?string $input = null, bool $terminal = false): array
{
    $process = new Process(
        ['sh', 'scripts/'.$script, ...$arguments],
        $project['work'],
        [
            ...releaseGitEnvironment(),
            'PATH' => $project['bin'].':'.getenv('PATH'),
            'FAKE_GH_DIR' => $project['state'],
            'GIT_TERMINAL_PROMPT' => '0',
        ],
        $input ?? '',
    );
    $process->setTty(false)->setPty($terminal);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput()];
}

/**
 * Every call the scripts made to `gh`, each as its list of arguments.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 * @return list<list<string>>
 */
function releaseGhCalls(array $project): array
{
    $files = glob($project['state'].'/calls/*') ?: [];
    sort($files);

    return array_map(
        static fn (string $file): array => array_slice(explode("\n---ARG---\n", (string) file_get_contents($file)), 0, -1),
        $files,
    );
}

/**
 * The calls to `gh` that are not a question: what the scripts asked GitHub to change.
 *
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 * @return list<list<string>>
 */
function releaseGhWrites(array $project): array
{
    return array_values(array_filter(
        releaseGhCalls($project),
        static fn (array $call): bool => in_array(($call[0] ?? '').' '.($call[1] ?? ''), ['pr create', 'release create'], true),
    ));
}

/**
 * @param  array{dir: string, work: string, origin: string, bin: string, state: string}  $project
 */
function releaseNothingWritten(array $project, string $branchHead): void
{
    expect(releaseGit($project['work'], ['status', '--porcelain']))->toBe('')
        ->and(releaseGit($project['work'], ['tag', '--list']))->toBe('')
        ->and(releaseGit($project['origin'], ['tag', '--list']))->toBe('')
        ->and(releaseGit($project['work'], ['rev-parse', 'HEAD']))->toBe($branchHead)
        ->and(releaseGit($project['work'], ['branch', '--format=%(refname:short)']))->toBe('main')
        ->and(releaseGit($project['origin'], ['branch', '--format=%(refname:short)']))->toBe('main')
        ->and(releaseGhWrites($project))->toBe([]);
}

it('keeps every test off the network: origin is a path on disk, and git refuses any other transport', function (): void {
    $project = releaseProject();

    $url = releaseGit($project['work'], ['config', '--get', 'remote.origin.url']);
    $rewrites = new Process(['git', 'config', '--get-regexp', '^url\.'], $project['work'], releaseGitEnvironment());
    $rewrites->run();

    $elsewhere = new Process(['git', 'ls-remote', 'https://127.0.0.1:9/Gcob/lara-spec-first.git'], $project['work'], releaseGitEnvironment());
    $elsewhere->run();

    expect($url)->toBe($project['origin'])
        ->and($url)->toStartWith($project['dir'].'/')
        ->and($url)->toEndWith('/Gcob/lara-spec-first.git')
        ->and($rewrites->getExitCode())->toBe(1, 'a rewrite rule would send an address somewhere else')
        ->and($rewrites->getOutput())->toBe('')
        ->and($elsewhere->getExitCode())->not->toBe(0)
        ->and($elsewhere->getOutput().$elsewhere->getErrorOutput())->toContain("transport 'https' not allowed");
});

describe('release-prep', function (): void {
    it('prepares a release on a branch of its own, with a draft pull request that says who opened it', function (): void {
        $project = releaseProject();
        $today = date('Y-m-d');

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126']);

        expect($run['exit'])->toBe(0, $run['out'])
            ->and(releaseGit($project['work'], ['branch', '--show-current']))->toBe('release/126/0.2.0')
            ->and(releaseGit($project['origin'], ['branch', '--list', 'release/126/0.2.0']))->toContain('release/126/0.2.0')
            ->and(releaseGit($project['work'], ['log', '-1', '--format=%s']))->toBe('docs(release): #126 cut 0.2.0 in the changelog')
            ->and(releaseGit($project['work'], ['tag', '--list']))->toBe('')
            ->and($run['out'])->toContain('must be the day the tag is cut');

        $changelog = (string) file_get_contents($project['work'].'/CHANGELOG.md');

        expect($changelog)
            ->toContain("## [Unreleased]\n\n## [0.2.0] - {$today}\n\n### Added")
            ->toContain("[unreleased]: https://github.com/Gcob/lara-spec-first/compare/v0.2.0...HEAD\n[0.2.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.2.0\n[0.1.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.1.0")
            ->toContain("## [0.1.0] - 2026-09-11\n\n### Added\n\n- The first thing");
        expect($changelog)->not->toContain('compare/v0.1.0...HEAD');

        $writes = releaseGhWrites($project);

        expect($writes)->toHaveCount(1)
            ->and($writes[0])->toContain('--draft')
            ->and($writes[0])->toContain('release/126/0.2.0')
            ->and(implode("\n", $writes[0]))->toContain('Opened by `just release-prep 0.2.0 126`')
            ->and(implode("\n", $writes[0]))->toContain('Upgrading from 0.1.0')
            ->and(implode("\n", $writes[0]))->toContain("The heading's date must be the day the tag is cut")
            ->and(implode("\n", $writes[0]))->toContain($today);
    });

    it('prepares a release only when every box of the card\'s "Ready when" is ticked', function (): void {
        $project = releaseProject();
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);
        file_put_contents($project['state'].'/card', str_replace('- [x] Lot 1 is closed', '- [ ] Lot 1 is closed', RELEASE_READY_CARD));

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   card #126 has every "Ready when" box ticked: 1 still open')
            ->and($run['out'])->toContain('- [ ] Lot 1 is closed');

        releaseNothingWritten($project, $head);
    });

    it('prepares a release in a dry run that writes nothing and exits 0 when every check is green', function (): void {
        $project = releaseProject();
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126', '--dry-run']);

        expect($run['exit'])->toBe(0, $run['out'])
            ->and($run['out'])->not->toContain('RED')
            ->and($run['out'])->toContain('Dry run, all checks green');

        releaseNothingWritten($project, $head);
    });

    it('prepares a release in a dry run that names every red check and exits 1', function (): void {
        $project = releaseProject();
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);
        file_put_contents($project['state'].'/ci', '12 1 2');
        file_put_contents($project['state'].'/card', "## Ready when\n\n- [ ] #107\n- [x] #108\n");
        file_put_contents($project['work'].'/stray.txt', 'dirty');
        releaseGit($project['work'], ['tag', 'v0.2.0']);

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126', '--dry-run']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   the tag v0.2.0 does not exist: it exists locally')
            ->and($run['out'])->toContain('RED   the working tree is clean')
            ->and($run['out'])->toContain('RED   CI is green on main')
            ->and($run['out'])->toContain('RED   card #126 has every "Ready when" box ticked: 1 still open');

        unlink($project['work'].'/stray.txt');
        releaseGit($project['work'], ['tag', '--delete', 'v0.2.0']);
        releaseNothingWritten($project, $head);
    });

    it('prepares a release only when the card has a "Ready when" box to read', function (string $card): void {
        $project = releaseProject();
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);
        file_put_contents($project['state'].'/card', $card);

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   card #126 has every "Ready when" box ticked: it has no box to tick');

        releaseNothingWritten($project, $head);
    })->with([
        'a card with no section' => ["## Why\n\nA release.\n\n## Done when\n\n- [ ] Packagist\n"],
        'a section with prose and no box' => ["## Ready when\n\nLot 1 is closed.\n\n## Done when\n\n- [x] Packagist\n"],
        'an empty section' => ["## Ready when\n\n## Done when\n\n- [x] Packagist\n"],
    ]);

    it('prepares a release in a dry run only when origin/main is the one this checkout knows', function (): void {
        $project = releaseProject();
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);
        $other = $project['dir'].'/other';
        mkdir($other);
        releaseGit($other, ['clone', '--quiet', $project['origin'], '.']);
        releaseGit($other, ['config', 'user.name', 'Other']);
        releaseGit($other, ['config', 'user.email', 'other@example.com']);
        file_put_contents($other.'/news.txt', 'news');
        releaseGit($other, ['add', 'news.txt']);
        releaseGit($other, ['commit', '--quiet', '-m', 'chore: land on main']);
        releaseGit($other, ['push', '--quiet', 'origin', 'main']);

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126', '--dry-run']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   origin/main is up to date here: run git fetch origin main');

        releaseNothingWritten($project, $head);
    });

    it('prepares a release only when every box is ticked, whatever bullet it is written with', function (string $open): void {
        $project = releaseProject();
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);
        file_put_contents($project['state'].'/card', "## Ready when\n\n- [x] #107\n{$open} #108\n");

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   card #126 has every "Ready when" box ticked: 1 still open');

        releaseNothingWritten($project, $head);
    })->with([
        'a dash' => ['- [ ]'],
        'an asterisk' => ['* [ ]'],
        'a plus' => ['+ [ ]'],
        'a number' => ['1. [ ]'],
    ]);

    it('prepares a release only when origin can be asked about the branch', function (): void {
        $project = releaseProject();
        rename($project['origin'], $project['origin'].'-gone');

        $run = releaseRun($project, 'release-prep.sh', ['0.2.0', '126']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   the branch release/126/0.2.0 does not exist: origin could not be asked')
            ->and($run['out'])->not->toContain('ok    the branch')
            ->and(releaseGit($project['work'], ['status', '--porcelain']))->toBe('')
            ->and(releaseGit($project['work'], ['branch', '--format=%(refname:short)']))->toBe('main')
            ->and(releaseGhWrites($project))->toBe([]);
    });

    it('prepares a release only for a version and a card it can read', function (string $version, string $card): void {
        $project = releaseProject();
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);

        $run = releaseRun($project, 'release-prep.sh', [$version, $card]);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED');

        releaseNothingWritten($project, $head);
    })->with([
        'a version with two numbers' => ['0.2', '126'],
        'a version with a leading v' => ['v0.2.0', '126'],
        'a card that is not a number' => ['0.2.0', 'abc'],
    ]);
});

describe('release', function (): void {
    /**
     * Each way a check goes red, with the line that names it. A closure breaks one thing in a green project.
     *
     * @return array<string, array{0: Closure, 1: string, 2: string}>
     */
    function releaseRedChecks(): array
    {
        return [
            'the version is not X.Y.Z' => [fn (array $p) => null, 'RED   the version is valid', '0.2'],
            'the tag exists locally' => [
                fn (array $p) => releaseGit($p['work'], ['tag', 'v0.2.0']),
                'RED   the tag v0.2.0 does not exist: it exists locally',
                '0.2.0',
            ],
            'the tag exists on origin' => [
                function (array $p): void {
                    releaseGit($p['work'], ['tag', 'v0.2.0']);
                    releaseGit($p['work'], ['push', '--quiet', 'origin', 'v0.2.0']);
                    releaseGit($p['work'], ['tag', '--delete', 'v0.2.0']);
                },
                'RED   the tag v0.2.0 does not exist: it exists on origin',
                '0.2.0',
            ],
            'the working tree is dirty' => [
                fn (array $p) => file_put_contents($p['work'].'/stray.txt', 'dirty'),
                'RED   the working tree is clean',
                '0.2.0',
            ],
            'HEAD is not origin/main' => [
                function (array $p): void {
                    file_put_contents($p['work'].'/local.txt', 'local');
                    releaseGit($p['work'], ['add', 'local.txt']);
                    releaseGit($p['work'], ['commit', '--quiet', '-m', 'chore: not pushed']);
                },
                'RED   HEAD is origin/main',
                '0.2.0',
            ],
            'CI is still running' => [
                fn (array $p) => file_put_contents($p['state'].'/ci', '12 3 0'),
                'RED   CI is green on',
                '0.2.0',
            ],
            'CI has failed' => [
                fn (array $p) => file_put_contents($p['state'].'/ci', '12 0 1'),
                'RED   CI is green on',
                '0.2.0',
            ],
            'something merged after the release pull request' => [
                function (array $p): void {
                    $changelog = (string) file_get_contents($p['work'].'/CHANGELOG.md');
                    file_put_contents($p['work'].'/CHANGELOG.md', str_replace("## [Unreleased]\n", "## [Unreleased]\n\n- A late change.\n", $changelog));
                    releaseGit($p['work'], ['commit', '--quiet', '--all', '-m', 'feat: land after the release']);
                    releaseGit($p['work'], ['push', '--quiet', 'origin', 'main']);
                },
                'RED   [Unreleased] is empty at HEAD: something merged after the release pull request',
                '0.2.0',
            ],
            'a file changed after the release pull request merged' => [
                fn (array $p) => releaseLand($p, ['docs-late.md']),
                'RED   only CHANGELOG.md changed since the release pull request merged (',
                '0.2.0',
            ],
            'the history is shallow' => [
                function (array $p): void {
                    releaseLand($p, ['docs-late.md']);
                    releaseGit($p['dir'], ['clone', '--quiet', '--depth', '1', 'file://'.$p['origin'], 'shallow']);
                    rename($p['work'], $p['dir'].'/work-full');
                    rename($p['dir'].'/shallow', $p['work']);
                },
                'RED   only CHANGELOG.md changed since the release pull request merged: the history is shallow',
                '0.2.0',
            ],
            'the heading is not dated today' => [
                function (array $p): void {
                    $changelog = (string) file_get_contents($p['work'].'/CHANGELOG.md');
                    file_put_contents($p['work'].'/CHANGELOG.md', str_replace(date('Y-m-d'), '2026-01-01', $changelog));
                    releaseGit($p['work'], ['commit', '--quiet', '--all', '-m', 'docs: date the entry']);
                    releaseGit($p['work'], ['push', '--quiet', 'origin', 'main']);
                },
                'RED   the heading\'s date is today: it says "2026-01-01"',
                '0.2.0',
            ],
            'gh reads another repository than origin' => [
                fn (array $p) => file_put_contents($p['state'].'/slug', 'someone/else'),
                'RED   gh reads the repository from origin: gh says someone/else, origin says Gcob/lara-spec-first',
                '0.2.0',
            ],
            'CI has failed on a later page' => [
                fn (array $p) => file_put_contents($p['state'].'/ci', "12 0 0\n12 0 1\n"),
                'RED   CI is green on',
                '0.2.0',
            ],
            'CI is still running on a later page' => [
                fn (array $p) => file_put_contents($p['state'].'/ci', "12 0 0\n12 2 0\n"),
                'RED   CI is green on',
                '0.2.0',
            ],
            'CI answers something unreadable' => [
                fn (array $p) => file_put_contents($p['state'].'/ci', "all good\n"),
                'RED   CI is green on',
                '0.2.0',
            ],
            'CI has no run at all' => [
                fn (array $p) => file_put_contents($p['state'].'/ci', '0 0 0'),
                'RED   CI is green on',
                '0.2.0',
            ],
            'the changelog has no heading for the version' => [
                fn (array $p) => null,
                'RED   CHANGELOG.md has a "## [0.3.0] - " heading',
                '0.3.0',
            ],
            'gh is logged in as somebody else' => [
                fn (array $p) => file_put_contents($p['state'].'/login', 'jacobpap'),
                'RED   gh is logged in as Gcob: it is "jacobpap"',
                '0.2.0',
            ],
        ];
    }

    it('refuses to tag when a check is red, and names it', function (Closure $break, string $line, string $version): void {
        $project = releaseProject(RELEASE_PREPARED_CHANGELOG);
        $break($project);
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);
        $tags = releaseGit($project['origin'], ['tag', '--list']);

        $dryRun = releaseRun($project, 'release.sh', [$version]);
        $publish = releaseRun($project, 'release.sh', [$version, '--publish']);

        expect($dryRun['exit'])->toBe(1)
            ->and($dryRun['out'])->toContain($line)
            ->and($publish['exit'])->not->toBe(0)
            ->and($publish['out'])->toContain($line)
            ->and(releaseGhWrites($project))->toBe([])
            ->and(releaseGit($project['origin'], ['tag', '--list']))->toBe($tags)
            ->and(releaseGit($project['work'], ['rev-parse', 'HEAD']))->toBe($head);
    })->with(releaseRedChecks());

    it('refuses to tag over an unreleased line with a fix that can be done', function (): void {
        $project = releaseProject(RELEASE_PREPARED_CHANGELOG);
        $changelog = (string) file_get_contents($project['work'].'/CHANGELOG.md');
        file_put_contents($project['work'].'/CHANGELOG.md', str_replace("## [Unreleased]\n", "## [Unreleased]\n\n- A late change.\n", $changelog));
        releaseGit($project['work'], ['commit', '--quiet', '--all', '-m', 'feat: land after the release']);
        releaseGit($project['work'], ['push', '--quiet', 'origin', 'main']);

        $run = releaseRun($project, 'release.sh', ['0.2.0']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   [Unreleased] is empty at HEAD')
            ->and($run['out'])->toContain('the 0.2.0 entry')
            ->and($run['out'])->not->toContain('commit before it');
    });

    it('tags over a release pull request whose own entry was corrected after it merged', function (string $cutDate, Closure $change): void {
        $project = releaseProject();
        $merge = releaseMergeCut($project, 'release/126/0.2.0', $cutDate);
        $judged = $change($project, $merge) ?? $merge;

        $run = releaseRun($project, 'release.sh', ['0.2.0']);

        expect($run['exit'])->toBe(0, $run['out'])
            ->and($run['out'])->not->toContain('RED')
            ->and($run['out'])->toContain('ok    only CHANGELOG.md changed since the release pull request merged ('.substr($judged, 0, 7).')');
    })->with([
        'the date fixed' => [
            '2026-01-01',
            function (array $p): void {
                $changelog = (string) file_get_contents($p['work'].'/CHANGELOG.md');
                file_put_contents($p['work'].'/CHANGELOG.md', str_replace('2026-01-01', date('Y-m-d'), $changelog));
                releaseGit($p['work'], ['commit', '--quiet', '--all', '-m', 'docs(release): date the entry']);
                releaseGit($p['work'], ['push', '--quiet', 'origin', 'main']);
            },
        ],
        'the entry reworded' => [
            date('Y-m-d'),
            function (array $p): void {
                $changelog = (string) file_get_contents($p['work'].'/CHANGELOG.md');
                file_put_contents($p['work'].'/CHANGELOG.md', str_replace('- A thing, see', '- A better thing, see', $changelog));
                releaseGit($p['work'], ['commit', '--quiet', '--all', '-m', 'docs(release): reword the entry']);
                releaseGit($p['work'], ['push', '--quiet', 'origin', 'main']);
            },
        ],
        'a late file reverted' => [
            date('Y-m-d'),
            function (array $p): void {
                releaseLand($p, ['docs-late.md']);
                releaseGit($p['work'], ['revert', '--no-edit', 'HEAD']);
                releaseGit($p['work'], ['push', '--quiet', 'origin', 'main']);
            },
        ],
        'a release merged, reverted and cut again' => [
            date('Y-m-d'),
            function (array $p, string $merge): string {
                releaseLand($p, ['docs-between.md'], 'docs: land while the release is abandoned');
                releaseGit($p['work'], ['revert', '--no-edit', '-m', '1', $merge]);
                releaseGit($p['work'], ['push', '--quiet', 'origin', 'main']);

                return releaseMergeCut($p, 'release/126/0.2.0-again', date('Y-m-d'));
            },
        ],
    ]);

    it('names the files that changed after the release pull request merged, five at most', function (int $count, string $listed): void {
        $project = releaseProject();
        $merge = releaseMergeCut($project, 'release/126/0.2.0', date('Y-m-d'));
        releaseLand($project, array_map(static fn (int $n): string => "late-{$n}.md", range(1, $count)));

        $run = releaseRun($project, 'release.sh', ['0.2.0']);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toContain('RED   only CHANGELOG.md changed since the release pull request merged ('.substr($merge, 0, 7).'): '.$listed);
    })->with([
        'five files' => [5, 'late-1.md, late-2.md, late-3.md, late-4.md, late-5.md. '],
        'six files' => [6, 'late-1.md, late-2.md, late-3.md, late-4.md, late-5.md and 1 more. '],
    ]);

    it('refuses to tag when origin cannot be asked whether the tag exists', function (): void {
        $project = releaseProject(RELEASE_PREPARED_CHANGELOG);
        rename($project['origin'], $project['origin'].'-gone');

        $run = releaseRun($project, 'release.sh', ['0.2.0', '--publish']);

        expect($run['exit'])->not->toBe(0)
            ->and($run['out'])->toContain('RED   the tag v0.2.0 does not exist: origin could not be asked')
            ->and(releaseGit($project['work'], ['tag', '--list']))->toBe('')
            ->and(releaseGhWrites($project))->toBe([]);
    });

    it('refuses to tag with no terminal, even when every check is green', function (): void {
        $project = releaseProject(RELEASE_PREPARED_CHANGELOG);
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);

        $dryRun = releaseRun($project, 'release.sh', ['0.2.0']);
        $piped = releaseRun($project, 'release.sh', ['0.2.0', '--publish'], "0.2.0\n");
        $closed = releaseRun($project, 'release.sh', ['0.2.0', '--publish']);

        expect($dryRun['exit'])->toBe(0, $dryRun['out'])
            ->and($dryRun['out'])->not->toContain('RED')
            ->and($dryRun['out'])->toContain('just release 0.2.0 --publish');

        foreach ([$piped, $closed] as $refused) {
            expect($refused['exit'])->not->toBe(0)
                ->and($refused['out'])->toContain('interactive terminal');
        }

        releaseNothingWritten($project, $head);
    });

    it('tags only after the version is typed at a terminal, and pins the entry\'s links to the tag', function (): void {
        $project = releaseProject(RELEASE_PREPARED_CHANGELOG);
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);

        $run = releaseRun($project, 'release.sh', ['0.2.0', '--publish'], "0.2.0\n", terminal: true);

        expect($run['exit'])->toBe(0, $run['out'])
            ->and($run['out'])->toContain('The tag will be created by: Test <test@example.com>')
            ->and($run['out'])->toContain('Type the version to tag (0.2.0)')
            ->and(releaseGit($project['origin'], ['tag', '--list']))->toBe('v0.2.0')
            ->and(releaseGit($project['origin'], ['cat-file', '-t', 'v0.2.0']))->toBe('tag')
            ->and(releaseGit($project['origin'], ['rev-parse', 'v0.2.0^{commit}']))->toBe($head);

        $writes = releaseGhWrites($project);

        expect($writes)->toHaveCount(1)
            ->and(array_slice($writes[0], 0, 3))->toBe(['release', 'create', 'v0.2.0'])
            ->and($writes[0])->toContain('--verify-tag')
            ->and($writes[0])->not->toContain('--repo');

        $notes = $writes[0][array_search('--notes', $writes[0], true) + 1];
        $pinned = 'https://github.com/Gcob/lara-spec-first/blob/v0.2.0';

        expect($notes)
            ->toContain("[the guide]({$pinned}/docs/guide/thing.md)")
            ->toContain("[its section]({$pinned}/CHANGELOG.md#a-section)")
            ->toContain('[the site](https://example.com/x)')
            ->toContain("[a bare path]({$pinned}/docs/other.md#fragment)")
            ->toContain('### Added');
        expect($notes)->not->toContain('## [0.2.0]')
            ->and($notes)->not->toContain('the first guide')
            ->and($notes)->not->toContain('](./');
    })->skip(fn (): bool => ! Process::isPtySupported(), 'This PHP cannot open a pseudo-terminal');

    it('tags only after the version is typed, so a wrong answer or no answer writes nothing', function (string $typed): void {
        $project = releaseProject(RELEASE_PREPARED_CHANGELOG);
        $head = releaseGit($project['work'], ['rev-parse', 'HEAD']);

        $run = releaseRun($project, 'release.sh', ['0.2.0', '--publish'], $typed, terminal: true);

        expect($run['exit'])->not->toBe(0)
            ->and($run['out'])->toContain('Nothing was written');

        releaseNothingWritten($project, $head);
    })->with([
        'another version' => ["0.2.1\n"],
        'the version with a leading v' => ["v0.2.0\n"],
        'an empty line' => ["\n"],
    ])->skip(fn (): bool => ! Process::isPtySupported(), 'This PHP cannot open a pseudo-terminal');

    it('prepares a release on the CI numbers its own jq expression reads from a page of check runs', function (): void {
        $expression = (new Process(['sh', '-c', '. scripts/release-lib.sh && printf "%s" "$CI_JQ"'], releaseRepository()))->mustRun()->getOutput();
        $page = json_encode([
            'total_count' => 7,
            'check_runs' => [
                ['status' => 'completed', 'conclusion' => 'success'],
                ['status' => 'completed', 'conclusion' => 'skipped'],
                ['status' => 'completed', 'conclusion' => 'neutral'],
                ['status' => 'queued', 'conclusion' => null],
                ['status' => 'in_progress', 'conclusion' => null],
                ['status' => 'completed', 'conclusion' => 'failure'],
                ['status' => 'completed', 'conclusion' => 'cancelled'],
            ],
        ], JSON_THROW_ON_ERROR);

        $jq = new Process(['jq', '-r', $expression], input: $page);
        $jq->mustRun();

        expect(trim($jq->getOutput()))->toBe('7 2 2');
    })->skip(fn (): bool => trim((string) shell_exec('command -v jq')) === '', 'jq is not installed');

    it('prepares a release and tags it without ever giving gh a --repo or asking it more than it was taught', function (): void {
        $project = releaseProject(RELEASE_PREPARED_CHANGELOG);

        releaseRun($project, 'release.sh', ['0.2.0']);
        releaseRun($project, 'release-prep.sh', ['0.3.0', '126', '--dry-run']);

        $asked = array_map(static fn (array $call): string => $call[0].' '.($call[1] ?? ''), releaseGhCalls($project));

        expect(releaseGhCalls($project))->not->toBe([]);

        foreach (releaseGhCalls($project) as $call) {
            expect($call)->not->toContain('--repo');
        }

        foreach ($asked as $question) {
            expect($question)->toMatch('#^(api user|api --paginate|repo view|issue view)$#');
        }

        foreach (releaseGhCalls($project) as $call) {
            if ($call[1] === '--paginate') {
                expect($call[2])->toMatch('#^repos/\{owner\}/\{repo\}/commits/[0-9a-f]{40}/check-runs\?per_page=100$#');
            }
        }
    });
});
