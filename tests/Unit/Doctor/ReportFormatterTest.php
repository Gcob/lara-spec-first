<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\ReportFormatter;
use Gcob\LaraSpecFirst\Doctor\RouteOutcome;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;

it('names every section even when there is nothing to say in it', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], []);
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('Document validity')
        ->and($text)->toContain('References')
        ->and($text)->toContain('Routing outcome')
        ->and($text)->toContain('Drift')
        ->and($text)->toContain('clean')
        ->and($text)->toContain('Clean.');
});

it('shows the resolved configuration', function (): void {
    $report = new DiagnosticReport(
        '/app/openapi.yaml',
        true,
        ['schemas.example.com'],
        'openapi-external-refs',
        SpecVersion::V3_1,
        [],
        [],
    );
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('/app/openapi.yaml')
        ->and($text)->toContain('schemas.example.com')
        ->and($text)->toContain('openapi-external-refs')
        ->and($text)->toContain('3.1');
});

it('says the spec file was not found', function (): void {
    $report = new DiagnosticReport('/app/openapi.yaml', false, [], 'openapi-external-refs', null, [], []);

    expect((new ReportFormatter)->toText($report))->toContain('not found');
});

it('labels a document fault and a package limit distinctly', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', null, [], [
        new Finding(FindingClass::DocumentFault, 'Document validity', null, '', 'the document is broken'),
        new Finding(FindingClass::PackageLimit, 'References', SupportLevel::Rejected, '', 'this package refuses it'),
    ]);
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('[document fault] the document is broken')
        ->and($text)->toContain('[package limit: rejected] this package refuses it')
        ->and($text)->toContain('1 document fault(s), 1 package limit(s).');
});

// The resolved routing table, per docs/guide/doctor.md: the outcome, not only
// the problems, so it prints even when the section carries no finding.
it('prints the resolved routing table even when Routing outcome is otherwise clean', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [
        new RouteOutcome('GET', '/users/{id}', 'App\\Http\\Controllers\\ShowUserController', true),
        new RouteOutcome('POST', '/users', 'App\\Http\\Generated\\Controllers\\CreateUserController', false),
    ], []);
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('GET')
        ->and($text)->toContain('/users/{id}')
        ->and($text)->toContain('App\\Http\\Controllers\\ShowUserController')
        ->and($text)->toContain('501, unimplemented')
        ->and($text)->not->toContain("Routing outcome\n  clean");
});

// --- toJson(): the same report, machine-readable ---

it('encodes a clean report as valid, parseable JSON', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], []);
    $decoded = json_decode((new ReportFormatter)->toJson($report), true);

    expect($decoded)->toBeArray()
        ->and($decoded['spec'])->toBe('openapi.yaml')
        ->and($decoded['specFileFound'])->toBeTrue()
        ->and($decoded['version'])->toBe('3.0')
        ->and($decoded['findings'])->toBe([])
        ->and($decoded['summary']['exitCode'])->toBe(0)
        ->and($decoded['summary']['clean'])->toBeTrue();
});

it('encodes each finding as a flat object, class and level as their own string values', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, ['schemas.example.com'], 'openapi-external-refs', null, [], [
        new Finding(FindingClass::DocumentFault, 'Document validity', null, '', 'broken'),
        new Finding(FindingClass::PackageLimit, 'References', SupportLevel::Rejected, '#/paths/~1debug', 'refused'),
    ]);
    $decoded = json_decode((new ReportFormatter)->toJson($report), true);

    expect($decoded['findings'])->toHaveCount(2)
        ->and($decoded['findings'][0])->toBe([
            'class' => 'document_fault',
            'section' => 'Document validity',
            'level' => null,
            'pointer' => '',
            'message' => 'broken',
        ])
        ->and($decoded['findings'][1])->toBe([
            'class' => 'package_limit',
            'section' => 'References',
            'level' => 'rejected',
            'pointer' => '#/paths/~1debug',
            'message' => 'refused',
        ])
        ->and($decoded['configuration'])->toBe([
            'allowedHosts' => ['schemas.example.com'],
            'vendorPath' => 'openapi-external-refs',
        ])
        ->and($decoded['summary']['exitCode'])->toBe(1)
        ->and($decoded['summary']['clean'])->toBeFalse();
});

it('encodes the resolved routing table', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [
        new RouteOutcome('GET', '/users/{id}', 'App\\Http\\Controllers\\ShowUserController', true),
    ], []);
    $decoded = json_decode((new ReportFormatter)->toJson($report), true);

    expect($decoded['routes'])->toBe([[
        'method' => 'GET',
        'path' => '/users/{id}',
        'target' => 'App\\Http\\Controllers\\ShowUserController',
        'routesToCustomController' => true,
    ]]);
});
