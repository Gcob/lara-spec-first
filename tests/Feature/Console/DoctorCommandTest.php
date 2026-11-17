<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A generated tree of its own, never written to and never created on disk — Routing outcome and Drift both read
 * whatever `generated.path` names, and pointing at the real configured tree (workbench's, in this repository's own test
 * run) would make these tests report drift against content this file has no control over. Not creating it at all is
 * deliberate too: every test here is then a fresh clone as far as Drift is concerned, which is its own legitimate case
 * — see DriftCheck — so nothing needs writing or cleaning up either.
 *
 * **Namespaced under `App\`, mapped to `app/` by Testbench's own skeleton `composer.json`.** Installation checks
 * `generated.namespace` against the *real* `autoload.psr-4` map, and `base_path()` under a booted Testbench application
 * is that skeleton's root — the same `App\` -> `app/` a real consumer application maps, and the same pair
 * `generated.namespace` defaults to in config/lara-spec-first.php, so this is also the realistic case rather than an
 * arbitrary one picked to dodge the check.
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

// --- Request schemas: a keyword the contract wrote and the request does not enforce ---

const DOCTOR_BODY_AT = '#/paths/~1orders/post/requestBody/content/application~1json/schema';

/**
 * The findings `spec:doctor --json` reports for a fixture, with the exit code.
 *
 * @return array{0: int, 1: array<string, mixed>}
 */
function doctorJson(string $fixture): array
{
    config()->set('lara-spec-first.spec.path', specFixturePath($fixture));

    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('spec:doctor', ['--json' => true], $output);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    return [$exit, $decoded];
}

/**
 * @param  array<string, mixed>  $report
 * @return list<array<string, string>>
 */
function doctorSupportFindings(array $report, string $level): array
{
    return array_values(array_filter(
        $report['findings'],
        static fn (array $finding): bool => $finding['section'] === 'Support findings' && $finding['level'] === $level,
    ));
}

// Acceptance 1: an ignored keyword is named, with the operation and where it is
// written, and the exit code is the one reserved for package limits.
it('names an ignored keyword, its operation and its position, and exits 2', function (string $fixture): void {
    config()->set('lara-spec-first.spec.path', specFixturePath($fixture));

    [$exit, $text] = doctor();

    expect($exit)->toBe(2)
        ->and($text)->toContain('[package limit: ignored] post /orders writes `default` at '.DOCTOR_BODY_AT.'/properties/status/default')
        ->and($text)->not->toContain('[document fault]');
})->with(['doctor-ignored-keywords-3.0.yaml', 'doctor-ignored-keywords-3.1.yaml']);

// One finding per keyword and per operation: `default` is written three times in
// the 3.0 fixture, and is one line that lists the three, the first one carried as
// the pointer.
it('reports a keyword written at several positions once, listing every one', function (): void {
    [$exit, $report] = doctorJson('doctor-ignored-keywords-3.0.yaml');

    $ignored = doctorSupportFindings($report, 'ignored');
    $byKeyword = [];

    foreach ($ignored as $finding) {
        // Not inside assert(): CI runs with assertions off, which would skip the match itself. A message without
        // a keyword lands under its own text, so the key list below fails and shows it.
        $keyword = preg_match('/writes `([^`]+)`/', $finding['message'], $match) === 1 ? $match[1] : $finding['message'];
        $byKeyword[$keyword] = $finding;
    }

    expect($exit)->toBe(2)
        ->and(array_keys($byKeyword))->toBe(['default', 'deprecated', 'oneOf'])
        ->and($byKeyword['default']['pointer'])->toBe(DOCTOR_BODY_AT.'/properties/status/default')
        ->and($byKeyword['default']['message'])->toContain(DOCTOR_BODY_AT.'/properties/status/default, '
            .DOCTOR_BODY_AT.'/properties/quantity/default, #/paths/~1orders/post/parameters/0/schema/default')
        ->and($byKeyword['deprecated']['pointer'])->toBe(DOCTOR_BODY_AT.'/properties/status/deprecated')
        ->and($byKeyword['oneOf']['pointer'])->toBe(DOCTOR_BODY_AT.'/properties/payment/oneOf');
});

