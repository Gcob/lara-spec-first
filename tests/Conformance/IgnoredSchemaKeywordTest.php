<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Generation\FormRequestEmitter;
use Gcob\LaraSpecFirst\Generation\InputDtoEmitter;
use Symfony\Component\Yaml\Yaml;

// The equivalence class behind `spec:doctor`'s report on request schemas: a
// keyword the support matrix marks `Ignored` never reaches a generator, so the
// only place it can be noticed is the reading itself, which is where it is
// collected and where its position is still known.
//
// Partitioned by what decides the answer rather than by keyword: whether the
// keyword is on the closed list, where the node carrying it is written, and
// whether the value is data or a schema. The list itself has one case per row of
// the matrix, since each row is its own claim.
//
// @see docs/guide/openapi-support.md — "Schemas"
// @see AGENTS.md — "Automated tests are required"

/**
 * Read a document whose `/things` `POST` writes the given body schema and `query` parameter schemas.
 *
 * Built in a temporary directory for the reason the refused-construct suite gives: what changes between cases is one
 * key, and a fixture file each would bury the ones that say something.
 *
 * @param  array<string, mixed>  $body  the schema written under `application/json`
 * @param  array<string, mixed>  $components  `components.schemas`, for the cases that reach a schema by `$ref`
 * @return list<Operation>
 */
function extractIgnoredKeywordDocument(array $body, array $components = [], string $version = '3.1.0'): array
{
    $document = [
        'openapi' => $version,
        'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'post' => [
                    'operationId' => 'createThing',
                    'requestBody' => ['content' => ['application/json' => ['schema' => $body]]],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
            ],
        ],
    ];

    if ($components !== []) {
        $document['components'] = ['schemas' => $components];
    }

    $path = sys_get_temp_dir().'/lsf-ignored-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($path, Yaml::dump($document, 10));

    try {
        return extractDocumentAt($path);
    } finally {
        unlink($path);
    }
}

function ignoredBody(Operation $operation): Schema
{
    expect($operation->requestBody)->not->toBeNull();
    assert($operation->requestBody !== null);

    return $operation->requestBody->content['application/json'];
}

const IGNORED_BODY_AT = '#/paths/~1things/post/requestBody/content/application~1json/schema';

// Class one: every keyword on the matrix's `Ignored` schema rows is collected,
// under its own name, at the position it is written.
it('collects each keyword the matrix ignores, at the position it is written', function (string $keyword, mixed $value): void {
    $body = ignoredBody(extractIgnoredKeywordDocument(['type' => 'object', $keyword => $value])[0]);

    expect($body->ignored)->toBe([$keyword => IGNORED_BODY_AT.'/'.$keyword]);
})->with([
    'default' => ['default', 'new'],
    'deprecated' => ['deprecated', true],
    '$defs' => ['$defs', ['Pet' => ['type' => 'string']]],
    '$anchor' => ['$anchor', 'thing'],
    'minProperties' => ['minProperties', 1],
    'maxProperties' => ['maxProperties', 3],
    'patternProperties' => ['patternProperties', ['^x-' => ['type' => 'string']]],
    'propertyNames' => ['propertyNames', ['pattern' => '^[a-z]+$']],
    'dependentSchemas' => ['dependentSchemas', ['a' => ['required' => ['b']]]],
    'unevaluatedProperties' => ['unevaluatedProperties', false],
    'prefixItems' => ['prefixItems', [['type' => 'string']]],
    'contains' => ['contains', ['type' => 'string']],
    'minContains' => ['minContains', 1],
    'maxContains' => ['maxContains', 2],
    'unevaluatedItems' => ['unevaluatedItems', false],
    'oneOf' => ['oneOf', [['type' => 'string'], ['type' => 'integer']]],
    'anyOf' => ['anyOf', [['type' => 'string'], ['type' => 'integer']]],
    'not' => ['not', ['type' => 'string']],
    'discriminator' => ['discriminator', ['propertyName' => 'kind']],
    'if' => ['if', ['properties' => ['a' => ['const' => 1]]]],
    'then' => ['then', ['required' => ['b']]],
    'else' => ['else', ['required' => ['c']]],
    'contentSchema' => ['contentSchema', ['type' => 'object']],
]);

// Class two: a keyword a generator reads, or one nobody owes a report about, is
// not on the list. `additionalProperties` is read in all three of its spellings
// (`false`, `true` and a schema, which the contract carries), so none is ignored.
it('collects nothing from a keyword a rule is built from', function (string $keyword, mixed $value): void {
    $body = ignoredBody(extractIgnoredKeywordDocument(['type' => 'object', $keyword => $value])[0]);

    expect($body->ignored)->toBe([]);
})->with([
    'additionalProperties: false' => ['additionalProperties', false],
    'additionalProperties: true' => ['additionalProperties', true],
    'additionalProperties as a schema' => ['additionalProperties', ['type' => 'string']],
    'required' => ['required', ['a']],
    'description' => ['description', 'what it is'],
    '$comment' => ['$comment', 'for a reader'],
    'examples' => ['examples', [['a' => 1]]],
]);

