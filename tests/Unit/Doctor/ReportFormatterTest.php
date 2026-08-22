<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Doctor\ApproachingSunset;
use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\LifecycleOutcome;
use Gcob\LaraSpecFirst\Doctor\ReportFormatter;
use Gcob\LaraSpecFirst\Doctor\RouteOutcome;
use Gcob\LaraSpecFirst\Doctor\SectionNote;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;

// Every section in doctor.md's own table, in its order — including the four
// this release does not check. A section absent from the report is one a green
// exit silently claims to have covered.
it('names every section even when there is nothing to say in it', function (string $section): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], []);

    expect((new ReportFormatter)->toText($report))->toContain($section);
})->with([
    'Configuration',
    'Document validity',
    'Version',
    'References',
    'Support findings',
    'Routing outcome',
    'Security',
    'Drift',
    'Lifecycle',
    'Installation',
    'Baseline',
    'Drivers',
]);

it('prints clean and a clean summary when every built section had nothing to say', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], []);
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('clean')
        ->and($text)->toContain('Clean.');
});

// `clean` is a claim, so a section carrying a note never makes it: printing
// both would say two different things about the same section.
it('prints a note instead of clean, and never both', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [
        'Drift' => SectionNote::notChecked('the document could not be read'),
    ]);
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('[not checked] the document could not be read')
        ->and($text)->not->toContain("Drift\n  clean");
});

// A section that ran on less than it needed is badged differently and left out
// of the summary's uncovered list: its findings stand.
it('badges a narrowed section as a note rather than as unchecked', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [
        'Routing outcome' => SectionNote::narrowed('only what could be extracted is listed'),
    ]);
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('[note] only what could be extracted is listed')
        ->and($text)->not->toContain('[not checked] only what could be extracted')
        ->and($text)->not->toContain('Not covered by this run: Routing outcome');
});

// doctor.md's own rule about the exit code: a green exit is never mistaken for
// a full pass, so the summary line — the one a person skims and a CI log tails
// — names what did not run.
it('names the sections that did not run under the summary, on a clean report too', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], []);

    expect((new ReportFormatter)->toText($report))
        ->toContain('Not covered by this run: Baseline, Drivers');
});

// The level used to be dropped here and survive only in `--json`, so the one
// document fault that carries a level printed without the level that motivates
// it, while doctor.md promises the level on every finding.
it('prints the support level on a document fault that carries one', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', null, [], [
        new Finding(FindingClass::DocumentFault, 'Support findings', SupportLevel::Partial, '', 'no operationId'),
    ]);

    expect((new ReportFormatter)->toText($report))->toContain('[document fault: partial] no operationId');
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

it('encodes every note, with checked telling a skipped section from a narrowed one', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [
        'Routing outcome' => SectionNote::narrowed('partial table'),
        'Drift' => SectionNote::notChecked('nothing to compare'),
    ]);
    $decoded = json_decode((new ReportFormatter)->toJson($report), true);

    expect($decoded['notes']['Routing outcome'])->toBe(['checked' => true, 'note' => 'partial table'])
        ->and($decoded['notes']['Drift'])->toBe(['checked' => false, 'note' => 'nothing to compare'])
        // The two this release still never checks are in there too, so a
        // consumer reading `notes` learns the exit code's whole scope from one
        // key.
        ->and($decoded['notes']['Baseline']['checked'])->toBeFalse()
        ->and(array_keys($decoded['notes']))->toBe([
            'Routing outcome',
            'Drift',
            'Baseline',
            'Drivers',
        ]);
});

// --- Lifecycle and Security: the two sections this PR adds ---

it('names the two new sections in the order docs/guide/doctor.md gives them', function (): void {
    $expected = [
        'Configuration',
        'Version',
        'Document validity',
        'References',
        'Support findings',
        'Routing outcome',
        'Security',
        'Drift',
        'Lifecycle',
        'Installation',
    ];

    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], []);
    $headings = array_values(array_filter(
        explode("\n", (new ReportFormatter)->toText($report)),
        static fn (string $line): bool => in_array($line, $expected, true),
    ));

    expect($headings)->toBe($expected);
});

