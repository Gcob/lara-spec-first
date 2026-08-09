<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\SpecVersion;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi30Strategy;

function fixturePath(string $name): string
{
    return __DIR__.'/../../Fixtures/'.$name;
}

it('reads a document and carries its version and strategy', function (): void {
    $document = (new SpecDocumentReader)->read(fixturePath('openapi-3.0.yaml'));

    expect($document->version)->toBe(SpecVersion::V3_0)
        ->and($document->strategy)->toBeInstanceOf(OpenApi30Strategy::class)
        ->and($document->data)->toHaveKey('paths')
        ->and($document->path)->toEndWith('openapi-3.0.yaml');
});

it('reads both supported versions', function (string $name, SpecVersion $expected): void {
    expect((new SpecDocumentReader)->read(fixturePath($name))->version)->toBe($expected);
})->with([
    ['openapi-3.0.yaml', SpecVersion::V3_0],
    ['openapi-3.1.yaml', SpecVersion::V3_1],
    ['openapi-3.1-webhooks-only.yaml', SpecVersion::V3_1],
]);

// Each guard in the pipeline, in the order the reader applies them. The order
// is load-bearing: a cyclic document must never reach the parser, so its guard
// has to come after decoding and before anything else touches the document.

it('reports a file that is not there', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(fixturePath('nope.yaml')))
        ->toThrow(UnreadableDocumentException::class, 'No specification file at');
});

it('reports malformed YAML with the parser reason', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(fixturePath('malformed.yaml')))
        ->toThrow(UnreadableDocumentException::class, 'is not valid YAML or JSON');
});

it('reports a document that is not a mapping', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(fixturePath('not-a-mapping.yaml')))
        ->toThrow(UnreadableDocumentException::class, 'must be a mapping');
});

it('rejects a version it does not implement', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(fixturePath('swagger-2.0.yaml')))
        ->toThrow(UnsupportedVersionException::class);
});

it('rejects a cyclic document before the parser can see it', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(fixturePath('cycle-pointer.yaml')))
        ->toThrow(CyclicReferenceException::class);
});

it('accepts a recursive schema', function (): void {
    expect((new SpecDocumentReader)->read(fixturePath('recursive-schema.yaml'))->version)
        ->toBe(SpecVersion::V3_0);
});

it('rejects a document missing what its version requires', function (): void {
    $path = sys_get_temp_dir().'/lsf-no-paths.yaml';
    file_put_contents($path, "openapi: 3.0.3\ninfo:\n  title: t\n  version: 1.0.0\n");

    try {
        expect(fn () => (new SpecDocumentReader)->read($path))
            ->toThrow(InvalidDocumentException::class);
    } finally {
        @unlink($path);
    }
});

it('reads JSON through the same path', function (): void {
    $path = sys_get_temp_dir().'/lsf-spec.json';
    file_put_contents($path, '{"openapi":"3.1.0","info":{"title":"t","version":"1.0.0"},"paths":{}}');

    try {
        expect((new SpecDocumentReader)->read($path)->version)->toBe(SpecVersion::V3_1);
    } finally {
        @unlink($path);
    }
});