// Class three: where the node is written decides the position, not where it is
// reached from. A property is a step below its parent, and a component is where
// the author wrote it, in whichever file.
it('names the position of a keyword written on a nested property', function (): void {
    $body = ignoredBody(extractIgnoredKeywordDocument([
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'default' => 'new', 'deprecated' => true],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'default' => 'x']],
        ],
    ])[0]);

    expect($body->properties['status']->ignored)->toBe([
        'default' => IGNORED_BODY_AT.'/properties/status/default',
        'deprecated' => IGNORED_BODY_AT.'/properties/status/deprecated',
    ])->and($body->properties['tags']->items?->ignored)->toBe([
        'default' => IGNORED_BODY_AT.'/properties/tags/items/default',
    ]);
});

it('names the component a keyword is written in when the schema is reached by reference', function (): void {
    $body = ignoredBody(extractIgnoredKeywordDocument(
        ['$ref' => '#/components/schemas/Thing'],
        ['Thing' => ['type' => 'object', 'oneOf' => [['type' => 'object']]]],
    )[0]);

    expect($body->ignored)->toBe(['oneOf' => '#/components/schemas/Thing/oneOf']);
});

// A schema written under `additionalProperties` is walked like any child, so a keyword nothing reads that it writes is
// reported where it is written: inside the value schema, and behind a `$ref` at the component's own position.
it('names the position of a keyword written inside an additionalProperties schema', function (): void {
    $inline = ignoredBody(extractIgnoredKeywordDocument([
        'type' => 'object',
        'additionalProperties' => ['type' => 'string', 'default' => 'x'],
    ])[0]);
    $behindRef = ignoredBody(extractIgnoredKeywordDocument(
        ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Tag']],
        ['Tag' => ['type' => 'string', 'deprecated' => true]],
    )[0]);

    expect($inline->ignored)->toBe([])
        ->and($inline->additionalPropertiesSchema?->ignored)->toBe(['default' => IGNORED_BODY_AT.'/additionalProperties/default'])
        ->and($behindRef->additionalPropertiesSchema?->ignored)->toBe(['deprecated' => '#/components/schemas/Tag/deprecated']);
});

// Class four: a value that is data is data. A `$ref` written in a `default` is a
// literal, and following it would have the keyword reported at a position the
// document never wrote.
it('does not follow a reference written inside a keyword that holds data', function (): void {
    $body = ignoredBody(extractIgnoredKeywordDocument(
        ['type' => 'object', 'default' => ['$ref' => '#/components/schemas/Other']],
        ['Other' => ['type' => 'string']],
    )[0]);

    expect($body->ignored)->toBe(['default' => IGNORED_BODY_AT.'/default']);
});

// Class five: the same contract at 3.0 reads the same, and a keyword the 3.0
// vocabulary has (`default`, `deprecated`, `oneOf`, `discriminator`) is found.
it('collects the same keywords from a 3.0 document', function (): void {
    $body = ignoredBody(extractIgnoredKeywordDocument([
        'type' => 'object',
        'default' => [],
        'oneOf' => [['type' => 'object']],
        'discriminator' => ['propertyName' => 'kind'],
    ], version: '3.0.3')[0]);

    expect(array_keys($body->ignored))->toBe(['default', 'oneOf', 'discriminator']);
});

/**
 * Every file the build emits for a contract whose two operations write one inline shape, with or without an `Ignored`
 * keyword in it at every depth: the request of each, and every input DTO.
 *
 * `createUser` nests the shape as `address` and `createUserAddress` sends it as the body, so both give
 * `CreateUserAddressInputDto`, and the two copies are written at two positions.
 *
 * @return array{emitted: array<string, string>, ignored: int}
 */
