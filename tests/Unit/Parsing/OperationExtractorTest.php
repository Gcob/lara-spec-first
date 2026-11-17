<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\EncodingDefaults;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\ExtractionResult;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi30Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi31Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategy;
use Symfony\Component\Yaml\Yaml;

/**
 * The read is assumed clean here: every fixture this helper is used against has a readable document, so unwrapping
 * straight to the extractor's own result is what keeps every existing assertion below reading exactly as it did before
 * this class stopped throwing.
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
 * @see docs/guide/controllers.md — "The contract decides what is customizable"
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

// Refused rather than defaulted, as the lifecycle extensions are: a depth nobody could
// read is a contract that says something this package would silently not do.
it('refuses an x-max-depth that is not a whole number of levels', function (string $fixture): void {
    $result = extractionFrom($fixture);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($result->faults[0]->getMessage())
        ->toContain('`x-max-depth`')
        ->toContain('#/components/schemas/Address')
        ->toContain('whole number');
})->with([
    'text' => 'max-depth-not-an-integer.yaml',
    'a negative number' => 'max-depth-negative.yaml',
    'a fraction' => 'max-depth-a-fraction.yaml',
]);

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

// Three of the four parameter locations do not reach the contract at all: a
// path parameter is the router's question and PathTemplate already carries it,
// and a header or a cookie is an Ignored support level rather than a gap.
it('carries the query parameters and no other location', function (): void {
    $operations = extractionFrom('query-parameters.yaml')->operations;

    $names = array_map(
        static fn (QueryParameter $parameter): string => $parameter->name,
        $operations[0]->queryParameters
    );

    expect($names)->toBe(['page', 'notify']);
});

// OpenAPI's own merge rule, which the parser does not apply: a Path Item's
// parameters are the operation's too, and the operation wins on a name they
// share. It is a merge rather than a collision, so the operation's schema is
// what survives.
it('lets the operation win over the Path Item on a shared parameter name', function (): void {
    $parameters = extractionFrom('query-parameters.yaml')->operations[0]->queryParameters;

    expect($parameters[1]->name)->toBe('notify')
        ->and($parameters[1]->required)->toBeTrue()
        ->and($parameters[1]->schema->types)->toBe([SchemaType::Boolean])
        ->and($parameters[0]->name)->toBe('page')
        ->and($parameters[0]->required)->toBeFalse()
        ->and($parameters[0]->schema->minimum)->toBe(1.0);
});

// Two media types over one schema are not a conflict, and the contract says so
// by carrying both keys. Which of them a generated rule set reads, and what a
// second *different* schema costs, is a question about generation.
it('keeps one entry per media type a body declares', function (): void {
    $body = extractionFrom('bodies.yaml')->operations[0]->requestBody;

    expect($body?->mediaTypes())->toBe(['application/json', 'application/x-www-form-urlencoded'])
        ->and($body?->required)->toBeTrue()
        ->and($body?->content['application/json']->required)->toBe(['street']);
});

// A body declaring no readable schema says nothing this package can generate
// from, and an empty RequestBody would read as "a body with no constraints"
// rather than as the silence it is.
it('carries no body when the document declares one with nothing readable under it', function (): void {
    expect(extractionFrom('bodies.yaml')->operations[1]->requestBody)->toBeNull();
});

// `requestBody.required: false` is a statement about the whole payload, never
// about a property inside it — the schema's own required list is untouched.
it('separates an optional body from the properties it requires', function (): void {
    $body = extractionFrom('bodies.yaml')->operations[2]->requestBody;

    expect($body?->required)->toBeFalse()
        ->and($body?->content['application/json']->required)->toBe(['street']);
});

// An operation that declares no body at all, which is most of them.
it('carries no body when the document declares none', function (): void {
    $operation = extractionFrom('operations.yaml')->operations[0];

    expect($operation->requestBody)->toBeNull()
        ->and($operation->queryParameters)->toBe([]);
});

// Responses are keyed by what the document wrote, not by what this package
// would like them to be: `2XX` ranges and `default` are statuses too, and
// deciding which one answers a given request is a question about serving a
// response rather than about reading a contract.
it('carries the status the document wrote, as the string it wrote', function (): void {
    $statuses = array_map(
        static fn (Response $response): string => $response->status,
        extractionFrom('responses.yaml')->operations[0]->responses
    );

    expect($statuses)->toBe(['200', '204', '4XX', 'default']);
});

// The position this card took rather than an accident of the walk: a `204` is a
// promise, and dropping it for want of a schema would leave nothing able to
// tell "answers with no body" from "never declared".
it('keeps a declared status that carries no body', function (): void {
    $operation = extractionFrom('responses.yaml')->operations[0];

    expect($operation->response('204')?->content)->toBe([])
        ->and($operation->response('204')?->mediaTypes())->toBe([])
        ->and($operation->response('default')?->content)->toBe([]);
});

it('normalizes a response schema per media type', function (): void {
    $ok = extractionFrom('responses.yaml')->operations[0]->response('200');

    expect($ok?->mediaTypes())->toBe(['application/json', 'text/csv'])
        ->and($ok?->content['application/json']->types)->toBe([SchemaType::Array])
        ->and($ok?->content['application/json']->items?->properties)->toHaveKey('title')
        ->and($ok?->content['text/csv']->types)->toBe([SchemaType::String]);
});

// A status nobody declared has no response, and asking for one says so rather
// than handing back an empty one that would read as "declared, no body".
it('has no response for a status the document never declared', function (): void {
    expect(extractionFrom('responses.yaml')->operations[0]->response('418'))->toBeNull();
});

// The parser invents a contentType for a part the author left bare (a binary is
// `application/octet-stream`, a primitive `text/plain`), and only for an inline
// schema. The contract carries what the document wrote and nothing else, so the
// next reader can tell "the author named a type" from "the parser filled in".
it('reads encoding contentType from the raw document', function (): void {
    $body = extractionFrom('encoding.yaml')->operations[0]->requestBody;

    // `caption` and `document` write no `contentType`, so they have no entry, and no other media type has one.
    expect($body?->encoding)->toBe(['multipart/form-data' => ['avatar' => 'image/png, image/jpeg']]);
});

// The default `contentType` of a part is the one thing a 3.0 and a 3.1 spelling of a part mean differently, so the
// strategy of the document's own version hands the body its table, and the body never says which version it was.
it('carries the encoding defaults of its version on a request body', function (string $fixture, VersionStrategy $strategy): void {
    $body = extractionFrom($fixture)->operations[0]->requestBody;

    expect($body?->encodingDefaults)->toEqual($strategy->encodingDefaults())
        ->and($body?->encodingDefaults)->not->toEqual(new EncodingDefaults);
})->with([
    'a 3.0 document' => ['doctor-encoding-defaults-3.0.yaml', new OpenApi30Strategy],
    'a 3.1 document' => ['doctor-encoding-defaults-3.1.yaml', new OpenApi31Strategy],
]);

// A `contentType` that is not a string is still something the author wrote, so it stays in the contract as written
// (a rule can then say it read nothing from it), where dropping it would make the part look as if it wrote none. A
// `null` is a key with nothing in it, which writes none.
it('reads encoding contentType from the raw document, a value that is not a string included', function (): void {
    $body = extractionFrom('encoding.yaml')->operations[3]->requestBody;

    expect($body?->encoding)->toBe(['multipart/form-data' => ['avatar' => ['image/png', 'image/jpeg'], 'caption' => 5]]);
});

// The one path where reading the raw document could read nothing: the request body is a reference, and `encoding` sits
// where it lands, in this document or in another file.
it('reads encoding under a request body behind a $ref', function (string $operation): void {
    $operations = extractionFrom('encoding-ref/spec.yaml')->operations;
    $body = array_values(array_filter($operations, static fn ($candidate): bool => $candidate->operationId === $operation))[0]->requestBody;

    expect($body?->encoding)->toBe(['multipart/form-data' => ['avatar' => 'image/png, image/jpeg']]);
})->with([
    'in the document' => ['uploadLocal'],
    'in another file' => ['uploadFromFile'],
]);

it('carries no encoding for a body that writes none', function (): void {
    expect(extractionFrom('encoding.yaml')->operations[2]->requestBody?->encoding)->toBe([]);
});

it('reads an inline and a referenced body with the same encoding', function (): void {
    $operations = extractionFrom('encoding.yaml')->operations;

    expect($operations[1]->requestBody?->encoding)
        ->toBe($operations[0]->requestBody?->encoding)
        ->toBe(['multipart/form-data' => ['avatar' => 'image/png, image/jpeg']]);
});

/**
 * The operations of a document whose `POST /things` writes the given body schema and answers `201` with the given
 * response schema, written to a temporary file because one key is all that changes between the cases.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $response
 * @param  array<string, mixed>  $components  `components.schemas`
 * @return list<Operation>
 */
