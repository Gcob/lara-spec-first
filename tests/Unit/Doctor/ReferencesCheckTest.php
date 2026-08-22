<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\Checks\ReferencesCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

it('is silent on a document with no unresolved references', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('operations.yaml'));

    expect(ReferencesCheck::check($outcome))->toBe([]);
});

it('turns a pure reference cycle into a document fault finding', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('cycle-pointer.yaml'));
    $findings = ReferencesCheck::check($outcome);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->section)->toBe('References')
        ->and($findings[0]->message)->toContain('closes a cycle');
});

it('turns a disallowed remote reference into a package limit finding', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('remote-reference.yaml'));
    $findings = ReferencesCheck::check($outcome);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->level?->value)->toBe('rejected')
        ->and($findings[0]->section)->toBe('References');
});
