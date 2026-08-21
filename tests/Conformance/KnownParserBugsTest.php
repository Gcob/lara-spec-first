<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

// The permanent record the conformance suite exists to keep: every parser
// defect this package has ever found, one dataset row each, and the row never
// leaves once it is added.
//
// Three are recorded so far, and they fall into two groups. Two are caught
// before they can hurt anything — a pure `$ref` cycle and a reference into
// `components.pathItems` are both refused by our own guards, in process,
// with a clear exception. The third is not: a `$ref` whose JSON pointer lands
// inside data our own cycle guard correctly treats as opaque reaches the same
// unrecoverable memory exhaustion as the first, on a shape the guard cannot
// see by its own design. There is no exception to catch for it, only a
// process that dies — which is why it gets its own dataset below, run in a
// child process, rather than a row in the first.
//
// All three were found within days of first use, on a surface no wider than
// paths and references, and none has a symptom on its own. An interface in
// front of the parser would not have caught any of them: what had gone wrong
// every time was the dependency being wrong, not a dependency worth swapping.
// This file is the behavioural contract instead — the acceptance criteria a
// replacement parser would have to meet — and it is deliberately the one file
// in this suite that is expected to only ever grow.
//
// @see docs/guide/openapi-support.md#parser-caveats — the same three rows, in prose
// @see docs/project/roadmap.md — "A conformance suite over the reading engine"

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
]);

// Not guarded against, and not catchable: each fixture below kills the PHP
// process before any of our own code gets a chance to report on it, the same
// way `cycle-pointer.yaml` would without the guard in front of it. Run in a
// child process for exactly that reason — see tests/Support/extract.php and
// the "Subprocess assertions" row in docs/project/stack.md.
it('records a parser defect that cannot be caught in-process', function (string $fixture): void {
    $process = new Process([
        PHP_BINARY,
        '-d', 'memory_limit=64M',
        __DIR__.'/../Support/extract.php',
        specFixturePath($fixture),
    ]);

    try {
        $process->run();

        // The ordinary shape of this failure: PHP's own memory accounting
        // catches it, prints the fatal to stderr, and exits normally with a
        // non-zero status.
        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('Allowed memory size');
    } catch (ProcessSignaledException) {
        // The same failure, reached a different way: some environments —
        // this suite's own Docker container among them — OOM-kill the child
        // before PHP's memory accounting gets to report the fatal cleanly, so
        // the process dies by signal instead of by a caught limit. Either way
        // it never returns, which is the property being pinned; Symfony
        // Process surfaces a signaled process as this exception rather than
        // an ordinary exit code, so the assertion has to follow it there.
        expect($process->hasBeenSignaled())->toBeTrue();
    }
})->with([
    'a $ref pointing into an example value, which the guard correctly treats as opaque' => ['ref-inside-example.yaml'],
    'a $ref pointing into an Example Object\'s value, for the same reason' => ['example-object-value.yaml'],
]);
