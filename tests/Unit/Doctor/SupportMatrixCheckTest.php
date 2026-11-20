<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Doctor\Checks\SupportMatrixCheck;
use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Generation\DeepObjectArrays;
use Gcob\LaraSpecFirst\Generation\ResponseDtoPlanner;
use Gcob\LaraSpecFirst\Generation\RuleSetOptions;
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

it('is silent about unread parameters when there is no document to count them in', function (): void {
    expect(SupportMatrixCheck::unreadParameters(null))->toBe([]);
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
        SupportMatrixCheck::unreadParameters($document),
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
        SupportMatrixCheck::unreadParameters($document),
    );

    expect($report->exitCode())->toBe(2);
});

// `requestBody` left the Deferred lot when a rule set was first built from one.
// What a particular body states and this package does not enforce is named in
// the generated request's own findings, per field, rather than counted here. A
// response left the lot after it: the header and cookie parameters are the one
// count there is.
it('no longer counts a request body as deferred', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users' => [
            'post' => [
                'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => ['201' => ['description' => 'created']],
            ],
        ],
    ]);

    expect(SupportMatrixCheck::unreadParameters($document))->toBe([]);
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
        SupportMatrixCheck::unreadParameters($document),
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

    expect(SupportMatrixCheck::unreadParameters($document))->toBe([]);
});

it('survives a node of the wrong type anywhere this section reads one', function (mixed $raw): void {
    expect(SupportMatrixCheck::unreadParameters(supportMatrixRawDocument($raw)))->toBeArray();
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

// --- What each count actually counts ---

// A response is not counted at all now: the doctor names the keywords a response
// DTO does not type, per operation, and no line says a response is deferred. It
// used to count declarations rather than operations, and three of them are still
// nothing to count.
it('no longer counts response declarations as deferred', function (): void {
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

    expect(SupportMatrixCheck::unreadParameters($document))->toBe([]);
});

// Response DTOs are generated, and the keywords a response schema writes are named
// per operation under their own finding, so a response leaves no line at the
// Deferred level and nothing points at the card that used to promise one.
it('leaves no Deferred line for responses', function (): void {
    $document = supportMatrixDocumentWithRawPaths([
        '/users/{id}' => ['get' => [
            'responses' => ['200' => ['description' => 'a user']],
        ]],
    ]);

    $findings = SupportMatrixCheck::unreadParameters($document);
    $messages = implode("\n", array_map(static fn ($finding): string => $finding->message, $findings));

    expect(array_filter($findings, static fn ($finding): bool => $finding->level === SupportLevel::Deferred))->toBe([])
        ->and($messages)->not->toContain('issues/164');
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
        SupportMatrixCheck::unreadParameters($document),
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
        SupportMatrixCheck::unreadParameters($document),
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
        SupportMatrixCheck::unreadParameters($document),
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
        SupportMatrixCheck::unreadParameters($document),
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
    )])], new RuleSetOptions);

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
    )])], new RuleSetOptions);

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

    $findings = SupportMatrixCheck::requestSchemas([$write('/a'), $write('/b')], new RuleSetOptions);

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
    )], new RuleSetOptions);

    expect(array_map(static fn ($finding): string => $finding->pointer, $findings))
        ->toBe(['#/inner/not', '#/branch/anyOf', '#/query/sort/default']);
});

// A body in a media type this package does not read produces no rule, so a
// keyword in it is not one that goes unenforced: the media type itself is what
// the generated request says it did not read.
it('ignores a media type the rule set does not read', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest([
        'application/xml' => supportMatrixObject(ignored: ['oneOf' => '#/xml/oneOf']),
    ])], new RuleSetOptions))->toBe([]);
});

it('is silent about an operation whose request writes no ignored keyword', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        properties: ['name' => new Schema(types: [SchemaType::String], maxLength: 10)],
    )])], new RuleSetOptions))->toBe([]);
});

// The same shape under two media types is one request, so a keyword written at
// one position is not reported twice, and one written at two is listed at both.
it('lists a keyword once per position when two media types share one schema', function (): void {
    $shared = supportMatrixObject(ignored: ['default' => '#/components/schemas/Thing/default']);

    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest([
        'application/json' => $shared,
        'multipart/form-data' => $shared,
    ])], new RuleSetOptions);

    expect($findings)->toHaveCount(1)
        ->and(substr_count($findings[0]->message, '#/components/schemas/Thing/default'))->toBe(1);
});

