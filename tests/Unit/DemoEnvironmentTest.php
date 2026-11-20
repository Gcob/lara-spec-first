<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

// The dev image runs the demo with the Hurl version CI runs it with. The build
// proves the copied binary is the version the Dockerfile names (a wrong
// HURL_VERSION fails it); these tests only keep the two files that name a
// version equal, and the image pinned by a digest, so a red one says which file
// to fix before anyone builds.
//
// The last tests guard demo/run.sh's arguments: `--keep` is read anywhere among
// them, and a mistyped flag must stop with the usage instead of being taken for
// a branch name. Each case stops before the script checks for its tools, so
// they need `bash` only: no Composer, no network, nothing written.

function dockerfileContents(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
}

it('pins the same Hurl version in the Dockerfile as the demo workflow installs', function (): void {
    preg_match('/^ARG HURL_VERSION=(\S+)$/m', dockerfileContents(), $matches);

    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/demo.yml');
    assert(is_array($workflow));
    $ciVersion = $workflow['env']['HURL_VERSION'] ?? null;

    expect($matches[1] ?? null)
        ->toBe(
            $ciVersion,
            'the Dockerfile and .github/workflows/demo.yml pin different Hurl versions: '
            .'change the ARG HURL_VERSION line and the env HURL_VERSION line together',
        );
});

it('pins the Hurl image by a digest and builds the stage from it', function (): void {
    $dockerfile = dockerfileContents();

    expect($dockerfile)
        ->toMatch('/^ARG HURL_IMAGE_DIGEST=sha256:[0-9a-f]{64}$/m')
        ->toMatch('/^FROM ghcr\.io\/orange-opensource\/hurl:\$\{HURL_VERSION\}@\$\{HURL_IMAGE_DIGEST\} AS hurl$/m');
});

/**
 * demo/run.sh, run by its absolute path, so the working directory does not matter.
 *
 * @return array{exit: int, err: string}
 */
function runDemo(string ...$arguments): array
{
    // DEMO_GIT_DIR is set by scripts/demo-in-docker.sh, and would make the script point git elsewhere.
    $process = new Process(['bash', dirname(__DIR__, 2).'/demo/run.sh', ...$arguments], env: ['DEMO_GIT_DIR' => false]);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'err' => $process->getErrorOutput()];
}

it('prints the usage and exits 2 when the flag is mistyped or missing its minor', function (string ...$arguments): void {
    $run = runDemo(...$arguments);

    expect($run['exit'])->toBe(2)
        ->and($run['err'])->toContain('Usage: demo/run.sh <minor> [<branch>] [--keep]');
})->with([
    '--keep alone' => ['--keep'],
    'a misspelled flag' => ['0.2', '--kep'],
    'a short flag' => ['0.2', '-k'],
    'three positional arguments' => ['0.2', 'a', 'b'],
]);

it('reads --keep anywhere among the arguments', function (string ...$arguments): void {
    $run = runDemo(...$arguments);

    expect($run['exit'])->toBe(2)
        ->and($run['err'])->toContain('there is no demo/9.9')
        ->and($run['err'])->not->toContain('Usage:');
})->with([
    'after the minor' => ['9.9', '--keep'],
    'before the minor' => ['--keep', '9.9'],
    'after the branch' => ['9.9', 'a-branch', '--keep'],
]);
