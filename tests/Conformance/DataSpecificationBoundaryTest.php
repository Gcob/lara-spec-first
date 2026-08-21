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
// through the whole reading engine — which is why two of the four shapes that
// boundary names are asserted elsewhere instead of below: `ref-inside-example`
// and an Example Object's `value` are exactly the two shapes where the guard is
// correct and the parser is not. Guarded correctly, they would duplicate
// ReferenceCycleDetectorTest.php here; run end to end, they kill the process
// before any assertion in this file could run. Their permanent, end-to-end case
// lives in KnownParserBugsTest.php, in a child process, for that reason.

it('does not read a $ref inside the JSON Schema examples list as a reference', function (): void {
    expect(extractFixture('schema-examples-list.yaml'))->toHaveCount(1);
});

// The map an Example Object lives in is specification, not data, precisely
// because an Example Object may itself be a Reference Object — so a cycle
// closing through it must still be caught.
it('still catches a cycle written through OpenAPI Example Objects', function (): void {
    expect(fn () => extractFixture('cycle-in-example-objects.yaml'))
        ->toThrow(CyclicReferenceException::class);
});
