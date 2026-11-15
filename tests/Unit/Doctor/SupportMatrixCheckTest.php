<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Doctor\Checks\SupportMatrixCheck;
use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
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

// The count split when a `query` parameter first became a rule. What is left
// under one label is the two locations this package reads and will not act on,
// and the level moved with them: `Ignored` gates the exit code where `Deferred`
// does not, which is the whole difference between a position and a roadmap.
it('counts header and cookie parameters as one Ignored finding', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => ['get' => [
            'parameters' => [
                ['name' => 'id', 'in' => 'path'],
                ['name' => 'page', 'in' => 'query'],
                ['name' => 'session', 'in' => 'cookie'],
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
        static fn ($finding): bool => str_contains($finding->message, 'header or cookie parameter'),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->level)->toBe(SupportLevel::Ignored)
        // The cookie and the header. Neither the path parameter nor the query
        // one is counted: one was always honored, and the other is now.
        ->and($findings[0]->message)->toContain('2 header or cookie parameter declaration(s)');
});

// A `Deferred` finding does not gate; an `Ignored` one does. The level is the
// whole content of that difference, so it is asserted as a code rather than as
// a word in a message.
it('makes an unread parameter gate the exit code', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => ['get' => [
            'parameters' => [['name' => 'X-Trace', 'in' => 'header']],
            'responses' => ['200' => ['description' => 'ok']],
        ]],
    ]);

    $report = new DiagnosticReport(
        'spec.yaml',
        true,
        [],
        'openapi-external-refs',
        SpecVersion::V3_0,
        [],
        SupportMatrixCheck::deferred($document),
    );

    expect($report->exitCode())->toBe(2);
});

// `requestBody` left the Deferred lot when a rule set was first built from one.
// What a particular body states and this package does not enforce is named in
// the generated request's own findings, per field, rather than counted here.
it('no longer counts a request body as deferred', function (): void {
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

    expect($findings)->toHaveCount(1)
        ->and($messages)->not->toContain('request body')
        ->and($messages)->toContain('1 response declaration(s)');
});

it('does not count a path parameter as an unread one', function (): void {
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

    expect($messages)->not->toContain('header or cookie parameter');
});

// --- Reading a raw document whose nodes have the wrong type ---

/**
 * A raw document with an arbitrary root, so a test can put a node of the wrong type where this class reads one. Every
 * existing test above builds a well-formed array, which is exactly why the suite stayed green on a command that died on
 * `parameters: oops`.
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

    expect(array_filter($findings, static fn ($f): bool => str_contains($f->message, 'header or cookie parameter')))->toBe([])
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
                ['name' => 'session', 'in' => 'cookie'],
            ],
            'get' => ['responses' => ['200' => ['description' => 'ok']]],
            'post' => ['responses' => ['201' => ['description' => 'created']]],
        ],
    ]);

    $findings = array_values(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'header or cookie parameter'),
    ));

    // Two declarations, counted once each rather than once per operation
    // beneath them: one declaration is what is written and what a reader counts.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('2 header or cookie parameter declaration(s)');
});

// A Reference Object carries no `in` of its own, so reading `in` off the
// reference counted a referenced parameter by the wrong location entirely.
it('resolves a referenced parameter before deciding where it sits', function (): void {
    $document = supportMatrixRawDocument([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Fixture', 'version' => '1.0.0'],
        'components' => ['parameters' => [
            'UserId' => ['name' => 'id', 'in' => 'path', 'required' => true],
            'Page' => ['name' => 'page', 'in' => 'query'],
            'Trace' => ['name' => 'X-Trace', 'in' => 'header'],
        ]],
        'paths' => ['/users/{id}' => ['get' => [
            'parameters' => [
                ['$ref' => '#/components/parameters/UserId'],
                ['$ref' => '#/components/parameters/Page'],
                ['$ref' => '#/components/parameters/Trace'],
            ],
            'responses' => ['200' => ['description' => 'ok']],
        ]]],
    ]);

    $findings = array_values(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'header or cookie parameter'),
    ));

    // One of three: the referenced path parameter was always honored and the
    // referenced query one is now, so only the header is left to report.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('1 header or cookie parameter declaration(s)');
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
        static fn ($finding): bool => str_contains($finding->message, 'header or cookie parameter'),
    ))->toBe([]);
})->with([
    'into another file' => ['common.yaml#/components/parameters/Trace'],
    'at a position the document does not hold' => ['#/components/parameters/Nope'],
    'at a target that is not a node' => ['#/openapi'],
]);

// Matched against `header` and `cookie` rather than against everything that is
// not `path`. A Parameter Object with no `in` at all is a document the parser
// will refuse, not a parameter this package reads, and counting it would put a
// wrong number on a line whose whole content is one.
it('does not count an entry whose location it cannot read', function (mixed $parameter): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => ['get' => [
            'parameters' => [$parameter],
            'responses' => ['200' => ['description' => 'ok']],
        ]],
    ]);

    expect(array_filter(
        SupportMatrixCheck::deferred($document),
        static fn ($finding): bool => str_contains($finding->message, 'header or cookie parameter'),
    ))->toBe([]);
})->with([
    'no in at all' => [['name' => 'page']],
    'an in nobody recognizes' => [['name' => 'page', 'in' => 'body']],
]);

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

// --- Request schemas: what a keyword the contract wrote does not become ---

/**
 * A `POST /things` carrying a body, `query` parameters, or both.
 *
 * @param  array<string, Schema>  $content
 * @param  list<QueryParameter>  $query
 */