it('reports what a read keyword leaves unenforced as Partial, naming the fields', function (): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => supportMatrixObject(
        properties: [
            'website' => new Schema(types: [SchemaType::String], format: 'uri'),
            'home' => new Schema(types: [SchemaType::String], format: 'uri'),
        ],
    )])], new RuleSetOptions);

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
    )])], new RuleSetOptions);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->message)->toContain('`enum`')
        ->and($findings[0]->message)->toContain('`meta`')
        ->and($findings[0]->message)->toContain('on these fields')
        ->and($findings[0]->message)->not->toContain('reads part of it');
});

// A field the rule set drops whole is a field of the contract the request does not enforce, so the doctor names what
// it states, one finding per keyword, and says the request may hold part of it as well as none of it.
it('reports every gap a generated request counts as Partial', function (array $content, array $query, array $expected): void {
    assert(array_is_list($query));

    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest($content, $query)], new RuleSetOptions);

    expect($findings)->toHaveCount(count($expected))
        ->and(array_map(static fn (Finding $finding): ?SupportLevel => $finding->level, $findings))->each->toBe(SupportLevel::Partial)
        ->and(supportMatrixReport($findings)->exitCode())->toBe(2);

    foreach (array_keys($expected) as $at => $keyword) {
        expect($findings[$at]->class)->toBe(FindingClass::PackageLimit)
            ->and($findings[$at]->pointer)->toBe('#/paths/~1things/post')
            ->and($findings[$at]->message)->toContain('post /things writes `'.$keyword.'` where the generated request does not enforce it, or enforces only part of it: '.$expected[$keyword])
            ->and($findings[$at]->message)->toContain('on these fields')
            ->and($findings[$at]->message)->toContain('the findings in the generated file say which');
    }
})->with(function (): Generator {
    $form = 'application/x-www-form-urlencoded';
    $integer = new Schema(types: [SchemaType::Integer]);
    $string = new Schema(types: [SchemaType::String]);

    yield 'a form name PHP delivers nowhere' => [
        [$form => supportMatrixObject(['[x]' => $integer])],
        [],
        ['type' => '`[x]`'],
    ];
    yield 'two form names PHP delivers as one' => [
        [$form => supportMatrixObject(['a.b' => $string, 'a_b' => $string])],
        [],
        ['type' => '`a.b`, `a_b`'],
    ];
    yield 'a star name' => [
        ['application/json' => supportMatrixObject(['a*b' => $string])],
        [],
        ['type' => '`a*b`'],
    ];
    yield 'a dropped trigger' => [
        ['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: ['email' => $string],
            dependentRequired: ['a*b' => ['email']],
        )],
        [],
        ['dependentRequired' => '`email`'],
    ];
    yield 'a query name PHP appends to' => [
        [],
        [new QueryParameter('ids[]', $integer, required: true)],
        ['required' => '`ids[]`', 'type' => '`ids[]`'],
    ];
    yield 'a recursion no field names, held by a star' => [
        ['application/json' => supportMatrixObject(['a*b' => Schema::recursion('#/components/schemas/Node', 'Node')])],
        [],
        ['$ref' => '`a*b`'],
    ];
});

// The informational lines of a generated file's findings must never gate the
// doctor: two media types over one schema is worth saying and wrong to fail on.
it('does not report what a generated file only mentions', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest([
        'application/json' => supportMatrixObject(['a' => new Schema(types: [SchemaType::String])]),
        'multipart/form-data' => supportMatrixObject(['a' => new Schema(types: [SchemaType::String])]),
    ], [new QueryParameter('tags', new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String])))])], new RuleSetOptions))
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
    )], new RuleSetOptions);

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
    )])], new RuleSetOptions);

    $fault = new Finding(FindingClass::DocumentFault, 'Document validity', null, '', 'a fault');

    expect($limits)->toHaveCount(2)
        ->and(array_unique(array_map(static fn ($finding): FindingClass => $finding->class, $limits), SORT_REGULAR))
        ->toBe([FindingClass::PackageLimit])
        ->and(supportMatrixReport($limits)->exitCode())->toBe(2)
        ->and(supportMatrixReport([...$limits, $fault])->exitCode())->toBe(1);
});

