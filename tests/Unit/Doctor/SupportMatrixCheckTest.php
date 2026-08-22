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
        ->and($findings[0]->message)->toContain('2 non-path parameter declaration(s)');
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

// --- Reading a raw document whose nodes have the wrong type ---

/**
 * A raw document with an arbitrary root, so a test can put a node of the wrong
 * type where this class reads one. Every existing test above builds a
 * well-formed array, which is exactly why the suite stayed green on a command
 * that died on `parameters: oops`.
 *
 * @param  array<array-key, mixed>  $raw
 */
function supportMatrixRawDocument(array $raw): ParsableSpecDocument
{
    return new ParsableSpecDocument('spec.yaml', SpecVersion::V3_0, new OpenApi30Strategy, $raw);
}

// The one input class this command exists for. Under a booted Laravel
// application `HandleExceptions` promotes the `foreach` warning to an
// `ErrorException`, so the command did not degrade — it died with a stack
// trace, in the section *after* the one that had already reported the real
// fault correctly.
it('survives a parameters node that is not a list', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => ['get' => [
            'parameters' => 'oops',
            'responses' => ['200' => ['description' => 'ok']],
        ]],
    ]);

    $findings = SupportMatrixCheck::deferred($document);

    expect(array_filter($findings, static fn ($f): bool => str_contains($f->message, 'non-path parameter')))->toBe([])
        ->and($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('1 response declaration(s)');
});

it('survives a node of the wrong type anywhere this section reads one', function (mixed $raw): void {
    expect(SupportMatrixCheck::deferred(supportMatrixRawDocument($raw)))->toBeArray();
})->with([
    'paths is a list' => [['paths' => ['/users']]],
    'paths is a string' => [['paths' => 'oops']],
    'a path item is a string' => [['paths' => ['/users' => 'oops']]],
    'an operation is a string' => [['paths' => ['/users' => ['get' => 'oops']]]],
    'responses is a string' => [['paths' => ['/users' => ['get' => ['responses' => 'oops']]]]],
    'path-item parameters is a string' => [['paths' => ['/users' => ['parameters' => 'oops', 'get' => []]]]],
    'a parameter entry is a string' => [['paths' => ['/users' => ['get' => ['parameters' => ['oops']]]]]],
    'no paths at all' => [['openapi' => '3.0.3']],
]);

// --- What each Deferred count actually counts ---

// The whole content of one of these findings is a number, so a label counting
// something else is the whole finding being wrong. This counted operations
// carrying a non-empty `responses` object, and printed "8 response
// declaration(s)" for a document declaring considerably more than eight.
it('counts response declarations rather than operations that declare any', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users/{id}' => ['get' => [
            'responses' => [
                '200' => ['description' => 'a user'],
                '404' => ['description' => 'no such user'],
            ],
        ]],
        '/posts' => ['get' => [
            'responses' => ['200' => ['description' => 'posts']],
        ]],
    ]);

    $findings = array_values(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'response declaration'),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('3 response declaration(s)');
});

// OpenAPI lets a path declare parameters shared by every operation under it,
// and a specification written that way reported zero deferred parameters —
// rule 2 defeated for a construct this package genuinely does not honor.
it('counts the parameters a Path Item declares for every operation under it', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => [
            'parameters' => [
                ['name' => 'page', 'in' => 'query'],
                ['name' => 'X-Trace', 'in' => 'header'],
            ],
            'get' => ['responses' => ['200' => ['description' => 'ok']]],
            'post' => ['responses' => ['201' => ['description' => 'created']]],
        ],
    ]);

    $findings = array_values(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'non-path parameter'),
    ));

    // Two declarations, counted once each rather than once per operation
    // beneath them: one declaration is what is written and what a reader counts.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('2 non-path parameter declaration(s)');
});

// A Reference Object carries no `in` of its own, so reading `in` off the
// reference counted a referenced *path* parameter toward the non-path total.
it('resolves a referenced parameter before deciding whether it is a path parameter', function (): void {
    $document = supportMatrixRawDocument([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Fixture', 'version' => '1.0.0'],
        'components' => ['parameters' => [
            'UserId' => ['name' => 'id', 'in' => 'path', 'required' => true],
            'Page' => ['name' => 'page', 'in' => 'query'],
        ]],
        'paths' => ['/users/{id}' => ['get' => [
            'parameters' => [
                ['$ref' => '#/components/parameters/UserId'],
                ['$ref' => '#/components/parameters/Page'],
            ],
            'responses' => ['200' => ['description' => 'ok']],
        ]]],
    ]);

    $findings = array_values(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'non-path parameter'),
    ));

    // One, not two: the referenced path parameter is not a deferred construct.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('1 non-path parameter declaration(s)');
});

// A reference this class cannot follow is skipped rather than guessed at — the
// number would otherwise be one nobody can check.
it('skips a reference it cannot follow rather than counting it', function (mixed $reference): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => ['get' => [
            'parameters' => [['$ref' => $reference]],
            'responses' => ['200' => ['description' => 'ok']],
        ]],
    ]);

    expect(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'non-path parameter'),
    ))->toBe([]);
})->with([
    'into another file' => ['common.yaml#/components/parameters/Page'],
    'at a position the document does not hold' => ['#/components/parameters/Nope'],
    'at a target that is not a node' => ['#/openapi'],
]);

// `in` present *and* not `path`. A Parameter Object with no `in` at all is a
// document the parser will refuse, not a parameter this package could defer,
// and counting it would put a wrong number on a line whose whole content is one.
it('does not count an entry with no in at all', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => ['get' => [
            'parameters' => [['name' => 'page']],
            'responses' => ['200' => ['description' => 'ok']],
        ]],
    ]);

    expect(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'non-path parameter'),
    ))->toBe([]);
});

// --- The pointer the section can actually build ---

it('points at the operation the operationId rule names', function (): void {
    $findings = SupportMatrixCheck::missingOperationId([
        new Operation(
            0,
            HttpMethod::Get,
            PathTemplate::fromString('/users/{id}'),
            null,
            audience: Audience::Public,
            lifecycle: Lifecycle::Stable,
        ),
    ]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->pointer)->toBe('#/paths/~1users~1{id}/get')
        ->and($findings[0]->level)->toBe(SupportLevel::Partial);
});