function supportMatrixRequest(array $content = [], array $query = [], string $path = '/things'): Operation
{
    return new Operation(
        0,
        HttpMethod::Post,
        PathTemplate::fromString($path),
        'createThing',
        requestBody: $content === [] ? null : new RequestBody($content, true),
        queryParameters: $query,
    );
}

/**
 * @param  array<string, Schema>  $properties
 * @param  array<string, string>  $ignored
 * @param  list<Schema>  $allOf
 */
function supportMatrixObject(array $properties = [], array $ignored = [], array $allOf = []): Schema
{
    return new Schema(types: [SchemaType::Object], properties: $properties, allOf: $allOf, ignored: $ignored);
}

it('reports an ignored keyword once per operation, naming where it is written', function (): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        ignored: ['oneOf' => '#/paths/~1things/post/requestBody/content/application~1json/schema/oneOf'],
    )])]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->section)->toBe('Support findings')
        ->and($findings[0]->level)->toBe(SupportLevel::Ignored)
        ->and($findings[0]->pointer)->toBe('#/paths/~1things/post/requestBody/content/application~1json/schema/oneOf')
        ->and($findings[0]->message)->toContain('post /things')
        ->and($findings[0]->message)->toContain('`oneOf`')
        ->and($findings[0]->message)->toContain('#/paths/~1things/post/requestBody/content/application~1json/schema/oneOf');
});

// One finding per keyword and per operation, never one per occurrence: a `default`
// on every property of a body is one line that lists where, and the pointer is
// the first of them.
it('folds a keyword written at several positions into one finding that lists them all', function (): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        properties: [
            'status' => new Schema(types: [SchemaType::String], ignored: ['default' => '#/schema/properties/status/default']),
            'tags' => new Schema(
                types: [SchemaType::Array],
                items: new Schema(types: [SchemaType::String], ignored: ['default' => '#/schema/properties/tags/items/default']),
            ),
            'kind' => new Schema(types: [SchemaType::String], ignored: ['deprecated' => '#/schema/properties/kind/deprecated']),
        ],
    )])]);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('`default`')
        ->and($findings[0]->message)->toContain('#/schema/properties/status/default, #/schema/properties/tags/items/default')
        ->and($findings[0]->pointer)->toBe('#/schema/properties/status/default')
        ->and($findings[1]->message)->toContain('`deprecated`')
        ->and($findings[1]->pointer)->toBe('#/schema/properties/kind/deprecated');
});

it('reports the same keyword once for each operation that writes it', function (): void {
    $write = static fn (string $path): Operation => supportMatrixRequest(
        ['application/json' => supportMatrixObject(ignored: ['default' => '#/'.$path.'/default'])],
        path: $path,
    );

    $findings = SupportMatrixCheck::requestSchemas([$write('/a'), $write('/b')]);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('post /a')
        ->and($findings[1]->message)->toContain('post /b');
});

it('reads an allOf branch, a nested object and a query parameter', function (): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(
        ['application/json' => supportMatrixObject(
            properties: ['inner' => supportMatrixObject(ignored: ['not' => '#/inner/not'])],
            allOf: [supportMatrixObject(ignored: ['anyOf' => '#/branch/anyOf'])],
        )],
        [new QueryParameter('sort', new Schema(types: [SchemaType::String], ignored: ['default' => '#/query/sort/default']))],
    )]);

    expect(array_map(static fn ($finding): string => $finding->pointer, $findings))
        ->toBe(['#/inner/not', '#/branch/anyOf', '#/query/sort/default']);
});

// A body in a media type this package does not read produces no rule, so a
// keyword in it is not one that goes unenforced: the media type itself is what
// the generated request says it did not read.
it('ignores a media type the rule set does not read', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest([
        'application/xml' => supportMatrixObject(ignored: ['oneOf' => '#/xml/oneOf']),
    ])]))->toBe([]);
});

it('is silent about an operation whose request writes no ignored keyword', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        properties: ['name' => new Schema(types: [SchemaType::String], maxLength: 10)],
    )])]))->toBe([]);
});

