<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
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