// The doctor reported a schema under `additionalProperties` as `Ignored` before the contract carried it. Where the build
// derives no rule from the schema at all, it is still a finding, now `Partial`, and the exit code stays `2`.
it('keeps reporting an additionalProperties schema where no rule set reads the schema', function (array $content, array $query, string $site): void {
    assert(array_is_list($query));

    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest($content, $query)], new RuleSetOptions);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->message)->toContain('`additionalProperties`')
        ->and($findings[0]->message)->toContain($site)
        ->and(supportMatrixReport($findings)->exitCode())->toBe(2);
})->with(function (): Generator {
    $value = new Schema(types: [SchemaType::Integer]);

    yield 'a typeless body' => [['application/json' => new Schema(additionalPropertiesSchema: $value)], [], 'The body'];
    yield 'an array body of maps' => [
        ['application/json' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Object], additionalPropertiesSchema: $value))],
        [],
        'The body',
    ];
    yield 'a query parameter typed object' => [
        [],
        [new QueryParameter('filter', new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::String])))],
        '`filter`',
    ];
});

// A `style` or an `explode` the document got wrong is the author's to fix, so the doctor reports it as a fault in the
// document, which exits `1`: before the contract carried them, nothing reported it and the exit code was `0`.
it('reports a malformed style or explode of a query parameter as a fault in the document', function (mixed $style, mixed $explode, string $written): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(query: [
        new QueryParameter('filter', new Schema(types: [SchemaType::Object]), style: $style, explode: $explode),
    ])], new RuleSetOptions);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::DocumentFault)
        ->and($findings[0]->message)->toContain($written)
        ->and(supportMatrixReport($findings)->exitCode())->toBe(1);
})->with([
    'a number as the style' => [5, false, '`filter` writes a `style` of `5`'],
    'an unknown style' => ['bogus', false, '`filter` writes a `style` of `bogus`'],
    'a text explode' => ['form', 'yes', '`filter` writes an `explode` of `yes`'],
]);

it('reports nothing for a query parameter written with a style and an explode OpenAPI defines', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest(query: [
        new QueryParameter('filter', new Schema(types: [SchemaType::Object]), style: 'form', explode: false),
    ])], new RuleSetOptions))->toBe([]);
});

// A deepObject is validated now, so the doctor's answer moves with the rule set it reads, with no code of its own: a keyword
// inside it that no rule enforces is named (it was silent), a map whose values are judged is not (it was reported). An
// array inside it follows `query.deep_object_arrays`: held at its presence under `any`, where the finding in the generated
// file says so and the doctor adds nothing, and checked by its brackets otherwise, where a keyword no rule enforces inside
// an item, or `uniqueItems` on objects, is named as it is anywhere else.
it('moves the doctor\'s exit code for a deepObject', function (Schema $schema, DeepObjectArrays $reading, int $exitCode, ?string $named, ?string $at): void {
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(query: [
        new QueryParameter('filter', $schema, style: 'deepObject', explode: false),
    ])], new RuleSetOptions($reading));

    expect(supportMatrixReport($findings)->exitCode())->toBe($exitCode)
        ->and($findings)->toHaveCount($named === null ? 0 : 1);

    if ($named !== null) {
        expect($findings[0]->level)->toBe(SupportLevel::Partial)
            ->and($findings[0]->message)->toContain($named)
            ->and($findings[0]->message)->toContain((string) $at);
    }
})->with(function (): Generator {
    $integers = new Schema(types: [SchemaType::Integer]);
    $objects = new Schema(types: [SchemaType::Array], items: supportMatrixObject(['a' => $integers]), uniqueItems: true);
    $links = new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String], format: 'uri-reference'));

    yield 'a property no rule enforces' => [
        supportMatrixObject(['next' => new Schema(types: [SchemaType::String], format: 'uri-reference')]),
        DeepObjectArrays::Brackets,
        2,
        '`format`',
        '`filter[next]`',
    ];
    yield 'a map whose values are judged' => [
        new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::String])),
        DeepObjectArrays::Brackets,
        0,
        null,
        null,
    ];
    yield 'an array held at its presence' => [
        supportMatrixObject(['ids' => new Schema(types: [SchemaType::Array], items: $integers, minItems: 1, maxItems: 3, uniqueItems: true)]),
        DeepObjectArrays::Any,
        0,
        null,
        null,
    ];
    yield 'integers with bounds and unique items, by brackets' => [
        supportMatrixObject(['ids' => new Schema(types: [SchemaType::Array], items: $integers, minItems: 1, maxItems: 3, uniqueItems: true)]),
        DeepObjectArrays::Brackets,
        0,
        null,
        null,
    ];
    yield 'objects with unique items, by brackets' => [supportMatrixObject(['rows' => $objects]), DeepObjectArrays::Brackets, 2, '`uniqueItems`', '`filter[rows]`'];
    yield 'objects with unique items, held at their presence' => [supportMatrixObject(['rows' => $objects]), DeepObjectArrays::Any, 0, null, null];
    yield 'strings with a format, by brackets' => [supportMatrixObject(['links' => $links]), DeepObjectArrays::Brackets, 2, '`format`', '`filter[links][]`'];
    yield 'strings with a format, held at their presence' => [supportMatrixObject(['links' => $links]), DeepObjectArrays::Any, 0, null, null];
});

