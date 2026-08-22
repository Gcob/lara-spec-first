<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\Checks\DocumentValidityCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

it('is silent on a clean document', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('operations.yaml'));

    expect(DocumentValidityCheck::check($outcome))->toBe([]);
});

it('turns an unreadable document into a document fault', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('root-list.yaml'));
    $findings = DocumentValidityCheck::check($outcome);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->section)->toBe('Document validity');
});

// Reference faults belong to ReferencesCheck, not this one — asserted here so
// the two checks stay a partition rather than one swallowing the other's work.
it('leaves reference faults for ReferencesCheck', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('cycle-pointer.yaml'));

    expect(DocumentValidityCheck::check($outcome))->toBe([]);
});

// Several document faults in one document each get their own finding, since
// the extractor collects instead of stopping at the first one.
it('reports every document fault the read collected, not only the first', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('multiple-faults.yaml'));
    $findings = DocumentValidityCheck::check($outcome);

    // multiple-faults.yaml carries one Document validity fault (the
    // unrecognized x-lifecycle value) and one duplicate endpoint — both
    // InvalidDocumentException, both Document validity. The trace operation
    // in the same fixture is a Support findings fault, not this section's.
    expect($findings)->toHaveCount(2);

    foreach ($findings as $finding) {
        expect($finding->section)->toBe('Document validity');
    }
});
