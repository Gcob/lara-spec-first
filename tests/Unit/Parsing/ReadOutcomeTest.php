<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\ParserUnsafeFault;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

it('reads a clean document straight through to its operations', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('operations.yaml'));

    expect($outcome->isClean())->toBeTrue()
        ->and($outcome->document)->not->toBeNull()
        ->and($outcome->operations)->toHaveCount(4);
});

it('never attempts extraction when the document itself could not be read at all', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('nope.yaml'));

    expect($outcome->document)->toBeNull()
        ->and($outcome->operations)->toBe([])
        ->and($outcome->faults)->toHaveCount(1)
        ->and($outcome->faults[0])->toBeInstanceOf(UnreadableDocumentException::class);
});

// The critical case this class exists to get right. A cyclic chain is the one
// document fault `cebe\openapi\` cannot survive — it exhausts memory instead of
// raising — and detecting it only ever *reports* it: nothing rewrites the
// document to remove it, unlike a neutralized remote reference. So this must
// never reach OperationExtractor while one is present. This test running to
// completion at all, rather than exhausting the memory limit, is the proof.
it('never hands a cyclic document to the parser, even though the document itself is still known', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('cycle-pointer.yaml'));

    expect($outcome->document)->not->toBeNull()
        ->and($outcome->operations)->toBe([])
        ->and($outcome->faults)->toHaveCount(1)
        ->and($outcome->faults[0])->toBeInstanceOf(CyclicReferenceException::class);
});

// The marker rather than the class name. `read()`'s gate used to ask
// `instanceof CyclicReferenceException`, which made every parser killer this
// package cannot detect *yet* — the conformance suite already records two — a
// line somebody would have to remember to add on the day it becomes
// detectable, with a dead process rather than a failing assertion as the
// symptom. Asking for {@see ParserUnsafeFault} instead makes that case
// additive, and this is the assertion that ties the fault the detector produces
// to the gate that reads it.
it('marks the fault the parser cannot survive rather than naming its class at the gate', function (): void {
    expect(CyclicReferenceException::chain(['#/a', '#/b', '#/a']))
        ->toBeInstanceOf(ParserUnsafeFault::class);
});

// What a caller that reports rather than refuses needs, and could not otherwise
// know: these operations were extracted from a document the guard rewrote, so
// they describe less of the API than the file does. `spec:build` never meets
// this — it stops at the first fault and generates nothing — which is exactly
// why the flag has to be carried rather than inferred at the one call site that
// cares.
it('carries the fact that the document was rewritten through to its caller', function (): void {
    $rewritten = ReadOutcome::read(new SpecDocumentReader, specFixturePath('remote-reference.yaml'));
    $untouched = ReadOutcome::read(new SpecDocumentReader, specFixturePath('operations.yaml'));

    expect($rewritten->neutralized)->toBeTrue()
        ->and($rewritten->isClean())->toBeFalse()
        ->and($untouched->neutralized)->toBeFalse()
        ->and($untouched->isClean())->toBeTrue();
});

// A cyclic document is never extracted, so it never carries operations —
// whichever extractor it is given. Passing one here is the only way to say that
// the assembly is injectable end to end rather than half newed up; the class
// being `final` and stateless is why there is nothing more to observe about it.
it('extracts through the extractor it was given', function (): void {
    $outcome = ReadOutcome::read(
        new SpecDocumentReader,
        specFixturePath('operations.yaml'),
        extractor: new OperationExtractor,
    );

    expect($outcome->isClean())->toBeTrue()
        ->and($outcome->operations)->toHaveCount(4);
});