// Acceptance 3: `--json` carries every one of them, as a package limit.
it('carries every request schema finding in the JSON output', function (): void {
    [$exit, $report] = doctorJson('doctor-ignored-keywords-3.1.yaml');

    $ignored = doctorSupportFindings($report, 'ignored');

    expect($exit)->toBe(2)
        ->and($report['summary']['exitCode'])->toBe(2)
        ->and(array_column($ignored, 'class'))->toBe(['package_limit', 'package_limit', 'package_limit'])
        ->and(array_column($ignored, 'pointer'))->toBe([
            DOCTOR_BODY_AT.'/patternProperties',
            DOCTOR_BODY_AT.'/properties/pair/prefixItems',
            DOCTOR_BODY_AT.'/properties/status/default',
        ]);
});

// The unenforced part of a Partial keyword is reported the same way, from the
// rule set rather than from the schema, and it gates as a package limit too.
it('names what a Partial keyword leaves unenforced, and exits 2', function (): void {
    [$exit, $report] = doctorJson('doctor-unenforced-keyword.yaml');

    $partial = doctorSupportFindings($report, 'partial');

    expect($exit)->toBe(2)
        ->and($partial)->toHaveCount(1)
        ->and($partial[0]['class'])->toBe('package_limit')
        ->and($partial[0]['pointer'])->toBe('#/paths/~1orders/post')
        ->and($partial[0]['message'])->toContain('post /orders writes `format`')
        ->and($partial[0]['message'])->toContain('`callback`')
        ->and(doctorSupportFindings($report, 'ignored'))->toBe([]);
});

// A name PHP rewrites has a rule under the key PHP delivers, so nothing it states is left unenforced and the doctor
// exits 0. A name PHP appends to has no key a rule can hold: it keeps its `required` and its bound in the report.
it('exits 0 for a query name PHP rewrites, and 2 for one PHP appends to', function (): void {
    [$rewrittenExit, $rewritten] = doctorJson('doctor-query-bracketed.yaml');
    [$appendedExit, $appended] = doctorJson('doctor-query-appended.yaml');

    $partial = doctorSupportFindings($appended, 'partial');

    // One finding per keyword.
    expect($rewrittenExit)->toBe(0)
        ->and(doctorSupportFindings($rewritten, 'partial'))->toBe([])
        ->and($appendedExit)->toBe(2)
        ->and($partial)->toHaveCount(2)
        ->and($partial[0]['class'])->toBe('package_limit')
        ->and($partial[0]['message'])->toContain('writes `required`')
        ->and($partial[0]['message'])->toContain('`ids[]`')
        ->and($partial[1]['message'])->toContain('writes `minimum`')
        ->and($partial[1]['message'])->toContain('`ids[]`');
});

// A plain name beside the name PHP nests under it keeps its rules, so the doctor exits 0. Two names under one key have
// no rule, so what each states is left unenforced and the doctor exits 2: a contract that exited 0 before can turn a
// green CI red.
it('exits 0 for a plain query name beside the names PHP nests under it, and 2 for two names under one key', function (): void {
    [$nestedExit, $nested] = doctorJson('doctor-query-overlapping.yaml');
    [$sameExit, $same] = doctorJson('doctor-query-same-key.yaml');

    $partial = doctorSupportFindings($same, 'partial');

    expect($nestedExit)->toBe(0)
        ->and(doctorSupportFindings($nested, 'partial'))->toBe([])
        ->and($sameExit)->toBe(2)
        ->and($partial)->toHaveCount(2)
        ->and($partial[0]['message'])->toContain('writes `required`')
        ->and($partial[0]['message'])->toContain('`user_name`')
        ->and($partial[1]['message'])->toContain('writes `minimum`');
});

// `encoding` narrows a file part, so the doctor owes nothing for it. A `contentType` on a part the request does not
// read as a file is left out of the rules, and that is a package limit it names: a keyword the matrix rates as honored
// in general. Its own section is "Parameters, bodies, responses", not "Schemas". The type OpenAPI gives a part anyway
// (`application/json` on an object, `text/plain` on a primitive, `application/octet-stream` on a file) is no news.
it('reports encoding on a part that is not a file', function (): void {
    [$exit, $report] = doctorJson('doctor-encoding.yaml');

    $partial = doctorSupportFindings($report, 'partial');

    expect($exit)->toBe(2)
        ->and($partial)->toHaveCount(1)
        ->and($partial[0]['class'])->toBe('package_limit')
        ->and($partial[0]['pointer'])->toBe('#/paths/~1profiles/post')
        ->and($partial[0]['message'])->toContain('post /profiles writes `encoding`')
        ->and($partial[0]['message'])->toContain('`name`')
        ->and($partial[0]['message'])->toContain('"Parameters, bodies, responses"')
        ->and($partial[0]['message'])->not->toContain('"Schemas"');
});

