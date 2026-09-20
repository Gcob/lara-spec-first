<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Symfony\Component\Process\Process;

// The permanent record the conformance suite exists to keep: every parser
// defect this package has ever found, one dataset row each, and the row never
// leaves once it is added.
//
// Four are recorded so far, and all four are now caught before they can hurt
// anything: a pure `$ref` cycle, a reference into `components.pathItems`, a
// `$ref` whose JSON pointer lands inside data — an `example`, an Example
// Object's `value` — which reached the same unrecoverable memory exhaustion as
// the first until the cycle guard learned to follow a reference into the
// position it actually points at, and a reference into a `$defs`.
//
// The fourth is the quietest of the four and was found the day schemas started
// being read. `$defs` is not modelled either, so a reference aimed inside one
// resolves to a plain array the parser cannot build a schema from: it drops the
// property that carried the reference and records nothing. A contract declaring
// three fields comes back with two, the build succeeds, and the generated code
// is missing a field nobody asked it to drop. Nothing fails, which is what puts
// it in this file rather than in a bug report.
//
// The third one keeps a second dataset of its own even so, and it is the reason
// this file still spawns a child process. What it pins is not the fault, which
// the first dataset already asserts, but the *manner of failure* it used to
// have: this document once took the whole PHP process down, and a run that
// merely reports a fault cannot tell you the process would still be standing.
// Only a child process can, so the case that once asserted a death now asserts
// a survival, and the row never leaves.
//
// All of them were found within days of first reaching the surface they sit on,
// and none has a symptom on its own. An interface in
// front of the parser would not have caught any of them: what had gone wrong
// every time was the dependency being wrong, not a dependency worth swapping.
// This file is the behavioural contract instead — the acceptance criteria a
// replacement parser would have to meet — and it is deliberately the one file
// in this suite that is expected to only ever grow.
//
// @see docs/guide/openapi-support.md#parser-caveats — the same three rows, in prose
// @see AGENTS.md — "Automated tests are required"

it('guards against a known parser defect', function (string $fixture, string $exception, string $message): void {
    $extract = fn (): array => extractFixture($fixture);

    expect($extract)->toThrow($exception, $message);
})->with([
    'a pure $ref cycle exhausts the parser\'s memory instead of raising, under RESOLVE_MODE_ALL' => [
        'cycle-pointer.yaml',
        CyclicReferenceException::class,
        'closes a cycle',
    ],
    'components.pathItems is not modelled, so a reference into it loses the endpoint in silence' => [
        'path-item-ref-component.yaml',
        RejectedConstructException::class,
        'does not model `components.pathItems`',
    ],
    'a $ref pointing into an example value, which the guard reads as data until something points at it' => [
        'ref-inside-example.yaml',
        CyclicReferenceException::class,
        'closes a cycle',
    ],
    'a $ref pointing into an Example Object\'s value, for the same reason' => [
        'example-object-value.yaml',
        CyclicReferenceException::class,
        'closes a cycle',
    ],
    '$defs is not modelled, so a reference into it drops the property holding it in silence' => [
        'ref-into-defs.yaml',
        RejectedConstructException::class,
        'inside a `$defs`',
    ],
]);

// The two documents above, run in a process of their own, because the fault
// they now raise is only half of what changed. Each of these once exhausted
// memory inside `OperationExtractor::parse()` and killed the interpreter
// outright, and no in-process assertion can distinguish "reported a fault" from
// "would have died a moment later" — the run making the assertion is the run
// that would have to survive it. A child process can, which is the only reason
// this stays here rather than folding into the dataset above.
//
// @see tests/Support/extract.php and the "Subprocess assertions" row in
//      docs/project/stack.md
it('no longer takes the process down on a defect that once did', function (string $fixture): void {
    $process = new Process([
        PHP_BINARY,
        '-d', 'memory_limit=64M',
        __DIR__.'/../Support/extract.php',
        specFixturePath($fixture),
    ]);

    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('ok')
        ->and($process->getErrorOutput())->not->toContain('Allowed memory size');
})->with([
    'a $ref pointing into an example value, which once died in Reference::resolve()' => ['ref-inside-example.yaml'],
    'a $ref pointing into an Example Object\'s value, which died the same way' => ['example-object-value.yaml'],
]);
