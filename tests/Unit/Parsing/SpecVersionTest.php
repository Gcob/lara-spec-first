<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\SpecVersion;

// Detection is the first thing that happens to a document and the only thing
// that can happen before a version is known, so it is tested against raw arrays
// rather than through any loader.

it('detects the supported versions', function (string $declared, SpecVersion $expected): void {
    expect(SpecVersion::detect(['openapi' => $declared]))->toBe($expected);
})->with([
    ['3.0.0', SpecVersion::V3_0],
    ['3.0.3', SpecVersion::V3_0],
    ['3.1.0', SpecVersion::V3_1],
    ['3.1.1', SpecVersion::V3_1],
    ['3.1.1-rc.1', SpecVersion::V3_1],
]);

it('names OpenAPI 2.x rather than calling it unreadable', function (): void {
    expect(fn () => SpecVersion::detect(['swagger' => '2.0']))
        ->toThrow(UnsupportedVersionException::class, 'OpenAPI 2.x');
});

it('rejects a version it does not implement, and says which it does', function (string $declared): void {
    expect(fn () => SpecVersion::detect(['openapi' => $declared]))
        ->toThrow(UnsupportedVersionException::class, 'Supported versions: 3.0, 3.1.');
})->with(['2.0.0', '3.2.0', '4.0.0']);

it('rejects a document that declares no version', function (): void {
    expect(fn () => SpecVersion::detect(['info' => ['title' => 't']]))
        ->toThrow(UnsupportedVersionException::class, 'declares no "openapi" version field');
});

// An unquoted `openapi: 3.1` is read by YAML as the float 3.1, which is the
// single most likely way for a hand-written document to fail here. The message
// has to name the fix rather than the type.
it('explains the unquoted version mistake', function (): void {
    expect(fn () => SpecVersion::detect(['openapi' => 3.1]))
        ->toThrow(UnsupportedVersionException::class, 'openapi: "3.1.0"');
});

it('rejects a version field that is not a version', function (): void {
    expect(fn () => SpecVersion::detect(['openapi' => 'latest']))
        ->toThrow(UnsupportedVersionException::class, 'is not a version number');
});

it('detects the version of the fixture documents', function (string $fixture, SpecVersion $expected): void {
    $document = specFixture($fixture);

    expect(SpecVersion::detect($document))->toBe($expected);
})->with([
    ['openapi-3.0.yaml', SpecVersion::V3_0],
    ['openapi-3.1.yaml', SpecVersion::V3_1],
    ['openapi-3.1-webhooks-only.yaml', SpecVersion::V3_1],
]);

it('rejects the OpenAPI 2.x fixture', function (): void {
    $document = specFixture('swagger-2.0.yaml');

    expect(fn () => SpecVersion::detect($document))->toThrow(UnsupportedVersionException::class);
});