// The protection report is printed on every run, including the run where
// nothing is wrong: protection that is off must never look like protection
// that passed.
it('prints the protection report even when the Lifecycle section carries no finding', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [], new LifecycleOutcome(
        ['get /users'],
        [new ApproachingSunset('get /legacy', '2026-07-01', 30)],
        47,
        0,
    ));
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('0 of 47 public operation(s) are stable.')
        ->and($text)->toContain('nothing in it can be broken by accident')
        ->and($text)->toContain('beta: get /users')
        ->and($text)->toContain('sunset in 30 day(s): get /legacy on 2026-07-01')
        ->and($text)->not->toContain("Lifecycle\n  clean")
        // Still clean overall: none of the three lines above is a finding.
        ->and($text)->toContain('Clean.');
});

it('names an unread root security block once, under Security', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [], null, true);

    expect((new ReportFormatter)->toText($report))->toContain('root security block');
});

it('encodes the lifecycle outcome as its own object, beside the findings', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [], new LifecycleOutcome(
        ['get /users'],
        [new ApproachingSunset('get /legacy', '2026-07-01', 30)],
        47,
        3,
    ), true);
    $decoded = json_decode((new ReportFormatter)->toJson($report), true);

    expect($decoded['lifecycle'])->toBe([
        'beta' => ['get /users'],
        'approachingSunsets' => [[
            'operation' => 'get /legacy',
            'sunset' => '2026-07-01',
            'daysRemaining' => 30,
        ]],
        'publicOperations' => 47,
        'stablePublicOperations' => 3,
    ])
        ->and($decoded['inheritsUnreadRootRequirements'])->toBeTrue()
        ->and($decoded['summary']['exitCode'])->toBe(0);
});

// The opposite case, and the reason the line above is conditional: a
// document nothing could be read from would otherwise print "0 of 0 public
// operation(s) are stable", a sentence about an empty file rather than about
// a contract.
it('says nothing under Lifecycle when there is no contract to say it about', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', null, [], [], [], new LifecycleOutcome([], [], 0, 0));

    expect((new ReportFormatter)->toText($report))->toContain("Lifecycle\n  clean");
});

// An internal-only service: every operation `x-audience: internal`, one of them
// `beta`. The guard used to be an AND across all three fields, so a non-empty
// beta listing dragged "0 of 0 public operation(s) are stable" along with it —
// the exact sentence the guard exists to avoid, on a document that is not empty
// at all. The protection report is a statement about a public surface; with no
// public surface it has nothing to say, and the beta line stands on its own.
it('lists beta operations without claiming 0 of 0 when nothing is public', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [], new LifecycleOutcome(
        ['get /internal'],
        [],
        0,
        0,
    ));
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('beta: get /internal')
        ->and($text)->not->toContain('0 of 0 public operation(s)')
        ->and($text)->not->toContain('public operation(s) are stable');
});

// Same shape for an approaching sunset on an internal-only document.
it('lists an approaching sunset without claiming 0 of 0 when nothing is public', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], [], [], new LifecycleOutcome(
        [],
        [new ApproachingSunset('get /internal', '2026-07-01', 30)],
        0,
        0,
    ));
    $text = (new ReportFormatter)->toText($report);

    expect($text)->toContain('sunset in 30 day(s): get /internal on 2026-07-01')
        ->and($text)->not->toContain('public operation(s) are stable');
});

// A contract that promises nothing still prints, on every run: that is the
// case the protection report exists for.
it('prints the protection report for a contract whose public operations promise nothing', function (): void {
    $report = new DiagnosticReport('openapi.yaml', true, [], 'openapi-external-refs', null, [], [], [], new LifecycleOutcome([], [], 47, 0));

    expect((new ReportFormatter)->toText($report))->toContain('0 of 47 public operation(s) are stable.');
});