// The note about a required deepObject accepted left out is information: it loses no constraint, so the doctor stays quiet.
it('does not count a required deepObject accepted when left out', function (): void {
    expect(SupportMatrixCheck::requestSchemas([supportMatrixRequest(query: [
        new QueryParameter('filter', supportMatrixObject(['next' => new Schema(types: [SchemaType::String])]), required: true, style: 'deepObject', explode: false),
    ])], new RuleSetOptions))->toBe([]);
});

// The value schema is walked like any child, so a keyword nothing reads that it writes is `Ignored` at its own position.
// Beside declared properties the request validates the values, so the map is not `Partial`: a declared name the guard
// cannot spare keeps it.
it('reports a keyword written inside an additionalProperties schema', function (string $declared, array $levels): void {
    $at = '#/paths/~1things/post/requestBody/content/application~1json/schema/additionalProperties/deprecated';
    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => new Schema(
        types: [SchemaType::Object],
        properties: [$declared => new Schema(types: [SchemaType::Integer])],
        additionalPropertiesSchema: new Schema(types: [SchemaType::String], ignored: ['deprecated' => $at]),
    )])], new RuleSetOptions);

    $got = [];

    foreach ($findings as $finding) {
        $got[$finding->level->value ?? ''][] = $finding->pointer;
    }

    expect($got)->toBe(array_map(static fn (array $pointers): array => array_map(static fn (string $pointer): string => str_replace('{at}', $at, $pointer), $pointers), $levels));
})->with([
    'a declared name the guard spares' => ['id', [SupportLevel::Ignored->value => ['{at}']]],
    'a declared name it cannot' => ['a.b', [
        SupportLevel::Ignored->value => ['{at}'],
        SupportLevel::Partial->value => ['#/paths/~1things/post'],
    ]],
]);

// The doctor reported every map body as `Ignored`, so a contract whose only gap was a map exited `2`. Once the request
// validates the values nothing is left unenforced, so the same contract exits `0`: the move the CHANGELOG announces.
it('gives a map the request validates no additionalProperties finding, and a clean exit', function (Schema $body, array $query): void {
    assert(array_is_list($query));

    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest(['application/json' => $body], $query)], new RuleSetOptions);

    expect($findings)->toBe([])
        ->and(supportMatrixReport($findings)->exitCode())->toBe(0);
})->with(function (): Generator {
    $map = new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::String]));
    $page = [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))];

    yield 'a map body' => [$map, []];
    yield 'a map body with an empty value schema' => [new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema), []];
    yield 'a nested map' => [supportMatrixObject(['labels' => $map]), []];
    // The guard spares the declared names, so the values beside them are validated: the move the CHANGELOG announces.
    yield 'beside declared properties' => [new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [SchemaType::Integer])], additionalPropertiesSchema: new Schema(types: [SchemaType::String])), []];
    yield 'nested beside declared properties' => [supportMatrixObject(['meta' => new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [SchemaType::Integer])], additionalPropertiesSchema: new Schema(types: [SchemaType::String]))]), []];
    // And it spares the key a `query` parameter arrives under.
    yield 'a root map beside a query parameter' => [$map, $page];
    yield 'a root with properties beside a query parameter' => [new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [SchemaType::Integer])], additionalPropertiesSchema: new Schema(types: [SchemaType::String])), $page];
});

