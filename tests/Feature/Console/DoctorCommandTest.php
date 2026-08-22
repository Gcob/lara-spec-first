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

// --- A document the pipeline could not read is not drift ---

/**
 * A generated tree that exists and holds files, unlike {@see doctorTree()} —
 * Drift's own preconditions are what these tests are about, so "a build already
 * happened" has to be a real state on disk rather than an assumption.
 */
function builtDoctorTree(): string
{
    static $tree = null;

    return $tree ??= base_path('app/LsfDoctorBuilt'.bin2hex(random_bytes(6)));
}

function writeBuiltDoctorTree(): void
{
    @mkdir(builtDoctorTree().'/Controllers', 0o777, true);
    file_put_contents(builtDoctorTree().'/routes.php', "<?php\n");
    file_put_contents(builtDoctorTree().'/Controllers/StaleController.php', "<?php\n");

    // Both, and matching each other: Installation compares the two, so a tree
    // moved without its namespace would add an Installation finding these
    // tests would then have to count around.
    config()->set(GeneratedRoutesLocator::SETTING, builtDoctorTree());
    config()->set('lara-spec-first.generated.namespace', 'App\\'.basename(builtDoctorTree()));
}

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(builtDoctorTree()));
});

// One real fault used to become one real fault plus one false line per
// generated file — each of them stating that a build would prune the file,
// which is not true: `spec:build` refuses the same document before writing
// anything. And the line that mattered scrolled off the top.
it('does not report the generated tree as stale when the document could not be read', function (): void {
    writeBuiltDoctorTree();
    config()->set('lara-spec-first.spec.path', specFixturePath('cycle-pointer.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        ->and($text)->toContain('closes a cycle')
        ->and($text)->not->toContain('would prune it')
        ->and($text)->not->toContain('has drifted')
        // One fault in, one fault out.
        ->and(substr_count($text, '[document fault'))->toBe(1);
});

// Silence would be the same defect in the other direction: a section that
// prints `clean` because it never ran.
it('says drift was not checked rather than printing clean, when the document could not be read', function (): void {
    writeBuiltDoctorTree();
    config()->set('lara-spec-first.spec.path', specFixturePath('cycle-pointer.yaml'));

    [, $text] = doctor();

    expect($text)->toContain('[not checked]')
        ->and($text)->toContain('would refuse it before writing anything')
        ->and($text)->toContain('Not covered by this run:')
        ->and($text)->toContain('Drift');
});

// The routing table still prints — it is the outcome, and the operations it
// lists are real — but it says what narrows it, since a partial table beside
// the faults that narrowed it is the one way this report can mislead.
it('says the routing table is partial when the document carries a fault', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('multiple-faults.yaml'));

    [, $text] = doctor();

    expect($text)->toContain('[note] only the operations that could be extracted are listed');
});

it('still checks drift on a clean document', function (): void {
    writeBuiltDoctorTree();

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        ->and($text)->toContain('has drifted')
        ->and($text)->not->toContain('[not checked] the document could not be read');
});

// --- A raw document whose nodes have the wrong type ---

// The one input class this command exists for. It used to die here with an
// `ErrorException` and a stack trace, in a section reading the raw array after
// the sections that had already reported everything they could.
it('reports a document with wrong-typed nodes instead of dying on it', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('wrong-typed-nodes.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBeIn([0, 1, 2])
        ->and($text)->toContain('Support findings')
        ->and($text)->not->toContain('ErrorException')
        ->and($text)->not->toContain('foreach()');
});

// --- What a green exit covers, said on every run ---

// Security and Lifecycle came off the unchecked list in the change that built
// them, which is the only way an entry there is meant to be removed. Both still
// print — every section does — but the summary no longer names them, because a
// run that checked them is not a run that skipped them.
it('names the sections it does not check, even on a clean contract', function (): void {
    [$exit, $text] = doctor();

    expect($exit)->toBe(0)
        ->and($text)->toContain('Security')
        ->and($text)->toContain('Lifecycle')
        ->and($text)->toContain('Baseline')
        ->and($text)->toContain('Drivers')
        ->and($text)->toContain('Not covered by this run: Baseline, Drivers');
});

it('carries the same answer in --json, with checked telling the two kinds apart', function (): void {
    writeBuiltDoctorTree();
    config()->set('lara-spec-first.spec.path', specFixturePath('multiple-faults.yaml'));

    $output = new BufferedOutput;
    app(Kernel::class)->call('spec:doctor', ['--json' => true], $output);
    $decoded = json_decode($output->fetch(), true);

    expect($decoded['notes'])->toBeArray()
        ->and($decoded['notes']['Baseline']['checked'])->toBeFalse()
        ->and($decoded['notes']['Drift']['checked'])->toBeFalse()
        ->and($decoded['notes']['Routing outcome']['checked'])->toBeTrue()
        ->and($decoded['notes']['Drift']['note'])->toBeString()
        // Security and Lifecycle are checked now, so neither carries a note at
        // all — the assertion that would have caught this section being built
        // without its note being retired.
        ->and($decoded['notes'])->not->toHaveKey('Security')
        ->and($decoded['notes'])->not->toHaveKey('Lifecycle');
});

// The `Partial` level used to survive only in `--json`, so the one document
// fault that carries a level printed without the level that motivates it.
it('prints the support level on a document fault that has one', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('promised-without-an-id.yaml'));

    [, $text] = doctor();

    expect($text)->toContain('[document fault: partial]')
        ->and($text)->toContain('declares no operationId');
});

// --- Lifecycle and Security, end to end ---

it('prints the protection report on a contract that promises nothing, without failing over it', function (): void {
    [$exit, $text] = doctor();

    expect($exit)->toBe(0)
        ->and($text)->toContain('Lifecycle')
        ->and($text)->toContain('public operation(s) are stable.')
        ->and($text)->toContain('beta: get /users/me');
});

// The fixture states 2026-06-01, a date that is now behind us and stays
// behind us — a permanent "already passed" case rather than a test that
// expires. It also carries an unreadable date, so both gating rules run.
it('exits 1 on a removal date that has passed and on one nothing can read', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('lifecycle.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        ->and($text)->toContain('[document fault]')
        ->and($text)->toContain('has passed')
        ->and($text)->toContain('next tuesday');
});

it('exits 2 and lists every operation whose declared security is not applied yet', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('secured-operations.yaml'));

    [$exit, $text] = doctor();

    expect($exit)->toBe(2)
        ->and($text)->toContain('[package limit: partial]')
        ->and($text)->toContain('get /orders')
        ->and($text)->toContain('post /orders')
        ->and($text)->toContain('root security block')
        // The operation that explicitly requires nothing is already served
        // exactly as its contract states, so it is not one of the two.
        ->and(substr_count($text, 'no authorization check behind it'))->toBe(2);
});

// A document nothing could be read from has no lifecycle outcome at all,
// rather than one full of zeroes: "0 of 0 public operations are stable", read
// off a file that could not be opened, is a statement about nothing, and a
// consumer of --json would have to know to disbelieve it.
it('reports no lifecycle outcome at all when there was no document to read one from', function (): void {
    config()->set('lara-spec-first.spec.path', '/does/not/exist.yaml');

    $output = new BufferedOutput;
    app(Kernel::class)->call('spec:doctor', ['--json' => true], $output);
    $decoded = json_decode($output->fetch(), true);

    expect($decoded['lifecycle'])->toBeNull();
});
