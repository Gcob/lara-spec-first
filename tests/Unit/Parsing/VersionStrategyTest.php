<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi30Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi31Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;

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
    $document = specFixture('openapi-3.1-webhooks-only.yaml');

    (new OpenApi31Strategy)->assertDocumentShape($document);
})->throwsNoExceptions();

it('rejects a 3.1 document carrying none of the root keys', function (): void {
    $document = ['openapi' => '3.1.0', 'info' => ['title' => 't', 'version' => '1.0.0']];

    expect(fn () => (new OpenApi31Strategy)->assertDocumentShape($document))
        ->toThrow(InvalidDocumentException::class, '"paths", "webhooks" or "components"');
});

it('accepts the fixture documents at their own version', function (string $fixture): void {
    $document = specFixture($fixture);

    (new VersionStrategyFactory)->forDocument($document)->assertDocumentShape($document);
})->with([
    'openapi-3.0.yaml',
    'openapi-3.1.yaml',
    'openapi-3.1-webhooks-only.yaml',
])->throwsNoExceptions();

// The four keywords the two strategies disagree about, asserted on the
// strategies themselves rather than through a document. The conformance suite
// pins what a *contract* comes out as; these pin the seam, so a failure says
// which version's reading is wrong rather than only that the two disagree.

it('reads a 3.0 type as the one string it is allowed to be', function (): void {
    $schema = (new OpenApi30Strategy)->normalizeSchema(['type' => 'string']);

    expect($schema->types)->toBe([SchemaType::String]);
});

it('adds null to a 3.0 type list when the document writes nullable', function (): void {
    $schema = (new OpenApi30Strategy)->normalizeSchema(['type' => 'string', 'nullable' => true]);

    expect($schema->types)->toBe([SchemaType::String, SchemaType::Null]);
});

// `nullable` with no type beside it claims a value may be null while saying
// nothing about what else it may be. A list holding only `null` would read as
// "this must be null", which is a constraint the document did not write.
it('does not invent a type from a lone 3.0 nullable', function (): void {
    $schema = (new OpenApi30Strategy)->normalizeSchema(['nullable' => true]);

    expect($schema->types)->toBe([])
        ->and($schema->isNullable())->toBeFalse();
});

it('reads a 3.1 type written either way', function (mixed $written, array $expected): void {
    expect((new OpenApi31Strategy)->normalizeSchema(['type' => $written])->types)->toBe($expected);
})->with([
    'a string' => ['integer', [SchemaType::Integer]],
    'a list' => [['string', 'null'], [SchemaType::String, SchemaType::Null]],
    'a list of one' => [['string'], [SchemaType::String]],
    'a type nobody defined' => ['money', []],
]);

// 3.1 removed `nullable`. A document writing it under a 3.1 header is writing a
// 3.0 keyword, and honoring it would make this package accept a spelling the
// version does not have.
it('ignores nullable at 3.1', function (): void {
    $schema = (new OpenApi31Strategy)->normalizeSchema(['type' => 'string', 'nullable' => true]);

    expect($schema->isNullable())->toBeFalse();
});

it('moves a 3.0 exclusive bound onto the number that carries it', function (): void {
    $schema = (new OpenApi30Strategy)->normalizeSchema([
        'minimum' => 0,
        'exclusiveMinimum' => true,
        'maximum' => 10,
        'exclusiveMaximum' => false,
    ]);

    expect($schema->exclusiveMinimum)->toBe(0.0)
        ->and($schema->minimum)->toBeNull()
        ->and($schema->maximum)->toBe(10.0)
        ->and($schema->exclusiveMaximum)->toBeNull();
});

// A boolean with nothing to modify says nothing at all, and inventing a bound
// of zero for it would refuse payloads the contract accepts.
it('ignores a 3.0 exclusive flag with no bound beside it', function (): void {
    $schema = (new OpenApi30Strategy)->normalizeSchema(['exclusiveMinimum' => true]);

    expect($schema->exclusiveMinimum)->toBeNull()
        ->and($schema->minimum)->toBeNull();
});

it('leaves a 3.1 exclusive bound where the document wrote it', function (): void {
    $schema = (new OpenApi31Strategy)->normalizeSchema(['exclusiveMinimum' => 0, 'maximum' => 10]);

    expect($schema->exclusiveMinimum)->toBe(0.0)
        ->and($schema->minimum)->toBeNull()
        ->and($schema->maximum)->toBe(10.0);
});

it('names a file part from the spelling its version has', function (): void {
    expect((new OpenApi30Strategy)->normalizeSchema(['type' => 'string', 'format' => 'binary'])->isFilePart)
        ->toBeTrue()
        ->and((new OpenApi31Strategy)->normalizeSchema(['type' => 'string', 'contentMediaType' => 'image/png'])->isFilePart)
        ->toBeTrue()
        ->and((new OpenApi30Strategy)->normalizeSchema(['type' => 'string', 'contentMediaType' => 'image/png'])->isFilePart)
        ->toBeFalse();
});

// `format: binary` is not a format a generator looks up; it is how 3.0 says
// "this is a file". Leaving it in `format` as well would state one fact twice,
// and it would make a 3.0 schema differ from its 3.1 twin over a keyword the
// newer version does not have.
it('consumes format binary rather than carrying it twice', function (): void {
    $schema = (new OpenApi30Strategy)->normalizeSchema(['type' => 'string', 'format' => 'binary']);

    expect($schema->format)->toBeNull()
        ->and((new OpenApi30Strategy)->normalizeSchema(['format' => 'uuid'])->format)->toBe('uuid');
});

// Both spellings of one example, and the rule when a half-migrated document
// writes the two: the newer half states the intention.
it('reads examples into one list', function (array $keywords, array $expected): void {
    expect((new OpenApi31Strategy)->normalizeSchema($keywords)->examples)->toBe($expected);
})->with([
    'the 3.0 singular' => [['example' => 'Ada'], ['Ada']],
    'the 3.1 list' => [['examples' => ['Ada', 'Grace']], ['Ada', 'Grace']],
    'both written' => [['example' => 'Ada', 'examples' => ['Grace']], ['Grace']],
    'neither' => [[], []],
]);

// `const` is 3.1's one-value `enum`, and the matrix says to treat it as one.
it('folds const into the enum it is', function (): void {
    expect((new OpenApi31Strategy)->normalizeSchema(['const' => 'admin'])->enum)->toBe(['admin'])
        ->and((new OpenApi31Strategy)->normalizeSchema(['enum' => ['a', 'b'], 'const' => 'a'])->enum)
        ->toBe(['a', 'b']);
});

// Only `false` is a constraint this package reads. A schema under the keyword
// is a rule for fields whose names are not known in advance, which no Laravel
// rule reaches, so it comes back as the silence it will be treated as.
it('reads additionalProperties only when it is a boolean', function (mixed $written, ?bool $expected): void {
    expect((new OpenApi31Strategy)->normalizeSchema(['additionalProperties' => $written])->additionalProperties)
        ->toBe($expected);
})->with([
    'forbidden' => [false, false],
    'allowed' => [true, true],
    'a schema' => [new Schema(types: [SchemaType::String]), null],
]);