function extractMapDocument(array $body, array $components = [], string $version = '3.1.0', array $response = ['type' => 'string']): array
{
    $document = [
        'openapi' => $version,
        'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['post' => [
            'operationId' => 'createThing',
            'requestBody' => ['content' => ['application/json' => ['schema' => $body]]],
            'responses' => ['201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => $response]]]],
        ]]],
    ];

    if ($components !== []) {
        $document['components'] = ['schemas' => $components];
    }

    $path = sys_get_temp_dir().'/lsf-map-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($path, Yaml::dump($document, 12));

    try {
        return extractDocumentAt($path);
    } finally {
        unlink($path);
    }
}

// The keyword carries a schema as well as a boolean: `additionalProperties` keeps its type and stays `null`, and the
// schema sits beside it, so a reader of the boolean never has to tell the two apart.
it('carries a schema written under additionalProperties into the contract', function (
    string $version,
    array $value,
    string $where,
    ?string $name,
    ?string $source,
): void {
    $map = ['type' => 'object', 'additionalProperties' => $value];
    $wrapped = match ($where) {
        'body' => $map,
        'property' => ['type' => 'object', 'properties' => ['meta' => $map]],
        default => ['type' => 'string'],
    };
    $operation = extractMapDocument(
        $wrapped,
        ['Tag' => ['type' => 'string', 'maxLength' => 5]],
        $version,
        $where === 'response' ? $map : ['type' => 'string'],
    )[0];

    $schema = match ($where) {
        'body' => $operation->requestBody?->content['application/json'],
        'property' => $operation->requestBody?->content['application/json']->properties['meta'],
        default => $operation->response('201')?->content['application/json'],
    };

    expect($schema?->additionalProperties)->toBeNull()
        ->and($schema?->additionalPropertiesSchema)->toBeInstanceOf(Schema::class)
        ->and($schema?->additionalPropertiesSchema?->name)->toBe($name)
        ->and($schema?->additionalPropertiesSchema?->source)->toBe($source)
        ->and($schema?->additionalPropertiesSchema?->types)->toBe([SchemaType::String])
        ->and($schema?->additionalPropertiesSchema?->maxLength)->toBe($name === null ? null : 5);
})->with(function (): Generator {
    foreach (['3.0.3', '3.1.0'] as $version) {
        foreach (['body', 'property', 'response'] as $where) {
            yield "inline, {$version}, {$where}" => [$version, ['type' => 'string'], $where, null, null];
            yield "a \$ref, {$version}, {$where}" => [$version, ['$ref' => '#/components/schemas/Tag'], $where, 'Tag', '#/components/schemas/Tag'];
        }
    }
});

it('carries no value schema for additionalProperties written as a boolean', function (bool $written): void {
    $body = extractMapDocument(['type' => 'object', 'additionalProperties' => $written])[0]->requestBody?->content['application/json'];

    expect($body?->additionalProperties)->toBe($written)
        ->and($body?->additionalPropertiesSchema)->toBeNull();
})->with([true, false]);

// A schema that closes on itself through the keyword must end in a marker at the second level: the walk follows the
// value schema like any child, and the position it is written at is what it knows it has already been in.
it('cuts a recursion that closes through additionalProperties', function (string $version): void {
    $body = extractMapDocument(
        ['$ref' => '#/components/schemas/Node'],
        ['Node' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Node']]],
        $version,
    )[0]->requestBody?->content['application/json'];

    $inner = $body?->additionalPropertiesSchema;

    expect($body?->name)->toBe('Node')
        ->and($inner?->name)->toBe('Node')
        ->and($inner?->recursesTo)->toBe('#/components/schemas/Node')
        ->and($inner?->additionalPropertiesSchema)->toBeNull();
})->with(['3.0.3', '3.1.0']);
