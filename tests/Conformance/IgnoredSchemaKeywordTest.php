<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Schema;
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
    'additionalProperties as a schema' => ['additionalProperties', ['type' => 'string']],
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
// not on the list. `additionalProperties` is the one keyword that is a boolean
// in one spelling and a schema in the other, and only the schema is ignored.
it('collects nothing from a keyword a rule is built from', function (string $keyword, mixed $value): void {
    $body = ignoredBody(extractIgnoredKeywordDocument(['type' => 'object', $keyword => $value])[0]);

    expect($body->ignored)->toBe([]);
})->with([
    'additionalProperties: false' => ['additionalProperties', false],
    'additionalProperties: true' => ['additionalProperties', true],
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