// A malformed `encoding` is a fault in the document, and the doctor's zero exit promises nothing was dropped without
// a decision: the request is looser than the contract (a YAML list narrows nothing, an entry for a part the schema
// does not declare is never read), so the exit is the document's, `1`, with the operation as the position.
it('reports a malformed encoding as a document fault', function (): void {
    [$exit, $report] = doctorJson('doctor-encoding-faults.yaml');

    $faults = array_values(array_filter(
        $report['findings'],
        static fn (array $finding): bool => $finding['class'] === 'document_fault',
    ));

    expect($exit)->toBe(1)
        ->and($faults)->toHaveCount(2)
        ->and(array_column($faults, 'section'))->toBe(['Support findings', 'Support findings'])
        ->and(array_column($faults, 'pointer'))->toBe(['#/paths/~1lists/post', '#/paths/~1nested/post'])
        ->and($faults[0]['message'])->toContain('post /lists')
        ->and($faults[0]['message'])->toContain('`contentType` for `avatar` that is not a string')
        ->and($faults[1]['message'])->toContain('post /nested')
        ->and($faults[1]['message'])->toContain('`meta.doc`, which the body does not declare');
});

// Acceptance 2: a keyword a response writes is not named, and the responses are
// still the one Deferred count, which does not gate.
it('names no keyword a response schema writes, and keeps responses as one deferred count', function (): void {
    [$exit, $report] = doctorJson('doctor-ignored-keyword-in-response.yaml');

    $support = array_values(array_filter(
        $report['findings'],
        static fn (array $finding): bool => $finding['section'] === 'Support findings',
    ));

    expect($exit)->toBe(0)
        ->and($support)->toHaveCount(1)
        ->and($support[0]['level'])->toBe('deferred')
        ->and($support[0]['message'])->toContain('1 response declaration(s) found');
});

// The rule set cannot be built for an operation whose body and query name one
// field, and Routing outcome reports that. Support findings must not die on it,
// nor lose what was read before a rule set existed.
it('still names an ignored keyword on an operation the build plan refuses', function (): void {
    [, $report] = doctorJson('doctor-ignored-keyword-with-conflicting-input.yaml');

    $ignored = doctorSupportFindings($report, 'ignored');

    expect($ignored)->toHaveCount(1)
        ->and($ignored[0]['pointer'])->toBe(DOCTOR_BODY_AT.'/properties/page/default')
        ->and(implode("\n", array_column($report['findings'], 'message')))->toContain('page');
});

// --- A document the pipeline could not read is not drift ---

/**
 * A generated tree that exists and holds files, unlike {@see doctorTree()} — Drift's own preconditions are what these
 * tests are about, so "a build already happened" has to be a real state on disk rather than an assumption.
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
        // Security and Lifecycle read the same partial operation list Routing
        // outcome does, so they carry the same kind of note: they ran, on less
        // than their names promise.
        ->and($decoded['notes']['Security']['checked'])->toBeTrue()
        ->and($decoded['notes']['Lifecycle']['checked'])->toBeTrue();
});

// And nothing at all on a clean run, which is what says the two sections came
// off the standing "not built yet" list rather than trading one silence for
// another: only a run that could not give them the whole document makes them
// speak up.
it('leaves Security and Lifecycle without a note at all on a clean contract', function (): void {
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('spec:doctor', ['--json' => true], $output);
    $decoded = json_decode($output->fetch(), true);

    expect($exit)->toBe(0)
        ->and($decoded['notes'])->not->toHaveKey('Security')
        ->and($decoded['notes'])->not->toHaveKey('Lifecycle');
});

// The regression this pair guards: both sections read `ReadOutcome::$operations`
// and nothing else, so with no document they found nothing and printed `clean` —
// a section asserting it ran and found nothing, on a file that was never opened.
// It matters most for Security, which doctor.md calls the one finding that can
// turn a documented-as-protected endpoint into a public one.
it('never claims Security or Lifecycle are clean on a run with no document', function (): void {
    config()->set('lara-spec-first.spec.path', '/does/not/exist.yaml');

    [$exit, $text] = doctor();

    expect($exit)->toBe(1)
        ->and($text)->not->toContain("Security\n  clean")
        ->and($text)->not->toContain("Lifecycle\n  clean")
        ->and($text)->toContain('there was no document to read operations from')
        ->and($text)->toContain('No operation was examined');
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
