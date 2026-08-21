<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A generated tree of its own, never written to and never created on disk —
 * Routing outcome and Drift both read whatever `generated.path` names, and
 * pointing at the real configured tree (workbench's, in this repository's
 * own test run) would make these tests report drift against content this
 * file has no control over. Not creating it at all is deliberate too: every
 * test here is then a fresh clone as far as Drift is concerned, which is its
 * own legitimate case — see DriftCheck — so nothing needs writing or
 * cleaning up either.
 *
 * **Namespaced under `App\`, mapped to `app/` by Testbench's own skeleton
 * `composer.json`.** Installation checks `generated.namespace` against the
 * *real* `autoload.psr-4` map, and `base_path()` under a booted Testbench
 * application is that skeleton's root — the same `App\` -> `app/` a real
 * consumer application maps, and the same pair `generated.namespace`
 * defaults to in config/lara-spec-first.php, so this is also the realistic
 * case rather than an arbitrary one picked to dodge the check.
 */
function doctorTree(): string
{
    static $tree = null;

    return $tree ??= base_path('app/LsfDoctorScratch'.bin2hex(random_bytes(6)));
}

function doctorNamespace(): string
{
    static $namespace = null;

    return $namespace ??= 'App\\'.basename(doctorTree());
}

beforeEach(function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('operations.yaml'));
    config()->set(GeneratedRoutesLocator::SETTING, doctorTree());
    config()->set('lara-spec-first.generated.namespace', doctorNamespace());
});

/**
 * @return array{0: int, 1: string}
 */
function doctor(): array
{
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('spec:doctor', [], $output);

    return [$exit, $output->fetch()];
}

// The validation criterion the whole PR is built around: a complete
// diagnostic, on a valid contract, with zero findings.
it('exits clean on a valid contract, with no false positives', function (): void {
    [$exit, $text] = doctor();

    expect($exit)->toBe(0)
        ->and($text)->toContain('Clean.')
        ->and($text)->not->toContain('document fault')
        ->and($text)->not->toContain('package limit');
});

it('shows the resolved configuration and detected version', function (): void {
    [, $text] = doctor();

    expect($text)->toContain(specFixturePath('operations.yaml'))
        ->and($text)->toContain('3.0');
});

it('exits 1 and names the fault on a document that is not valid OpenAPI', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('openapi-3.0-no-paths.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        ->and($text)->toContain('[document fault]')
        ->and($text)->toContain('must declare "paths"');
});

// A pure reference cycle is the one document fault the OpenAPI parser cannot
// survive — see ReadOutcomeTest, which pins the same guarantee at the
// pipeline level. Exercised again here to prove the command itself survives
// it end to end, rather than trusting that nothing between the pipeline and
// the console output reintroduces the crash.
it('survives a cyclic document and reports it as a document fault rather than exhausting memory', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('cycle-pointer.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        ->and($text)->toContain('[document fault]')
        ->and($text)->toContain('closes a cycle');
});

it('exits 2 on a package limit with no document fault alongside it', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('remote-reference.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(2)
        ->and($text)->toContain('[package limit: rejected]')
        ->and($text)->toContain('will not fetch');
});

// A trace operation is a Support finding, not a References one — a package
// limit in its own right, exercised through the whole command rather than
// only at SupportMatrixCheck's own unit level.
it('exits 2 on a rejected construct under Support findings, with no document fault alongside it', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('trace-operation.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(2)
        ->and($text)->toContain('[package limit: rejected]')
        ->and($text)->toContain('Laravel has no TRACE verb');
});

it('reports every fault the reading pipeline collected, not only the first', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('multiple-faults.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        // The duplicate endpoint and the unrecognized x-lifecycle value are
        // Document validity faults; the trace operation is a Support
        // finding — a package limit, not a document fault, so it does not
        // add to this count even though it does appear in the report.
        ->and(substr_count($text, '[document fault]'))->toBe(2)
        ->and(substr_count($text, '[package limit:'))->toBe(1)
        ->and($text)->toContain('Laravel has no TRACE verb');
});

it('respects --spec over the configured path', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('trace-operation.yaml'));

    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('spec:doctor', ['--spec' => specFixturePath('operations.yaml')], $output);

    expect($exit)->toBe(0)
        ->and($output->fetch())->toContain('Clean.');
});

it('reports a missing specification as a document fault rather than a stack trace', function (): void {
    config()->set('lara-spec-first.spec.path', '/does/not/exist.yaml');

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        ->and($text)->toContain('No specification file at');
});

it('emits parseable JSON findings with --json', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('trace-operation.yaml'));

    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('spec:doctor', ['--json' => true], $output);
    $decoded = json_decode($output->fetch(), true);

    $rejected = array_values(array_filter(
        $decoded['findings'],
        static fn (array $finding): bool => $finding['level'] === 'rejected',
    ));

    expect($exit)->toBe(2)
        ->and($decoded)->toBeArray()
        ->and($decoded['summary']['exitCode'])->toBe(2)
        // Also carries a Deferred finding (the fixture's own `responses`
        // block) — present in the report but excluded from the exit code,
        // which is exactly what this asserts by filtering it out here.
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]['class'])->toBe('package_limit')
        ->and($rejected[0]['message'])->toContain('Laravel has no TRACE verb');
});

it('emits a parseable JSON error, not prose, when --json is combined with a refusal', function (): void {
    // The one thing that still reaches the command's own outer refusal
    // rather than becoming a Finding: `spec.path` itself is unusable, so
    // there is nothing to even attempt reading a contract from.
    config()->set('lara-spec-first.spec.path', '');

    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('spec:doctor', ['--json' => true], $output);
    $decoded = json_decode($output->fetch(), true);

    expect($exit)->toBe(1)
        ->and($decoded)->toBeArray()
        ->and($decoded['error'])->toBeString();
});
