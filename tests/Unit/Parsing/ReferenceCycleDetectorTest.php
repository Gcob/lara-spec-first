<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Guards\ReferenceCycleDetector;

// The distinction this class exists to make. If only one test survives a
// refactor, it should be this pair.

it('accepts a schema that refers back to itself through content', function (): void {
    (new ReferenceCycleDetector)->assertNoCycles(specFixture('recursive-schema.yaml'));
})->throwsNoExceptions();

it('rejects a chain of references that never reaches content', function (): void {
    expect(fn () => (new ReferenceCycleDetector)->assertNoCycles(specFixture('cycle-pointer.yaml')))
        ->toThrow(CyclicReferenceException::class);
});

it('names the whole chain so the cycle can be found', function (): void {
    expect(fn () => (new ReferenceCycleDetector)->assertNoCycles(specFixture('cycle-pointer.yaml')))
        ->toThrow(
            CyclicReferenceException::class,
            '#/components/schemas/A -> #/components/schemas/B -> #/components/schemas/A'
        );
});

it('rejects a reference to itself', function (): void {
    $document = ['components' => ['schemas' => ['A' => ['$ref' => '#/components/schemas/A']]]];

    expect(fn () => (new ReferenceCycleDetector)->assertNoCycles($document))
        ->toThrow(CyclicReferenceException::class);
});

it('rejects a longer cycle', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['$ref' => '#/components/schemas/B'],
        'B' => ['$ref' => '#/components/schemas/C'],
        'C' => ['$ref' => '#/components/schemas/A'],
    ]]];

    expect(fn () => (new ReferenceCycleDetector)->assertNoCycles($document))
        ->toThrow(CyclicReferenceException::class);
});

it('accepts a chain of references that does reach content', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['$ref' => '#/components/schemas/B'],
        'B' => ['$ref' => '#/components/schemas/C'],
        'C' => ['type' => 'string'],
    ]]];

    (new ReferenceCycleDetector)->assertNoCycles($document);
})->throwsNoExceptions();

// Anything needing the vendored copies is out of reach until they are loaded.
// Stated as a test so the limit is visible rather than implied.
it('ignores references it cannot resolve without the vendored copies', function (mixed $ref): void {
    $document = ['components' => ['schemas' => ['A' => ['$ref' => $ref]]]];

    (new ReferenceCycleDetector)->assertNoCycles($document);
})->with([
    'another file' => ['common.yaml#/components/schemas/A'],
    'a URL' => ['https://example.com/schemas.yaml#/A'],
    'not a string' => [42],
])->throwsNoExceptions();

it('walks path templates without mistaking them for pointer segments', function (): void {
    $document = ['paths' => ['/users/{id}' => ['get' => ['responses' => ['200' => [
        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/A']]],
    ]]]]], 'components' => ['schemas' => ['A' => ['$ref' => '#/paths/~1users~1{id}/get/responses/200/content/application~1json/schema']]]];

    expect(fn () => (new ReferenceCycleDetector)->assertNoCycles($document))
        ->toThrow(CyclicReferenceException::class);
});

it('accepts the documents that carry no cycle', function (string $name): void {
    (new ReferenceCycleDetector)->assertNoCycles(specFixture($name));
})->with([
    'openapi-3.0.yaml',
    'openapi-3.1.yaml',
    'openapi-3.1-webhooks-only.yaml',
])->throwsNoExceptions();

// `$ref` is a legal key name inside a value. An API that itself handles JSON
// Schema will carry one in an example, and refusing to load such a document
// would be the worst failure this class can produce — a valid contract turned
// away, where a false negative would merely leave the parser to complain.
it('does not read a literal $ref inside a value as a reference', function (): void {
    (new ReferenceCycleDetector)->assertNoCycles(specFixture('ref-inside-example.yaml'));
})->throwsNoExceptions();

it('treats every data-carrying key as opaque', function (string $key): void {
    $document = ['components' => ['schemas' => [
        'A' => ['type' => 'object', $key => ['$ref' => '#/components/schemas/B']],
        'B' => ['$ref' => '#/components/schemas/A/'.$key],
    ]]];

    (new ReferenceCycleDetector)->assertNoCycles($document);
})->with(['example', 'examples', 'default', 'enum', 'const'])->throwsNoExceptions();

// Stated so the gap is visible: `follow()` compares pointers for equality, not
// containment, so a reference aimed at one of its own ancestors is not caught.
// A false negative, which leaves the parser to fail rather than refusing a
// document that was fine.
it('does not catch a reference aimed at its own ancestor', function (): void {
    $document = ['components' => ['schemas' => ['A' => ['$ref' => '#/components/schemas']]]];

    (new ReferenceCycleDetector)->assertNoCycles($document);
})->throwsNoExceptions();