// The same shape under two media types is one request, so a keyword written at
// one position is not reported twice, and one written at two is listed at both.
it('lists a keyword once per position when two media types share one schema', function (): void {
    $shared = supportMatrixObject(ignored: ['default' => '#/components/schemas/Thing/default']);

    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest([
        'application/json' => $shared,
        'multipart/form-data' => $shared,
    ])]);

    expect($findings)->toHaveCount(1)
        ->and(substr_count($findings[0]->message, '#/components/schemas/Thing/default'))->toBe(1);
});

it('reports what a read keyword leaves unenforced as Partial, naming the fields', function (): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        properties: [
            'website' => new Schema(types: [SchemaType::String], format: 'uri'),
            'home' => new Schema(types: [SchemaType::String], format: 'uri'),
        ],
    )])]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->pointer)->toBe('#/paths/~1things/post')
        ->and($findings[0]->message)->toContain('post /things')
        ->and($findings[0]->message)->toContain('`format`')
        ->and($findings[0]->message)->toContain('`website`, `home`');
});

// The matrix rates `enum` as honored, and an `enum` on an object is a field it was not read for. The finding is
// `Partial` because it names fields rather than a keyword, so it must not say the keyword is read in part.
it('says the fields leave the keyword out, never that the package reads part of it', function (): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        properties: ['meta' => new Schema(types: [SchemaType::Object], enum: [[]])],
    )])]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->message)->toContain('`enum`')
        ->and($findings[0]->message)->toContain('`meta`')
        ->and($findings[0]->message)->toContain('on these fields')
        ->and($findings[0]->message)->not->toContain('reads part of it');
});

// The informational lines of a generated file's findings must never gate the
// doctor: two media types over one schema is worth saying and wrong to fail on.
it('does not report what a generated file only mentions', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest([
        'application/json' => supportMatrixObject(['a' => new Schema(types: [SchemaType::String])]),
        'multipart/form-data' => supportMatrixObject(['a' => new Schema(types: [SchemaType::String])]),
    ], [new QueryParameter('tags', new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String])))])]))
        ->toBe([]);
});

// What the build plan refuses, Routing outcome already reports. The ignored
// keywords were read before any rule set existed, so they stay reportable; what
// a rule set would have said cannot be known, and is left out rather than guessed.
it('still reports ignored keywords when the rule set cannot be built', function (): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(
        ['application/json' => supportMatrixObject(
            properties: [
                'page' => new Schema(types: [SchemaType::String], format: 'uri', ignored: ['default' => '#/page/default']),
            ],
        )],
        [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    )]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->level)->toBe(SupportLevel::Ignored)
        ->and($findings[0]->message)->toContain('`default`');
});

/**
 * @param  list<Finding>  $findings
 */
function supportMatrixReport(array $findings): DiagnosticReport
{
    return new DiagnosticReport('spec.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], $findings);
}

// A package limit gates with 2, and a document fault still wins with 1.
it('gates the exit code as a package limit, never as a document fault', function (): void {
    $limits = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        ignored: ['oneOf' => '#/oneOf'],
        properties: ['website' => new Schema(types: [SchemaType::String], format: 'uri')],
    )])]);

    $fault = new Finding(FindingClass::DocumentFault, 'Document validity', null, '', 'a fault');

    expect($limits)->toHaveCount(2)
        ->and(array_unique(array_map(static fn ($finding): FindingClass => $finding->class, $limits), SORT_REGULAR))
        ->toBe([FindingClass::PackageLimit])
        ->and(supportMatrixReport($limits)->exitCode())->toBe(2)
        ->and(supportMatrixReport([...$limits, $fault])->exitCode())->toBe(1);
});

// --- Keywords written beside a $ref, at 3.1 ---

it('names an Ignored keyword written beside a $ref in a 3.1 request', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('doctor-ref-siblings-3.1.yaml'));
    $at = '#/paths/~1things/post/requestBody/content/application~1json/schema/properties';
    $findings = SupportMatrixCheck::requestSchemas($outcome->operations);

    expect(array_map(static fn (Finding $finding): string => $finding->pointer, $findings))->toBe([
        $at.'/code/default',
        $at.'/code/deprecated',
        $at.'/node/x-max-depth',
    ])->and($findings[0]->level)->toBe(SupportLevel::Ignored)
        ->and($findings[0]->message)->toContain('`default`')
        // The depth is honored on the schema a recursion points back at, so the message says where to write it
        // rather than that the package reads past it.
        ->and($findings[2]->message)->toContain('`x-max-depth`')
        ->and($findings[2]->message)->toContain('on the recursive schema itself')
        ->and($findings[2]->message)->not->toContain('reads past it');
});

it('names nothing for a keyword beside a $ref in a 3.0 request', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('doctor-ref-siblings-3.0.yaml'));

    expect($outcome->faults)->toBe([])
        ->and(SupportMatrixCheck::requestSchemas($outcome->operations))->toBe([]);
});
