<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;

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
// isolation, key by key. What belongs here is proof that the boundary holds
// through the whole reading engine, in both directions — a document whose data
// merely contains a `$ref` is read, and a document that aims a reference at the
// position holding one is refused.
//
// Which key the data sits under makes no difference to either direction, so the
// two remaining spellings of the refusal — a schema's `example`, and an Example
// Object's `value` — are not repeated below. Their permanent end-to-end case
// lives in KnownParserBugsTest.php, where it stays because those two documents
// used to kill the process rather than raise, and that file is the record of
// every parser defect this package has found.

it('does not read a $ref inside the JSON Schema examples list as a reference', function (): void {
    expect(extractFixture('examples-list-is-data.yaml'))->toHaveCount(1);
});

// The same list keyword, with a Reference Object aimed at the position the
// literal occupies. The literal is still not collected as a reference; it is
// reached because something points at it, which is what the parser does too.
it('refuses a document that aims a reference into that list', function (): void {
    expect(fn () => extractFixture('schema-examples-list.yaml'))
        ->toThrow(CyclicReferenceException::class);
});

// The map an Example Object lives in is specification, not data, precisely
// because an Example Object may itself be a Reference Object — so a cycle
// closing through it must still be caught.
it('still catches a cycle written through OpenAPI Example Objects', function (): void {
    expect(fn () => extractFixture('cycle-in-example-objects.yaml'))
        ->toThrow(CyclicReferenceException::class);
});
