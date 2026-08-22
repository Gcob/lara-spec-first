<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\ApproachingSunset;
use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\LifecycleOutcome;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;

/**
 * @param  list<Finding>  $findings
 */
function reportWith(array $findings): DiagnosticReport
{
    return new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', null, [], $findings);
}

it('is clean, and exits zero, with no findings at all', function (): void {
    $report = reportWith([]);

    expect($report->isClean())->toBeTrue()
        ->and($report->hasDocumentFault())->toBeFalse()
        ->and($report->hasPackageLimit())->toBeFalse()
        ->and($report->exitCode())->toBe(0);
});

it('exits 1 on a document fault', function (): void {
    $report = reportWith([
        new Finding(FindingClass::DocumentFault, 'Document validity', null, '', 'broken'),
    ]);

    expect($report->isClean())->toBeFalse()
        ->and($report->hasDocumentFault())->toBeTrue()
        ->and($report->exitCode())->toBe(1);
});

it('exits 2 on a package limit alone', function (): void {
    $report = reportWith([
        new Finding(FindingClass::PackageLimit, 'References', SupportLevel::Rejected, '', 'refused'),
    ]);

    expect($report->hasDocumentFault())->toBeFalse()
        ->and($report->hasPackageLimit())->toBeTrue()
        ->and($report->exitCode())->toBe(2);
});

// A document fault is the harder failure of the two, and it must never be
// buried by a package limit's exit code just because the list happened to
// carry one of each.
it('a document fault wins the exit code over a package limit found alongside it', function (): void {
    $report = reportWith([
        new Finding(FindingClass::PackageLimit, 'References', SupportLevel::Rejected, '', 'refused'),
        new Finding(FindingClass::DocumentFault, 'Document validity', null, '', 'broken'),
    ]);

    expect($report->exitCode())->toBe(1);
});

// Decision, pinned rather than left to a reader's discretion: what the
// Lifecycle section reports without a finding — an approaching removal date,
// the beta listing, the protection report — never touches the exit code.
// Otherwise a repository green on Monday goes red on Tuesday with no commit
// in between, which is how a CI gate teaches a team to ignore it.
it('stays clean when the lifecycle outcome has something to say and no finding says it', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', null, [], [], [], new LifecycleOutcome(
        ['get /users'],
        [new ApproachingSunset('get /legacy', '2026-07-01', 30)],
        4,
        0,
    ));

    expect($report->isClean())->toBeTrue()
        ->and($report->exitCode())->toBe(0);
});

// The opposite decision, and the one that costs something: an operation
// documented as protected and served unprotected gates, forever, until
// enforcement lands.
it('exits 2 on a security finding alone', function (): void {
    $report = reportWith([
        new Finding(FindingClass::PackageLimit, 'Security', SupportLevel::Partial, '', 'not applied yet'),
    ]);

    expect($report->exitCode())->toBe(2);
});
