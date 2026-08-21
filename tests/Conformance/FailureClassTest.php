<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

// The fifth and last equivalence class of the conformance suite: why a document
// did not load. The doctor already keeps two kinds of finding apart — see
// docs/guide/doctor.md#two-kinds-of-finding-never-mixed — and this suite adds a
// third, specific to the reading engine rather than to the doctor's report:
//
//   Document fault  — the document is wrong. The spec author's to fix.
//   Package limit   — the document is correct; we choose not to serve the
//                      construct. Ours, on the roadmap.
//   Parser defect   — the dependency is wrong, not the document or our own
//                      choice: cebe\openapi\ cannot be trusted with this shape
//                      at all, so we refuse it ourselves before it gets there.
//
// A parser defect and a package limit can share one PHP exception type — both
// a `trace` operation and a `components.pathItems` reference throw
// RejectedConstructException — which is exactly why the class is not something
// `toThrow()` can prove on its own, and is asserted here in prose, fixture by
// fixture, instead. See docs/project/roadmap.md and
// docs/guide/openapi-support.md#parser-caveats.

/**
 * @return list<Operation>
 */
function extractFailureClassFixture(string $name): array
{
    return (new OperationExtractor)->extract((new SpecDocumentReader)->read(specFixturePath($name)));
}

describe('document fault: the spec author has something to fix', function (): void {
    it('refuses two operations that address one endpoint', function (): void {
        expect(fn () => extractFailureClassFixture('duplicate-endpoint.yaml'))
            ->toThrow(InvalidDocumentException::class);
    });
});

describe('package limit: the document is correct, we choose not to serve it', function (): void {
    it('refuses a trace operation, which Laravel\'s router has no verb for', function (): void {
        expect(fn () => extractFailureClassFixture('trace-operation.yaml'))
            ->toThrow(RejectedConstructException::class, 'Laravel has no TRACE verb');
    });
});

// Both cases below are guards against the parser itself, not against the
// document or a construct we merely decline. Each has a permanent case of its
// own in KnownParserBugsTest.php; what belongs here is that they read as a
// distinct *class* of failure even though one of them wears the same exception
// type as a package limit above.
describe('parser defect: the dependency cannot be trusted with this shape', function (): void {
    it('refuses a pure reference cycle, which exhausts the parser\'s memory instead of raising', function (): void {
        expect(fn () => extractFailureClassFixture('cycle-pointer.yaml'))
            ->toThrow(CyclicReferenceException::class);
    });

    it('refuses a Path Item reference the parser would drop in silence', function (): void {
        expect(fn () => extractFailureClassFixture('path-item-ref-component.yaml'))
            ->toThrow(RejectedConstructException::class, 'does not model `components.pathItems`');
    });
});