function emitForTwoCopiesOfOneShape(bool $withIgnored): array
{
    $extra = static fn (array $keywords): array => $withIgnored ? $keywords : [];

    $street = ['type' => 'string', 'minLength' => 2, ...$extra(['default' => 'main', 'not' => ['type' => 'integer']])];
    $address = [
        'type' => 'object',
        'required' => ['street'],
        'properties' => ['street' => $street],
        ...$extra(['deprecated' => true, 'minProperties' => 1]),
    ];
    $operation = static fn (string $id, array $body): array => ['post' => [
        'operationId' => $id,
        'requestBody' => ['content' => ['application/json' => ['schema' => $body]]],
        'responses' => ['201' => ['description' => 'Created']],
    ]];

    $path = sys_get_temp_dir().'/lsf-golden-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($path, Yaml::dump([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
        'paths' => [
            '/users' => $operation('createUser', ['type' => 'object', 'properties' => ['address' => $address]]),
            '/addresses' => $operation('createUserAddress', $address),
        ],
    ], 12));

    try {
        $operations = extractDocumentAt($path);
    } finally {
        unlink($path);
    }

    $planned = plannedRequests($operations);
    $emitted = [];

    foreach ($planned['requests'] as $request) {
        $file = (new FormRequestEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($request);
        $emitted[$file->relativePath] = $file->contents;
    }

    foreach ($planned['plan']->dtos as $dto) {
        $file = (new InputDtoEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($dto);
        $emitted[$file->relativePath] = $file->contents;
    }

    $count = 0;
    $walk = static function (Schema $schema) use (&$walk, &$count): void {
        $count += count($schema->ignored);
        array_map($walk, $schema->properties);
    };

    foreach ($operations as $operation) {
        $operation->requestBody !== null && array_map($walk, $operation->requestBody->content);
    }

    return ['emitted' => $emitted, 'ignored' => $count];
}

// Class six: a keyword nothing reads changes nothing that is generated. The guard behind the shared DTO name, which
// compared two inline copies as written and refused a contract that the same one without a `default` built.
it('emits the same files with and without the keywords the matrix ignores', function (): void {
    $without = emitForTwoCopiesOfOneShape(false);
    $with = emitForTwoCopiesOfOneShape(true);

    // The case would pass for the wrong reason if the keywords never reached the contract.
    expect($without['ignored'])->toBe(0)
        ->and($with['ignored'])->toBeGreaterThan(4)
        ->and(array_keys($with['emitted']))->toContain('Data/CreateUserAddressInputDto.php')
        ->and($with['emitted'])->toBe($without['emitted']);
});

/**
 * Every file the build emits for a contract that reaches one `Address` twice: as the body of its own operation, and as
 * the `address` of another, where it is written as a `$ref` with or without `Ignored` keywords beside it.
 *
 * @return array{emitted: array<string, string>, ignored: list<string>}
 */
function emitForARefWithKeywordsBeside(bool $withIgnored): array
{
    $beside = $withIgnored
        ? ['deprecated' => true, 'default' => ['street' => 'Main'], 'oneOf' => [['type' => 'object']], 'x-max-depth' => 3]
        : [];
    $operation = static fn (string $id, array $body): array => ['post' => [
        'operationId' => $id,
        'requestBody' => ['content' => ['application/json' => ['schema' => $body]]],
        'responses' => ['201' => ['description' => 'Created']],
    ]];

    $path = sys_get_temp_dir().'/lsf-ref-beside-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($path, Yaml::dump([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
        'paths' => [
            '/users' => $operation('createUser', ['type' => 'object', 'properties' => [
                'address' => ['$ref' => '#/components/schemas/Address', ...$beside],
            ]]),
            '/addresses' => $operation('createAddress', ['$ref' => '#/components/schemas/Address']),
        ],
        'components' => ['schemas' => ['Address' => [
            'type' => 'object',
            'required' => ['street'],
            'properties' => ['street' => ['type' => 'string', 'minLength' => 2]],
        ]]],
    ], 12));

    try {
        $operations = extractDocumentAt($path);
    } finally {
        unlink($path);
    }

    $planned = plannedRequests($operations);
    $emitted = [];

    foreach ($planned['requests'] as $request) {
        $file = (new FormRequestEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($request);
        $emitted[$file->relativePath] = $file->contents;
    }

    foreach ($planned['plan']->dtos as $dto) {
        $file = (new InputDtoEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($dto);
        $emitted[$file->relativePath] = $file->contents;
    }

    $ignored = [];
    $walk = static function (Schema $schema) use (&$walk, &$ignored): void {
        array_push($ignored, ...array_values($schema->ignored));
        array_map($walk, [...array_values($schema->properties), ...$schema->allOf]);
    };

    foreach ($operations as $operation) {
        $operation->requestBody !== null && array_map($walk, $operation->requestBody->content);
    }

    return ['emitted' => $emitted, 'ignored' => $ignored];
}

// Class seven: the same document written with and without the keywords the matrix ignores beside a `$ref`, which 3.1
// reads and 3.0 never did. They are carried on the schema as positions and nothing else, so what is generated does not
// change, and one `Address` is still one class however many keywords are written beside the `$ref` that reaches it.
it('records a keyword beside a $ref the same way in a document with and without ignored keywords', function (): void {
    $without = emitForARefWithKeywordsBeside(false);
    $with = emitForARefWithKeywordsBeside(true);
    $at = '#/paths/~1users/post/requestBody/content/application~1json/schema/properties/address/';

    // The case would pass for the wrong reason if the keywords never reached the contract.
    expect($without['ignored'])->toBe([])
        ->and($with['ignored'])->toBe([$at.'default', $at.'deprecated', $at.'oneOf', $at.'x-max-depth'])
        ->and(array_keys($with['emitted']))->toContain('Data/AddressInputDto.php')
        ->and($with['emitted'])->toBe($without['emitted']);
});
