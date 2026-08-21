<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Guards\ReferenceCycleDetector;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

// The third equivalence class of the conformance suite: OpenAPI reuses `$ref`
// as a key name inside plain data — an `example`, a `default`, a JSON Schema
// `examples` list — and a document describing an API that itself handles JSON
// Schema will contain one for real. Confusing a literal for a reference in
// either direction is wrong: reading data as specification refuses a valid
// contract, and reading specification as data lets a real cycle through on
// exactly the shape the parser cannot survive. See
// docs/guide/openapi-support.md#reading-a-document and docs/project/roadmap.md.
//
// ReferenceCycleDetectorTest.php pins this same boundary against the guard in
// isolation, key by key. What belongs here instead is proof that the boundary
// holds through the whole reading engine, on documents shaped the way a real
// contract would write them.

/**
 * @return list<Operation>
 */
function extractBoundaryFixture(string $name): array
{
    return (new OperationExtractor)->extract((new SpecDocumentReader)->read(specFixturePath($name)));
}

// NOT run through the full pipeline, unlike its neighbours below — see the note
// at the bottom of this file. Pinned at the guard level only, the same way
// ReferenceCycleDetectorTest.php already does, until that note is resolved.
it('does not read a literal $ref inside an example value as a reference', function (): void {
    (new ReferenceCycleDetector)
        ->assertNoCycles(specFixture('ref-inside-example.yaml'));
})->throwsNoExceptions();

it('does not read a $ref inside the JSON Schema examples list as a reference', function (): void {
    extractBoundaryFixture('schema-examples-list.yaml');
})->throwsNoExceptions();

// NOT run through the full pipeline — see the note at the bottom of this file.
// Pinned at the guard level only, like its neighbour above.
it('does not follow the value of an OpenAPI Example Object', function (): void {
    (new ReferenceCycleDetector)
        ->assertNoCycles(specFixture('example-object-value.yaml'));
})->throwsNoExceptions();

// The map an Example Object lives in is specification, not data, precisely
// because an Example Object may itself be a Reference Object — so a cycle
// closing through it must still be caught.
it('still catches a cycle written through OpenAPI Example Objects', function (): void {
    expect(fn () => extractBoundaryFixture('cycle-in-example-objects.yaml'))
        ->toThrow(CyclicReferenceException::class);
});

// NOT asserted end to end, and neither is the guard test right above it:
// `ref-inside-example.yaml` and `example-object-value.yaml` are exactly the
// shapes the guard is designed to wave through — `example` and an Example
// Object's `value` are data, and their contents are correctly left unwalked,
// per the two tests above. But handed to the OpenAPI parser afterwards, on
// either document, a `$ref` that targets a JSON pointer landing inside that
// literal data resolves *through* it and recurses without ever reaching a
// schema — the same failure `cycle-pointer.yaml` produces, on a shape our own
// guard does not and, by its stated design, cannot see. Confirmed by hand
// against the vendored parser; not reproduced here, because there is no way to
// assert an unrecoverable fatal error in Pest without taking the test run down
// with it. Flagged for a decision rather than silently worked around — see
// KnownParserBugsTest.php.
