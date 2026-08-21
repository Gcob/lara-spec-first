<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Doctor\Checks\SupportMatrixCheck;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Parsing\ParsableSpecDocument;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi30Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;

// --- Lot 1: Rejected, already detected by the reading pipeline ---

it('surfaces a trace operation as a Rejected support finding', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('trace-operation.yaml'));
    $findings = SupportMatrixCheck::rejected($outcome);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->section)->toBe('Support findings')
        ->and($findings[0]->level)->toBe(SupportLevel::Rejected)
        ->and($findings[0]->message)->toContain('Laravel has no TRACE verb');
});

it('is silent when nothing was rejected', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('operations.yaml'));

    expect(SupportMatrixCheck::rejected($outcome))->toBe([]);
});

// Cycle and remote-reference faults belong to ReferencesCheck's own section,
// not this lot — asserted so the partition stays a partition.
it('does not surface a reference fault a second time under Support findings', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('cycle-pointer.yaml'));

    expect(SupportMatrixCheck::rejected($outcome))->toBe([]);
});

// --- Lot 2: operationId required once an operation promises public + stable ---

function supportMatrixPromisedOperation(?string $operationId, Audience $audience, ?Lifecycle $lifecycle): Operation
{
    return new Operation(0, HttpMethod::Get, PathTemplate::fromString('/users/{id}'), $operationId, audience: $audience, lifecycle: $lifecycle);
}

it('flags a public, stable operation with no operationId', function (): void {
    $findings = SupportMatrixCheck::missingOperationId([
        supportMatrixPromisedOperation(null, Audience::Public, Lifecycle::Stable),
    ]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->section)->toBe('Support findings')
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->message)->toContain('promises public and stable');
});

it('is silent when the operationId is present', function (): void {
    expect(SupportMatrixCheck::missingOperationId([
        supportMatrixPromisedOperation('showUser', Audience::Public, Lifecycle::Stable),
    ]))->toBe([]);
});

it('is silent for a beta operation with no operationId', function (): void {
    expect(SupportMatrixCheck::missingOperationId([
        supportMatrixPromisedOperation(null, Audience::Public, Lifecycle::Beta),
    ]))->toBe([]);
});

it('is silent for an internal operation with no operationId', function (): void {
    expect(SupportMatrixCheck::missingOperationId([
        supportMatrixPromisedOperation(null, Audience::Internal, null),
    ]))->toBe([]);
});

// --- Lot 3: Deferred, one line per construct rather than per occurrence ---

/**
 * @param  array<string, mixed>  $raw
 */
function supportMatrixDocumentWithRawPaths(array $raw): ParsableSpecDocument
{
    return new ParsableSpecDocument('spec.yaml', SpecVersion::V3_0, new OpenApi30Strategy, [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Fixture', 'version' => '1.0.0'],
        'paths' => $raw,
    ]);
}

it('is silent about deferred constructs when there are none to count', function (): void {
    expect(SupportMatrixCheck::deferred(null))->toBe([]);
});

it('counts query parameters across the document as one Deferred finding', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => ['get' => [
            'parameters' => [
                ['name' => 'id', 'in' => 'path'],
                ['name' => 'page', 'in' => 'query'],
            ],
            'responses' => ['200' => ['description' => 'ok']],
        ]],
        '/posts' => ['get' => [
            'parameters' => [
                ['name' => 'X-Trace', 'in' => 'header'],
            ],
            'responses' => ['200' => ['description' => 'ok']],
        ]],
    ]);

    $findings = array_values(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'non-path parameter'),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->level)->toBe(SupportLevel::Deferred)
        // Two non-path parameters counted (query + header); the path
        // parameter itself is not one of them.
        ->and($findings[0]->message)->toContain('2 non-path parameter(s)');
});

it('counts requestBody and responses as their own separate findings', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => [
            'post' => [
                'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => ['201' => ['description' => 'created']],
            ],
        ],
    ]);

    $findings = SupportMatrixCheck::deferred($document);
    $messages = implode('', array_map(static fn ($finding): string => $finding->message, $findings));

    expect($findings)->toHaveCount(2)
        ->and($messages)->toContain('1 request body/bodies')
        ->and($messages)->toContain('1 response declaration(s)');
});

it('does not count a path parameter as a deferred one', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users/{id}' => ['get' => [
            'parameters' => [['name' => 'id', 'in' => 'path']],
            'responses' => ['200' => ['description' => 'ok']],
        ]],
    ]);

    $messages = implode('', array_map(
        static fn ($finding): string => $finding->message,
        SupportMatrixCheck::deferred($document),
    ));

    expect($messages)->not->toContain('non-path parameter');
});