// The values of a map the build cannot validate are still a finding and still exit `2`: a declared name the guard cannot
// spare (a dot Laravel replaces, a `*`, a form name nested in another, a name a body that is JSON and a form reads two
// ways), and the one undeclared key a form delivers under a declared name's key.
it('keeps reporting the values of a map the request leaves unvalidated', function (array $content, array $query, int $count = 1): void {
    assert(array_is_list($query));

    $findings = SupportMatrixCheck::requestSchemas([supportMatrixRequest($content, $query)], new RuleSetOptions);

    // A declared name the rule set drops whole also leaves its type unenforced, and that is a finding of its own.
    expect($findings)->toHaveCount($count)
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->message)->toContain('`additionalProperties`')
        ->and(supportMatrixReport($findings)->exitCode())->toBe(2);
})->with(function (): Generator {
    $value = new Schema(types: [SchemaType::String]);
    $beside = static fn (string $declared): Schema => new Schema(types: [SchemaType::Object], properties: [$declared => $value], additionalPropertiesSchema: $value);

    yield 'beside a declared name holding a dot' => [['application/json' => $beside('a.b')], []];
    yield 'beside a declared name holding a star' => [['application/json' => $beside('x*y')], [], 2];
    yield 'beside a form name nested in another' => [['application/x-www-form-urlencoded' => $beside('page[size]')], []];
    yield 'beside a name a JSON and form body reads two ways' => [
        ['application/json' => $beside('user.name'), 'application/x-www-form-urlencoded' => $beside('user.name')],
        [],
        2,
    ];
    // Spared under `user_name`, and an undeclared key that arrives under it is judged by the declared name's rules only.
    yield 'beside a form name PHP delivers under another one' => [['application/x-www-form-urlencoded' => $beside('user.name')], []];
});

// --- Keywords written beside a $ref, at 3.1 ---

it('names an Ignored keyword written beside a $ref in a 3.1 request', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('doctor-ref-siblings-3.1.yaml'));
    $at = '#/paths/~1things/post/requestBody/content/application~1json/schema/properties';
    $findings = SupportMatrixCheck::requestSchemas($outcome->operations, new RuleSetOptions);

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
        ->and(SupportMatrixCheck::requestSchemas($outcome->operations, new RuleSetOptions))->toBe([]);
});

// --- Response schemas: a keyword a response DTO does not type ---

/**
 * What the doctor reports for a fixture's schemas when the build plan exists: the operations of the contract and the
 * response plan the build would make from them.
 *
 * @return list<Finding>
 */
function supportMatrixSchemaFindings(string $fixture): array
{
    $operations = extractFixture($fixture);

    return SupportMatrixCheck::schemas($operations, (new ResponseDtoPlanner)->plan($operations, []), new RuleSetOptions);
}

/**
 * The keywords a schema writes that the reading marks as ignored, wherever in the schema they are.
 *
 * @return list<string>
 */
function supportMatrixIgnoredIn(Schema $schema): array
{
    $found = array_keys($schema->ignored);

    foreach ([...array_values($schema->properties), ...$schema->allOf, ...array_filter([$schema->items, $schema->additionalPropertiesSchema])] as $child) {
        $found = [...$found, ...supportMatrixIgnoredIn($child)];
    }

    return $found;
}

// One finding per keyword and per operation holds for a response as it does for a request: `oneOf` written in three
// places of the responses of one operation is one line that lists them, the first as its pointer.
it('names a keyword a response DTO does not type once per operation', function (): void {
    $findings = supportMatrixSchemaFindings('doctor-response-keywords.yaml');
    $schema = '#/paths/~1orders~1{id}/get/responses/%s/content/application~1json/schema';
    $oneOf = [
        sprintf($schema, '200').'/properties/payment/oneOf',
        sprintf($schema, '200').'/properties/shipping/oneOf',
        sprintf($schema, '201').'/properties/receipt/oneOf',
    ];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->section)->toBe('Support findings')
        ->and($findings[0]->level)->toBe(SupportLevel::Ignored)
        ->and($findings[0]->pointer)->toBe($oneOf[0])
        ->and($findings[0]->message)->toContain('get /orders/{id} writes `oneOf` at '.implode(', ', $oneOf))
        ->and($findings[1]->pointer)->toBe(sprintf($schema, '200').'/properties/customer/properties/contact/anyOf')
        ->and($findings[1]->message)->toContain('`anyOf`')
        ->and((new DiagnosticReport('spec.yaml', true, [], 'openapi-external-refs', SpecVersion::V3_0, [], $findings))->exitCode())->toBe(2);
});

