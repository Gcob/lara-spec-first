<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

// The dev image runs the demo with the Hurl version CI runs it with. The build
// proves the copied binary is the version the Dockerfile names (a wrong
// HURL_VERSION fails it); these tests only keep the two files that name a
// version equal, and the image pinned by a digest, so a red one says which file
// to fix before anyone builds.

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
