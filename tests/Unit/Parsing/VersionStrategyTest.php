<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\SpecVersion;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi30Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi31Strategy;
use Gcob\LaraSpecFirst\Parsing\VersionStrategyFactory;
use Symfony\Component\Yaml\Yaml;

it('selects a strategy per version', function (SpecVersion $version, string $expected): void {
    /** @var class-string $expected */
    expect((new VersionStrategyFactory)->for($version))->toBeInstanceOf($expected);
})->with([
    [SpecVersion::V3_0, OpenApi30Strategy::class],
    [SpecVersion::V3_1, OpenApi31Strategy::class],
]);

it('selects a strategy straight from a document', function (): void {
    $strategy = (new VersionStrategyFactory)->forDocument(['openapi' => '3.1.0']);

    expect($strategy->version())->toBe(SpecVersion::V3_1);
});

it('refuses to select a strategy for a version it does not implement', function (): void {
    expect(fn () => (new VersionStrategyFactory)->forDocument(['openapi' => '2.0.0']))
        ->toThrow(UnsupportedVersionException::class);
});

// The one document-shape rule that genuinely differs between the two versions:
// 3.1 made `paths` optional, so a webhooks-only document is valid there and
// impossible at 3.0. Nothing downstream should ever have to know that.
it('requires paths at 3.0', function (): void {
    $document = ['openapi' => '3.0.3', 'info' => ['title' => 't', 'version' => '1.0.0']];

    expect(fn () => (new OpenApi30Strategy)->assertDocumentShape($document))
        ->toThrow(InvalidDocumentException::class, 'must declare "paths" at its root');
});

it('accepts a 3.1 document carrying webhooks but no paths', function (): void {
    /** @var array<string, mixed> $document */
    $document = Yaml::parseFile(__DIR__.'/../../Fixtures/openapi-3.1-webhooks-only.yaml');

    (new OpenApi31Strategy)->assertDocumentShape($document);
})->throwsNoExceptions();

it('rejects a 3.1 document carrying none of the root keys', function (): void {
    $document = ['openapi' => '3.1.0', 'info' => ['title' => 't', 'version' => '1.0.0']];

    expect(fn () => (new OpenApi31Strategy)->assertDocumentShape($document))
        ->toThrow(InvalidDocumentException::class, '"paths", "webhooks" or "components"');
});

it('accepts the fixture documents at their own version', function (string $fixture): void {
    /** @var array<string, mixed> $document */
    $document = Yaml::parseFile(__DIR__.'/../../Fixtures/'.$fixture);

    (new VersionStrategyFactory)->forDocument($document)->assertDocumentShape($document);
})->with([
    'openapi-3.0.yaml',
    'openapi-3.1.yaml',
    'openapi-3.1-webhooks-only.yaml',
])->throwsNoExceptions();