// A keyword written in both directions of one operation is one fact about it, so it is one finding that says what each
// direction does with it, the request's position first.
it('lists request and response positions in one finding per keyword', function (): void {
    $findings = supportMatrixSchemaFindings('doctor-response-keywords-both.yaml');
    $request = '#/paths/~1orders/post/requestBody/content/application~1json/schema/properties/payment/oneOf';
    $response = '#/paths/~1orders/post/responses/200/content/application~1json/schema/properties/payment/oneOf';

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->pointer)->toBe($request)
        ->and($findings[0]->message)->toContain('`oneOf` at '.$request.', '.$response)
        ->and($findings[0]->message)->toContain('the generated request enforces nothing for it')
        ->and($findings[0]->message)->toContain('the response DTO reads past it');
});

it('names a response keyword under every operation that reaches it', function (): void {
    $findings = supportMatrixSchemaFindings('doctor-response-keywords-shared.yaml');

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('get /users/{id} writes `anyOf` at #/components/schemas/User/properties/contact/anyOf')
        ->and($findings[1]->message)->toContain('get /teams/{id} writes `anyOf` at #/components/schemas/User/properties/contact/anyOf')
        ->and($findings[1]->pointer)->toBe('#/components/schemas/User/properties/contact/anyOf');
});

// What an annotation or a value constraint says is not part of the type a DTO holds, and #122 is where values are
// checked: naming it here would be a line the author cannot act on.
it('does not name a value constraint or an annotation written only in a response', function (): void {
    $operations = extractFixture('doctor-response-silent-keywords.yaml');
    $written = supportMatrixIgnoredIn($operations[0]->responses[0]->content['application/json']);
    $plan = (new ResponseDtoPlanner)->plan($operations, []);

    expect($written)->toContain('default', 'deprecated', 'minProperties', 'contains')
        ->and($plan->statuses['get /things'][0]->dto)->not->toBeNull()
        ->and(SupportMatrixCheck::schemas($operations, $plan, new RuleSetOptions))->toBe([]);
});

// A keyword in a response with no DTO reaches nothing: the status has no type to say it about.
it('does not name a keyword in a response that has no DTO', function (): void {
    $operations = extractFixture('doctor-response-no-dto.yaml');
    $plan = (new ResponseDtoPlanner)->plan($operations, []);

    expect(array_map(static fn (array $statuses): ?string => $statuses[0]->dto, $plan->statuses))->toBe([
        'get /text' => null,
        'get /two-shapes' => null,
        'get /strings' => null,
    ])
        ->and(SupportMatrixCheck::schemas($operations, $plan, new RuleSetOptions))->toBe([]);
});

it('names a 3.1 keyword written only in a response', function (): void {
    $findings = supportMatrixSchemaFindings('doctor-response-keywords-3.1.yaml');
    $schema = '#/paths/~1things/get/responses/200/content/application~1json/schema/properties';

    expect(array_map(static fn (Finding $finding): string => $finding->pointer, $findings))->toBe([
        $schema.'/pair/prefixItems',
        $schema.'/rest/unevaluatedItems',
        $schema.'/card/dependentSchemas',
    ]);
});

// --- Response schemas: a property a DTO types mixed, or does not carry ---

/**
 * One `GET /things` answering `200` with this schema.
 */
function supportMatrixAnswering(Schema $schema): Operation
{
    return new Operation(
        0,
        HttpMethod::Get,
        PathTemplate::fromString('/things'),
        'showThing',
        responses: [new Response('200', ['application/json' => $schema])],
    );
}

/**
 * A component answered with, holding one property.
 */
function supportMatrixComponent(string $name, Schema $property): Schema
{
    return new Schema(
        name: $name,
        source: '#/components/schemas/'.$name,
        types: [SchemaType::Object],
        properties: ['x' => $property],
    );
}

/**
 * @return list<Finding>
 */
