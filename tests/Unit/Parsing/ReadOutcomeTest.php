<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
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
