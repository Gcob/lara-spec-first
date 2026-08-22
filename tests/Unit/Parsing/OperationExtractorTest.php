<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\ExtractionResult;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

/**
 * The read is assumed clean here: every fixture this helper is used against
 * has a readable document, so unwrapping straight to the extractor's own
 * result is what keeps every existing assertion below reading exactly as it
 * did before this class stopped throwing.
 */
function extractionFrom(string $fixture): ExtractionResult
{
    $document = (new SpecDocumentReader)->read(specFixturePath($fixture))->document;

    assert($document !== null);

    return (new OperationExtractor)->extract($document);
}

/**
 * @return list<Operation>
 */
function extractFrom(string $fixture): array
{
    return extractionFrom($fixture)->operations;
}

/**
 * @return list<array<string, list<string>>>
 */
function schemesOf(Operation $operation): array
{
    return array_map(
        static fn (SecurityRequirement $requirement): array => $requirement->schemes,
        $operation->security ?? []
    );
}

it('extracts every operation of every path', function (): void {
    expect(extractFrom('operations.yaml'))->toHaveCount(4);
});

// The document's order settles which route wins when two match, so it is part
// of the contract rather than a property of how a file happens to be written.
it('numbers the operations in document order', function (): void {
    $identities = array_map(
        static fn ($operation): string => $operation->identity(),
        extractFrom('operations.yaml')
    );

    expect($identities)->toBe([
        'get /users/me',
        'get /users/{}',
        'delete /users/{}',
        'post /posts',
    ]);
});

it('indexes them from zero without a gap', function (): void {
    $indexes = array_map(static fn ($operation): int => $operation->index, extractFrom('operations.yaml'));

    expect($indexes)->toBe([0, 1, 2, 3]);
});

it('carries the operationId as written', function (): void {
    expect(extractFrom('operations.yaml')[0]->operationId)->toBe('showCurrentUser');
});

// Deriving a name is the generator's job. A contract that invented one would
// bake a single generator's convention into what every generator reads.
it('leaves an absent operationId absent', function (): void {
    $delete = extractFrom('operations.yaml')[2];

    expect($delete->method)->toBe(HttpMethod::Delete)
        ->and($delete->operationId)->toBeNull();
});

// The first behaviour in this package that genuinely needs the OpenAPI parser:
// a Path Item written as a reference has to be resolved before its operations
// can be seen at all.
it('resolves a Path Item that refers to another path', function (): void {
    $operations = extractFrom('path-item-ref-path.yaml');

    expect($operations)->toHaveCount(2)
        ->and($operations[1]->path->template)->toBe('/healthz')
        ->and($operations[1]->operationId)->toBe('health');
});

it('resolves a Path Item that lives in another file', function (): void {
    $operations = extractFrom('path-item-ref-file/spec.yaml');

    expect($operations)->toHaveCount(1)
        ->and($operations[0]->operationId)->toBe('healthFromAnotherFile');
});

// Verified against the vendored parser: it does not model `components.pathItems`,
// so it resolves the reference to a plain value, keeps no operations and reports
// nothing. Silently losing an endpoint is the one outcome this package must never
// produce, so the reference is refused with the reason and the two forms that do
// work.
it('refuses a Path Item reference the parser would drop in silence', function (): void {
    $result = extractionFrom('path-item-ref-component.yaml');

    expect($result->operations)->toBe([])
        ->and($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RejectedConstructException::class)
        ->and($result->faults[0]->getMessage())->toContain('does not model `components.pathItems`');
});

it('refuses a trace operation, and says whose limitation it is', function (): void {
    $result = extractionFrom('trace-operation.yaml');

    expect($result->operations)->toBe([])
        ->and($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RejectedConstructException::class)
        ->and($result->faults[0]->getMessage())->toContain('Laravel has no TRACE verb');
});

