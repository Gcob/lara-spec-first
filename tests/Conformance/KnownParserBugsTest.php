<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

// The permanent record the conformance suite exists to keep: every parser
// defect this package has ever found, one dataset row each, and the row never
// leaves once it is added.
//
// Both entries below were found within days of first use, on a surface no
// wider than paths and references, and neither has a symptom on its own — a
// pure `$ref` cycle exhausts memory instead of raising, and a reference into
// `components.pathItems` loses an endpoint without reporting anything. An
// interface in front of the parser would not have caught either one: what had
// gone wrong both times was the dependency being wrong, not a dependency worth
// swapping. This file is the behavioural contract instead — the acceptance
// criteria a replacement parser would have to meet — and it is deliberately
// the one file in this suite that is expected to only ever grow.
//
// @see docs/guide/openapi-support.md#parser-caveats — the same two rows, in prose
// @see docs/project/roadmap.md — "A conformance suite over the reading engine"

it('guards against a known parser defect', function (string $fixture, string $exception, string $message): void {
    $extract = fn (): array => (new OperationExtractor)->extract(
        (new SpecDocumentReader)->read(specFixturePath($fixture))
    );

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
