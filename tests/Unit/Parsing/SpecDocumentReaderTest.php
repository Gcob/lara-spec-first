<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi30Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;

it('reads a document and carries its version and strategy', function (): void {
    $document = (new SpecDocumentReader)->read(specFixturePath('openapi-3.0.yaml'));

    expect($document->version)->toBe(SpecVersion::V3_0)
        ->and($document->strategy)->toBeInstanceOf(OpenApi30Strategy::class)
        ->and($document->raw)->toHaveKey('paths')
        ->and($document->path)->toEndWith('openapi-3.0.yaml');
});

it('reads both supported versions', function (string $name, SpecVersion $expected): void {
    expect((new SpecDocumentReader)->read(specFixturePath($name))->version)->toBe($expected);
})->with([
    ['openapi-3.0.yaml', SpecVersion::V3_0],
    ['openapi-3.1.yaml', SpecVersion::V3_1],
    ['openapi-3.1-webhooks-only.yaml', SpecVersion::V3_1],
]);

// Each guard in the pipeline, in the order the reader applies them. The order
// is load-bearing: a cyclic document must never reach the parser, so its guard
// has to come after decoding and before anything else touches the document.

it('reports a file that is not there', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('nope.yaml')))
        ->toThrow(UnreadableDocumentException::class, 'No specification file at');
});

it('reports malformed YAML with the parser reason', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('malformed.yaml')))
        ->toThrow(UnreadableDocumentException::class, 'is not valid YAML or JSON');
});

it('reports a document that is not a mapping', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('not-a-mapping.yaml')))
        ->toThrow(UnreadableDocumentException::class, 'must be a mapping');
});

it('rejects a version it does not implement', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('swagger-2.0.yaml')))
        ->toThrow(UnsupportedVersionException::class);
});

it('rejects a cyclic document before the parser can see it', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('cycle-pointer.yaml')))
        ->toThrow(CyclicReferenceException::class);
});

it('accepts a recursive schema', function (): void {
    expect((new SpecDocumentReader)->read(specFixturePath('recursive-schema.yaml'))->version)
        ->toBe(SpecVersion::V3_0);
});

it('rejects a document missing what its version requires', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('openapi-3.0-no-paths.yaml')))
        ->toThrow(InvalidDocumentException::class);
});

// One code path for both formats is a stated decision, so a real JSON fixture
// is what keeps it honest.
it('reads JSON through the same path', function (): void {
    expect((new SpecDocumentReader)->read(specFixturePath('openapi-3.1.json'))->version)
        ->toBe(SpecVersion::V3_1);
});

it('accepts an empty mapping and complains about the missing version, not the shape', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('empty-mapping.json')))
        ->toThrow(UnsupportedVersionException::class, 'declares no "openapi" version field');
});

it('calls a root-level list a list', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('root-list.yaml')))
        ->toThrow(UnreadableDocumentException::class, 'decodes to a list');
});

it('accepts the third root key 3.1 allows', function (): void {
    expect((new SpecDocumentReader)->read(specFixturePath('openapi-3.1-components-only.yaml'))->version)
        ->toBe(SpecVersion::V3_1);
});

// The skip measures the very thing it guards against rather than inferring it
// from the environment: it writes a file, removes every permission, and asks
// whether it can still be read. An earlier version tested /proc/1/mem and
// posix_geteuid(), which skipped inside the project's own Docker container — a
// test that never runs where the code is written is worth less than no test —
// and depended on ext-posix, which the package does not require.
it('reports a file it cannot read', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'lsf-');
    assert(is_string($path));
    file_put_contents($path, "openapi: 3.0.3\npaths: {}\n");
    chmod($path, 0o000);
    clearstatcache(true, $path);

    try {
        expect(fn () => (new SpecDocumentReader)->read($path))
            ->toThrow(UnreadableDocumentException::class, 'Check its permissions');
    } finally {
        chmod($path, 0o600);
        unlink($path);
    }
})->skip(function (): bool {
    $probe = tempnam(sys_get_temp_dir(), 'lsf-probe-');
    assert(is_string($probe));
    chmod($probe, 0o000);
    clearstatcache(true, $probe);
    $readsAnything = is_readable($probe);
    chmod($probe, 0o600);
    unlink($probe);

    return $readsAnything;
}, 'this user reads files it has no permission on');