function supportMatrixFindingsFor(Operation $operation): array
{
    return SupportMatrixCheck::schemas([$operation], (new ResponseDtoPlanner)->plan([$operation], []), new RuleSetOptions);
}

// Each way a response DTO ends up with a value it types `mixed`, or does not carry, is one finding of its own under the
// operation, with its cause in the words, so the author knows what to change.
it('names each reason a response DTO types a value mixed', function (Schema $schema, string $position, string $reason): void {
    $findings = supportMatrixFindingsFor(supportMatrixAnswering($schema));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->class)->toBe(FindingClass::PackageLimit)
        ->and($findings[0]->level)->toBe(SupportLevel::Partial)
        ->and($findings[0]->section)->toBe('Support findings')
        ->and($findings[0]->pointer)->toBe($position)
        ->and($findings[0]->message)->toContain('get /things answers with a DTO that')
        ->and($findings[0]->message)->toContain($position)
        ->and($findings[0]->message)->toContain($reason);
})->with([
    'a type union' => [
        supportMatrixComponent('Team', new Schema(types: [SchemaType::String, SchemaType::Integer])),
        '#/components/schemas/Team/properties/x',
        'because it states no single type (a union of types, no type at all, or only `null`)',
    ],
    'a recursion with no class to point at' => [
        new Schema(types: [SchemaType::Object], properties: [
            'link' => Schema::recursion('#/paths/~1showThing/get/responses/200/content/application~1json/schema'),
        ]),
        '#/paths/~1things/get/responses/200/content/application~1json/schema/properties/link',
        'because it points back at a schema that is not a single named schema, so there is no class to build it into',
    ],
    'properties written beside the reference' => [
        new Schema(
            name: 'Node',
            source: '#/components/schemas/Node',
            types: [SchemaType::Object],
            properties: ['link' => new Schema(
                name: 'Node',
                types: [SchemaType::Object],
                properties: ['extra' => new Schema(types: [SchemaType::String])],
                recursesTo: '#/components/schemas/Node',
            )],
        ),
        '#/components/schemas/Node/properties/link',
        'because it writes properties beside a reference to a schema it points back at, and the class of that schema does not carry them',
    ],
    'a target that is not an object with properties' => [
        supportMatrixComponent('Shelf', new Schema(
            name: 'Forest',
            source: '#/components/schemas/Forest',
            types: [SchemaType::Array],
            items: new Schema(types: [SchemaType::Object], properties: [
                'link' => Schema::recursion('#/components/schemas/Forest', 'Forest'),
            ]),
        )),
        '#/components/schemas/Shelf/properties/x/items/properties/link',
        'because it points back at a schema that is not an object with properties, so there is no class to read it into',
    ],
    'values beside declared properties' => [
        new Schema(
            name: 'Pet',
            source: '#/components/schemas/Pet',
            types: [SchemaType::Object],
            properties: ['name' => new Schema(types: [SchemaType::String])],
            additionalPropertiesSchema: new Schema(types: [SchemaType::String]),
        ),
        '#/components/schemas/Pet/additionalProperties',
        'does not carry the values `additionalProperties` types',
    ],
]);

// A value that writes a keyword the doctor names is one fact, and the keyword's finding is the one that says it: a
// second finding for the property would be the same line twice. One that writes only an annotation keeps its own.
it('names a keyword once when the mixed value writes it', function (): void {
    $findings = supportMatrixSchemaFindings('response-untyped-keywords.yaml');
    $order = '#/components/schemas/Order/properties';

    expect(array_map(static fn (Finding $finding): ?SupportLevel => $finding->level, $findings))->toBe([SupportLevel::Ignored, SupportLevel::Ignored])
        ->and(array_map(static fn (Finding $finding): string => $finding->pointer, $findings))->toBe([
            $order.'/payment/oneOf',
            '#/components/schemas/Note/anyOf',
        ]);

    $annotated = supportMatrixFindingsFor(supportMatrixAnswering(supportMatrixComponent('Team', new Schema(
        types: [SchemaType::String, SchemaType::Integer],
        ignored: ['default' => '#/components/schemas/Team/properties/x/default'],
    ))));

    expect($annotated)->toHaveCount(1)
        ->and($annotated[0]->level)->toBe(SupportLevel::Partial);
});