// Two paths differing only in a parameter name address one endpoint, which no
// router can tell apart. Surfacing it is the payoff of normalizing identity.
// The first of the two still extracts — only the second, which finds the
// identity already claimed, is skipped.
it('refuses the second of two operations that address one endpoint, keeping the first', function (): void {
    $result = extractionFrom('duplicate-endpoint.yaml');

    expect($result->operations)->toHaveCount(1)
        ->and($result->operations[0]->operationId)->toBe('showById')
        ->and($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($result->faults[0]->getMessage())->toContain('both resolve to `get /users/{}`');
});

it('extracts nothing from a document with no paths', function (): void {
    expect(extractFrom('openapi-3.1-webhooks-only.yaml'))->toBe([]);
});

it('resolves an operation that states nothing to public and beta', function (): void {
    $operation = extractFrom('lifecycle.yaml')[0];

    expect($operation->audience)->toBe(Audience::Public)
        ->and($operation->lifecycle)->toBe(Lifecycle::Beta)
        ->and($operation->deprecated)->toBeFalse()
        ->and($operation->sunset)->toBeNull();
});

it('lets an internal operation claim no lifecycle at all', function (): void {
    $operation = extractFrom('lifecycle.yaml')[1];

    expect($operation->audience)->toBe(Audience::Internal)
        ->and($operation->lifecycle)->toBeNull();
});

it('carries a fully promised operation as written', function (): void {
    $operation = extractFrom('lifecycle.yaml')[2];

    expect($operation->audience)->toBe(Audience::Public)
        ->and($operation->lifecycle)->toBe(Lifecycle::Stable)
        ->and($operation->deprecated)->toBeTrue()
        ->and($operation->sunset)->toBe('2026-06-01')
        ->and($operation->tags)->toBe(['billing', 'legacy']);
});

// These arrive as an int, as a date and as an instant: YAML decodes an unquoted
// date to a Unix timestamp, and quoting it changes nothing about the promise.
it('writes one spelling for every spelling of one moment', function (): void {
    $operations = extractFrom('lifecycle.yaml');

    expect($operations[2]->sunset)->toBe('2026-06-01')
        ->and($operations[3]->sunset)->toBe('2026-06-01')
        ->and($operations[4]->sunset)->toBe('2026-06-01');
});

// PHP would read `next tuesday` against the day the build happens to run, which
// is an artifact that changes without the contract changing. Judging the value
// is the doctor's, so it is recorded as written rather than refused or resolved.
it('records a sunset it cannot read as a moment, without resolving it', function (): void {
    expect(extractFrom('lifecycle.yaml')[5]->sunset)->toBe('next tuesday');
});

it('refuses a value for an extension it defines but does not recognize', function (): void {
    $result = extractionFrom('unknown-lifecycle.yaml');

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($result->faults[0]->getMessage())->toContain('`x-lifecycle` on `get /typo` is "stabel"');
});

it('tells an inherited security requirement from an explicit opt-out', function (): void {
    $operations = extractFrom('security-states.yaml');

    expect($operations[0]->security)->toBeNull()
        ->and($operations[1]->security)->toBe([]);
});

it('sorts the schemes inside one requirement, which are ANDed and unordered', function (): void {
    expect(schemesOf(extractFrom('security-states.yaml')[2]))
        ->toBe([['apiKey' => [], 'bearerAuth' => []]]);
});

it('keeps the scopes a requirement asks for', function (): void {
    expect(schemesOf(extractFrom('security-states.yaml')[3]))
        ->toBe([['oauth2' => ['read', 'write']]]);
});

/*
 * `x-controller`, which is the one extension whose value is a PHP class name
 * rather than a word this package defines. That makes its checks different in
 * kind: there is no allowed list to compare against, only whether PHP could carry
 * the name at all.
 *
 * @see docs/guide/controllers.md — "The specification decides what is customizable"
 */

it('carries the custom controller the contract names', function (): void {
    $operations = extractFrom('custom-controllers.yaml');

    expect($operations[0]->controller)
        ->toBe('Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\WrittenController');
});

// `\App\…` and `App\…` name one class, and carrying both spellings forward would
// mean two values that collide without looking alike.
it('reads a leading separator as the absolute name PHP writes', function (): void {
    $operations = extractFrom('custom-controllers.yaml');

    expect($operations[1]->controller)
        ->toBe('Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\NotWrittenYetController');
});

it('leaves an operation that declares no custom controller without one', function (): void {
    $operations = extractFrom('custom-controllers.yaml');

    expect($operations[2]->controller)->toBeNull();
});

// Refused where the document is read rather than where the class would be
// generated: the value is the name itself, so nothing this package could do to it
// later would make it into an identifier.
it('refuses an x-controller PHP could never carry', function (string $fixture): void {
    $result = extractionFrom($fixture);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($result->faults[0]->getMessage())->toContain('not a class name PHP could carry');
})->with([
    'a hyphen' => 'x-controller-hyphen.yaml',
    'a trailing separator' => 'x-controller-trailing.yaml',
    'a leading digit' => 'x-controller-digit.yaml',
]);

it('refuses an x-controller that is not text at all', function (): void {
    $result = extractionFrom('x-controller-not-a-string.yaml');

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($result->faults[0]->getMessage())->toContain('reads it as text');
});

// --- Collecting more than one fault in a single pass, the reason this class
// stopped throwing at the first one. ---

it('skips only the faulty operation and keeps extracting the rest of the document', function (): void {
    $result = extractionFrom('multiple-faults.yaml');

    $identities = array_map(
        static fn (Operation $operation): string => $operation->identity(),
        $result->operations,
    );

    expect($identities)->toBe(['get /users/me', 'get /users/{}'])
        ->and($result->faults)->toHaveCount(3);

    $faultTypes = array_map(get_class(...), $result->faults);

    expect($faultTypes)->toBe([
        RejectedConstructException::class, // the trace operation
        InvalidDocumentException::class,   // the unrecognized x-lifecycle value
        InvalidDocumentException::class,   // the duplicate endpoint
    ]);
});

// `index` settles which route wins when two match, so it has to describe the
// order operations are actually registered in — contiguous among the survivors
// — rather than the raw position they were attempted at in the document, which
// three skipped operations would otherwise leave full of gaps.
it('numbers surviving operations contiguously despite the ones skipped between them', function (): void {
    $indexes = array_map(
        static fn (Operation $operation): int => $operation->index,
        extractionFrom('multiple-faults.yaml')->operations,
    );

    expect($indexes)->toBe([0, 1]);
});
