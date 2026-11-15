<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\AllOfMerger;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\InRule;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\RemoteReferences\RemoteReferenceFetcher;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Illuminate\Http\Client\Factory;
use Symfony\Component\Yaml\Yaml;

// What a `$ref` means in a 3.1 schema, and what it still means in a 3.0 one.
//
// JSON Schema 2020-12 lets a keyword sit beside a `$ref`, and the two apply together: `{$ref: Code, maxLength: 3}` is
// the `Code` schema and a ceiling of three. The 3.0 specification says the opposite, that everything beside a `$ref`
// is ignored, so the same line is two contracts and the version decides. The parser drops every sibling at both, so the
// reading of them is this package's, and what is pinned here is that none is dropped without a word.
//
// Partitioned by what decides the answer: the version, what the siblings add (a constraint, an annotation, a schema,
// a refusal), how many `$ref`s the chain has, and where the schema is read from (a body, a response, a query
// parameter). The documents are built in a temporary directory because what changes between cases is one key.
//
// @see docs/guide/openapi-support.md — "Schemas"
// @see AGENTS.md — "Automated tests are required"

const SIBLING_CODE = ['Code' => ['type' => 'string']];

/**
 * A document written to a temporary directory, read the way a build reads it.
 *
 * @param  array<string, mixed>  $paths  the whole `paths` object
 * @param  array<string, mixed>  $components  `components.schemas`
 * @param  array<string, array<string, mixed>>  $files  other files of the same directory, by relative name
 */
function readSiblingDocument(array $paths, array $components = [], string $version = '3.1.0', array $files = []): ReadOutcome
{
    $directory = sys_get_temp_dir().'/lsf-siblings-'.bin2hex(random_bytes(6));
    mkdir($directory);

    $document = [
        'openapi' => $version,
        'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
        'paths' => $paths,
    ];

    if ($components !== []) {
        $document['components'] = ['schemas' => $components];
    }

    file_put_contents($directory.'/spec.yaml', Yaml::dump($document, 12));

    foreach ($files as $name => $contents) {
        file_put_contents($directory.'/'.$name, Yaml::dump($contents, 12));
    }

    try {
        return ReadOutcome::read(new SpecDocumentReader, $directory.'/spec.yaml');
    } finally {
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
}

/**
 * One path that is a `POST` with the given body schema.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function siblingPostPath(array $schema, string $id = 'createThing'): array
{
    return ['post' => [
        'operationId' => $id,
        'requestBody' => ['content' => ['application/json' => ['schema' => $schema]]],
        'responses' => ['201' => ['description' => 'Created']],
    ]];
}

/**
 * The operations of a body written as `{type: object, properties: {field: <schema>}}`, thrown at the first fault.
 *
 * @param  array<string, mixed>  $field
 * @param  array<string, mixed>  $components
 * @return list<Operation>
 */
function readSiblingField(array $field, array $components = SIBLING_CODE, string $version = '3.1.0'): array
{
    $outcome = readSiblingDocument(
        ['/things' => siblingPostPath(['type' => 'object', 'properties' => ['field' => $field]])],
        $components,
        $version,
    );

    if (! $outcome->isClean()) {
        throw $outcome->faults[0];
    }

    return $outcome->operations;
}

/**
 * The schema the body writes as `field`, as the build sees it before `allOf` is folded.
 *
 * @param  list<Operation>  $operations
 */
function siblingFieldOf(array $operations): Schema
{
    $body = $operations[0]->requestBody;
    assert($body !== null);

    return $body->content['application/json']->properties['field'];
}

/**
 * The same schema with its `allOf` folded, which is what a rule set is built from.
 */
function siblingMerged(Schema $field): Schema
{
    return AllOfMerger::merge($field, 'createThing', 'field');
}

const SIBLING_FIELD_AT = '#/paths/~1things/post/requestBody/content/application~1json/schema/properties/field';

// Class one: a constraint beside a `$ref` holds together with the referenced schema, at 3.1. Each row is one keyword
// the rule set reads, beside a target of the type it applies to, and the value it reads back from the folded field.
it('honors a constraint written beside a $ref at 3.1', function (
    array $siblings,
    array $target,
    string $keyword,
    mixed $expected,
): void {
    $field = siblingMerged(siblingFieldOf(readSiblingField(['$ref' => '#/components/schemas/Target', ...$siblings], ['Target' => $target])));

    expect($field->$keyword)->toEqual($expected);
})->with([
    'maxLength' => [['maxLength' => 3], ['type' => 'string'], 'maxLength', 3],
    'minLength' => [['minLength' => 2], ['type' => 'string'], 'minLength', 2],
    'pattern' => [['pattern' => '^[A-Z]+$'], ['type' => 'string'], 'pattern', '^[A-Z]+$'],
    'minimum' => [['minimum' => 1], ['type' => 'integer'], 'minimum', 1.0],
    'enum' => [['enum' => ['a', 'b']], ['type' => 'string'], 'enum', ['a', 'b']],
    'required' => [['required' => ['city']], ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]], 'required', ['city']],
    'readOnly' => [['readOnly' => true], ['type' => 'string'], 'readOnly', true],
]);

// The card's own case, end to end: the rule the generated request carries for it.
it('adds the ceiling written beside a $ref to the rules of the field', function (): void {
    $operations = readSiblingField(['$ref' => '#/components/schemas/Code', 'maxLength' => 3]);

    expect(RuleSetBuilder::for($operations[0])->rules['field'])->toBe(['sometimes', 'string', 'max:3']);
});

// Class two: 3.0 says everything beside a `$ref` is ignored, and the package says it the same way.
it('ignores a keyword written beside a $ref at 3.0, as the specification does', function (): void {
    $field = siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Code', 'maxLength' => 3],
        SIBLING_CODE,
        '3.0.3',
    ));

    expect($field->maxLength)->toBeNull()
        ->and($field->allOf)->toBe([])
        ->and($field->name)->toBe('Code')
        ->and($field->ignored)->toBe([]);
});

// Class three: the reading is the `allOf` of one branch the 3.0 spelling writes, which is the whole definition.
it('reads a $ref with siblings as the one-branch allOf it means', function (): void {
    $beside = siblingFieldOf(readSiblingField(['$ref' => '#/components/schemas/Code', 'maxLength' => 3, 'minLength' => 1]));
    $spelled = siblingFieldOf(readSiblingField(
        ['allOf' => [['$ref' => '#/components/schemas/Code']], 'maxLength' => 3, 'minLength' => 1],
        SIBLING_CODE,
        '3.0.3',
    ));

    expect($beside->shapeKey())->toBe($spelled->shapeKey())
        ->and($beside->allOf)->toHaveCount(1)
        ->and($beside->allOf[0]->name)->toBe('Code');
});

// Class four: an annotation says nothing a generator reads, so the field is the target and nothing wraps it.
it('returns the referenced schema itself when only annotations sit beside it', function (string $keyword, mixed $value): void {
    $direct = siblingFieldOf(readSiblingField(['$ref' => '#/components/schemas/Code']));
    $beside = siblingFieldOf(readSiblingField(['$ref' => '#/components/schemas/Code', $keyword => $value]));

    expect($beside->shapeKey())->toBe($direct->shapeKey())
        ->and($beside->allOf)->toBe([])
        ->and($beside->name)->toBe('Code');
})->with([
    'description' => ['description', 'what it is'],
    'title' => ['title', 'A code'],
    'deprecated' => ['deprecated', true],
    'default' => ['default', 'XYZ'],
    '$comment' => ['$comment', 'for a reader'],
]);

it('records deprecated and default beside a $ref at the position they are written', function (): void {
    $field = siblingFieldOf(readSiblingField([
        '$ref' => '#/components/schemas/Code',
        'deprecated' => true,
        'default' => 'XYZ',
    ]));

    expect($field->ignored)->toBe([
        'default' => SIBLING_FIELD_AT.'/default',
        'deprecated' => SIBLING_FIELD_AT.'/deprecated',
    ]);
});

// Class five: a sibling that holds schemas is read like the same keyword anywhere else.
it('reads properties, items and allOf written inline beside a $ref', function (): void {
    $object = ['Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]];
    $list = ['Tags' => ['type' => 'array', 'items' => ['type' => 'string']]];

    $properties = siblingMerged(siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Base', 'properties' => ['label' => ['type' => 'string', 'maxLength' => 2]]],
        $object,
    )));
    $items = siblingMerged(siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Tags', 'items' => ['type' => 'string', 'maxLength' => 4]],
        $list,
    )));
    $allOf = siblingMerged(siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Base', 'allOf' => [['required' => ['id']]]],
        $object,
    )));

    expect(array_keys($properties->properties))->toEqualCanonicalizing(['id', 'label'])
        ->and($properties->properties['label']->maxLength)->toBe(2)
        ->and($items->items?->maxLength)->toBe(4)
        ->and($allOf->required)->toBe(['id']);
});

// Class six: an alias chain writes its siblings at every hop, and each is read where it is written.
it('reads the siblings at every hop of an alias chain', function (): void {
    $field = siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Outer', 'pattern' => '^[A-Z]+$'],
        [
            'Outer' => ['$ref' => '#/components/schemas/Inner', 'maxLength' => 10],
            'Inner' => ['$ref' => '#/components/schemas/Code', 'minLength' => 2],
            ...SIBLING_CODE,
        ],
    ));
    $merged = siblingMerged($field);

    expect($merged->maxLength)->toBe(10)
        ->and($merged->minLength)->toBe(2)
        ->and($merged->pattern)->toBe('^[A-Z]+$')
        ->and($merged->types)->toBe([SchemaType::String]);
});

it('names the position of a sibling in the file that wrote it', function (): void {
    $outcome = readSiblingDocument(
        ['/things' => siblingPostPath(['type' => 'object', 'properties' => [
            'field' => ['$ref' => './schemas.yaml#/Alias'],
        ]])],
        files: ['schemas.yaml' => [
            'Alias' => ['$ref' => '#/Code', 'maxLength' => 3, 'deprecated' => true],
            'Code' => ['type' => 'string'],
        ]],
    );

    expect($outcome->faults)->toBe([]);

    $field = siblingFieldOf($outcome->operations);

    expect($field->ignored)->toBe(['deprecated' => 'schemas.yaml#/Alias/deprecated'])
        ->and(siblingMerged($field)->maxLength)->toBe(3);
});

// Class seven: what is refused beside a `$ref` is what is refused anywhere, and a keyword that would make the read
// wrong rather than missing keeps being refused.
it('refuses an $id or a dynamic reference written beside a $ref', function (string $keyword, string $message): void {
    $read = fn (): array => readSiblingField(['$ref' => '#/components/schemas/Code', $keyword => '#thing']);

    expect($read)->toThrow(RejectedConstructException::class, $message);
})->with([
    '$id' => ['$id', 'declares `$id`'],
    '$dynamicRef' => ['$dynamicRef', 'writes `$dynamicRef`'],
    '$dynamicAnchor' => ['$dynamicAnchor', 'writes `$dynamicAnchor`'],
    '$recursiveRef' => ['$recursiveRef', 'writes `$recursiveRef`'],
    '$recursiveAnchor' => ['$recursiveAnchor', 'writes `$recursiveAnchor`'],
]);

it('refuses a sibling that contradicts the referenced schema the way allOf does', function (
    array $siblings,
    array $target,
): void {
    $operations = readSiblingField(['$ref' => '#/components/schemas/Target', ...$siblings], ['Target' => $target]);

    expect(fn () => RuleSetBuilder::for($operations[0]))
        ->toThrow(ConflictingInputException::class, 'beside a `$ref`');
})->with([
    'a closed object over a declared property' => [
        ['additionalProperties' => false],
        ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ],
    'a type the target does not have' => [['type' => 'integer'], ['type' => 'string']],
]);

// A `$ref` written inside the keywords beside another is read where it is written: through the same walk as every other
// schema, against the file that wrote it. The four rows are the places the parser keeps no trace of one.
it('reads a $ref nested inside a keyword beside a $ref', function (array $siblings, string $path): void {
    $operations = readSiblingField(
        ['$ref' => '#/components/schemas/Base', ...$siblings],
        ['Base' => ['type' => 'object'], 'User' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 5]]]],
    );
    $field = siblingMerged(siblingFieldOf($operations));
    $user = match ($path) {
        'properties' => $field->properties['owner'],
        'items' => $field->items,
        'allOf' => $field,
        default => $field->properties['owner']->properties['address'],
    };

    expect($user?->properties['name']->maxLength)->toBe(5);
})->with([
    'in properties' => [['properties' => ['owner' => ['$ref' => '#/components/schemas/User']]], 'properties'],
    'in items' => [['items' => ['$ref' => '#/components/schemas/User']], 'items'],
    'in allOf' => [['allOf' => [['$ref' => '#/components/schemas/User']]], 'allOf'],
    'two levels down' => [['properties' => ['owner' => ['type' => 'object', 'properties' => [
        'address' => ['$ref' => '#/components/schemas/User'],
    ]]]], 'two levels'],
]);

// A malformed sibling used to be dropped with the rest. Now it is read, and what the parser cannot build is the
// document's fault, for that operation only: the read does not crash and the operations around it are built.
it('refuses a malformed sibling as a fault in the document, without crashing the read', function (): void {
    $outcome = readSiblingDocument([
        '/broken' => siblingPostPath(['type' => 'object', 'properties' => [
            'field' => ['$ref' => '#/components/schemas/Code', 'items' => 'x'],
        ]], 'createBroken'),
        '/fine' => siblingPostPath(['type' => 'object', 'properties' => [
            'field' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 3],
        ]], 'createFine'),
    ], SIBLING_CODE);

    expect($outcome->faults)->toHaveCount(1)
        ->and($outcome->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($outcome->faults[0]->getMessage())->toContain(siblingFieldAtOf('/broken'))
        ->and(array_map(static fn (Operation $operation): ?string => $operation->operationId, $outcome->operations))
        ->toBe(['createFine']);
});

/**
 * Where the body of a `POST` at a path writes its `field`.
 */
function siblingFieldAtOf(string $path): string
{
    return '#/paths/'.str_replace('/', '~1', $path).'/post/requestBody/content/application~1json/schema/properties/field';
}

// Class eight: `oneOf`, `anyOf` and `not` are reported wherever they are written, and a `$ref` inside one is no reason
// to refuse the document. Only the keywords the parser hands back raw are refused for one.
it('reports oneOf, anyOf and not holding a $ref beside a $ref instead of refusing them', function (string $keyword, mixed $value): void {
    $field = siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Code', $keyword => $value],
        [...SIBLING_CODE, 'Other' => ['type' => 'integer']],
    ));

    expect($field->ignored)->toBe([$keyword => SIBLING_FIELD_AT.'/'.$keyword])
        ->and($field->allOf)->toBe([])
        ->and($field->name)->toBe('Code');
})->with([
    'oneOf' => ['oneOf', [['$ref' => '#/components/schemas/Other'], ['type' => 'string']]],
    'anyOf' => ['anyOf', [['$ref' => '#/components/schemas/Other'], ['type' => 'string']]],
    'not' => ['not', ['$ref' => '#/components/schemas/Other']],
]);

it('refuses a raw keyword holding a $ref beside a $ref', function (): void {
    $read = fn (): array => readSiblingField(
        ['$ref' => '#/components/schemas/Base', 'prefixItems' => [['$ref' => '#/components/schemas/Base']]],
        ['Base' => ['type' => 'array']],
    );

    expect($read)->toThrow(RejectedConstructException::class, 'writes `prefixItems`');
});

// Class nine: the place the schema is read from does not change what it means.
it('reads siblings beside a $ref in a response schema', function (): void {
    $outcome = readSiblingDocument(['/things' => ['get' => [
        'operationId' => 'listThings',
        'responses' => ['200' => [
            'description' => 'Things',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 3]]],
        ]],
    ]]], SIBLING_CODE);

    expect($outcome->faults)->toBe([]);

    $schema = $outcome->operations[0]->responses[0]->content['application/json'];

    expect(siblingMerged($schema)->maxLength)->toBe(3);
});

it('reads siblings beside a $ref in a query parameter schema', function (): void {
    $outcome = readSiblingDocument(['/things' => ['get' => [
        'operationId' => 'listThings',
        'parameters' => [[
            'name' => 'code',
            'in' => 'query',
            'schema' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 3],
        ]],
        'responses' => ['200' => ['description' => 'Things']],
    ]]], SIBLING_CODE);

    expect($outcome->faults)->toBe([])
        ->and(RuleSetBuilder::for($outcome->operations[0])->rules['code'])->toBe(['sometimes', 'string', 'max:3']);
});

// Class ten: a marker is a `$ref` too. A constraint beside the one that closes a cycle holds at every level the cycle
// is crossed at, and the rules are the ones the `allOf` spelling gives.
it('carries a constraint beside a recursion marker to every level, as the allOf spelling does', function (): void {
    $node = static fn (array $next): array => ['Node' => [
        'type' => 'object',
        'x-max-depth' => 2,
        'properties' => ['label' => ['type' => 'string'], 'next' => $next],
    ]];
    $rules = static function (array $next) use ($node): array {
        $outcome = readSiblingDocument(
            ['/nodes' => siblingPostPath(['$ref' => '#/components/schemas/Node'])],
            $node($next),
        );

        expect($outcome->faults)->toBe([]);

        return RuleSetBuilder::for($outcome->operations[0])->rules;
    };

    $beside = $rules(['$ref' => '#/components/schemas/Node', 'required' => ['label']]);
    $spelled = $rules(['allOf' => [['$ref' => '#/components/schemas/Node'], ['required' => ['label']]]]);

    expect($beside)->toEqual($spelled)
        ->and($beside)->toHaveKey('next.next.label')
        ->and($beside['next'])->toContain('required_array_keys:label');
});

// Class eleven: a name belongs to what the author named, and a constraint beside a `$ref` does not change which schema
// that is. A constraint that adds no shape keeps the target's name; one that does is a schema of its own.
it('keeps a constraint written beside a $ref on a named alias under the target\'s class name', function (): void {
    $field = siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Alias'],
        ['Alias' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 3], ...SIBLING_CODE],
    ));

    expect($field->name)->toBeNull()
        ->and($field->source)->toBeNull()
        ->and($field->allOf)->toHaveCount(1)
        ->and($field->allOf[0]->name)->toBe('Code')
        ->and($field->maxLength)->toBe(3);
});

it('takes the name of the alias when what is written beside the $ref adds a key', function (): void {
    $field = siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Alias'],
        [
            'Alias' => ['$ref' => '#/components/schemas/Node', 'required' => ['id']],
            'Node' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ],
    ));

    expect($field->name)->toBe('Alias')
        ->and($field->source)->toBe('#/components/schemas/Alias')
        ->and($field->allOf[0]->name)->toBe('Node');
});

// D37: a depth is read off the schema a marker points back at, so one written beside a `$ref` would be read by nothing.
// It is reported at the hop that writes it, and the target keeps its own.
it('reports an x-max-depth written beside a $ref at every hop', function (): void {
    $field = siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Tree', 'x-max-depth' => 1],
        [
            'Tree' => ['$ref' => '#/components/schemas/Node', 'x-max-depth' => 5],
            'Node' => ['type' => 'object', 'x-max-depth' => 2, 'properties' => ['id' => ['type' => 'integer']]],
        ],
    ));

    $wrapped = $field;
    $positions = [];

    while ($wrapped->allOf !== [] || $wrapped->ignored !== []) {
        $positions[] = $wrapped->ignored;
        $wrapped = $wrapped->allOf[0] ?? new Schema;
    }

    expect($positions)->toBe([
        ['x-max-depth' => SIBLING_FIELD_AT.'/x-max-depth'],
        ['x-max-depth' => '#/components/schemas/Tree/x-max-depth'],
    ])->and(siblingMerged($field)->maxDepth)->toBe(2);
});

// D42 b: a `$ref` written inside the keywords beside a `$ref` is followed like any other, wherever it is written.
const SIBLING_USER = ['User' => ['type' => 'object', 'properties' => [
    'name' => ['type' => 'string', 'maxLength' => 5],
    'address' => ['$ref' => '#/components/schemas/Address'],
]]];
const SIBLING_ADDRESS = ['Address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string', 'maxLength' => 9]]]];

it('resolves a $ref written inside properties, items and allOf beside a $ref', function (
    array $siblings,
    string $target,
    string $keyword,
    array $expected,
): void {
    $field = siblingMerged(siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Target', ...$siblings],
        ['Target' => ['type' => $target], 'Tag' => ['type' => 'string', 'maxLength' => 4], 'Extra' => ['type' => 'object', 'required' => ['id']]],
    )));

    $read = match ($keyword) {
        'properties' => $field->properties['owner']->maxLength,
        'items' => $field->items?->maxLength,
        default => $field->required,
    };

    expect($read)->toBe($expected[0]);
})->with([
    'a property' => [['properties' => ['owner' => ['$ref' => '#/components/schemas/Tag']]], 'object', 'properties', [4]],
    'the items' => [['items' => ['$ref' => '#/components/schemas/Tag']], 'array', 'items', [4]],
    'a branch of allOf' => [['allOf' => [['$ref' => '#/components/schemas/Extra']]], 'object', 'allOf', [['id']]],
]);

it('honors siblings beside a $ref nested inside a sibling', function (): void {
    $field = siblingMerged(siblingFieldOf(readSiblingField(
        ['$ref' => '#/components/schemas/Base', 'properties' => [
            'owner' => ['$ref' => '#/components/schemas/Code', 'maxLength' => 3, 'deprecated' => true],
        ]],
        ['Base' => ['type' => 'object'], ...SIBLING_CODE],
    )));

    $owner = AllOfMerger::merge($field->properties['owner'], 'createThing', 'owner');

    expect($owner->maxLength)->toBe(3)
        ->and($owner->types)->toBe([SchemaType::String])
        ->and(array_keys($field->properties['owner']->ignored))->toBe(['deprecated']);
});

it('resolves a nested $ref against the file that wrote it', function (): void {
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath('ref-siblings-external/main.yaml'));

    expect($outcome->faults)->toBe([]);

    $body = $outcome->operations[0]->requestBody;
    assert($body !== null);
    $thing = AllOfMerger::merge($body->content['application/json'], 'createThing', 'body');
    $owner = $thing->properties['owner'];

    expect(array_keys($thing->properties))->toEqualCanonicalizing(['id', 'owner'])
        ->and($owner->properties['name']->maxLength)->toBe(5)
        ->and($owner->properties['address']->properties['city']->maxLength)->toBe(9)
        ->and($owner->name)->toBe('user');
});

it('resolves a nested $ref into a vendored remote copy', function (): void {
    $directory = sys_get_temp_dir().'/lsf-siblings-'.bin2hex(random_bytes(6));
    mkdir($directory.'/vendor', 0o777, true);

    $factory = new Factory;
    $factory->preventStrayRequests();
    $factory->fake([
        'https://schemas.example.com/common.yaml' => Yaml::dump(['components' => ['schemas' => [
            'User' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 5],
                'address' => ['$ref' => 'https://schemas.example.com/address.yaml#/components/schemas/Address'],
            ]],
        ]]], 12),
        'https://schemas.example.com/address.yaml' => Yaml::dump(['components' => ['schemas' => SIBLING_ADDRESS]], 12),
    ]);

    file_put_contents($directory.'/spec.yaml', Yaml::dump([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
        'paths' => ['/things' => siblingPostPath(['$ref' => '#/components/schemas/Base', 'properties' => [
            'owner' => ['$ref' => 'https://schemas.example.com/common.yaml#/components/schemas/User'],
        ]])],
        'components' => ['schemas' => ['Base' => ['type' => 'object']]],
    ], 12));

    try {
        $reader = new SpecDocumentReader(remote: new RemoteReferenceGuard(
            ['schemas.example.com'],
            $directory.'/vendor',
            new RemoteReferenceFetcher($factory),
        ));
        $outcome = ReadOutcome::read($reader, $directory.'/spec.yaml', updateRefs: true);
    } finally {
        exec('rm -rf '.escapeshellarg($directory));
    }

    expect($outcome->faults)->toBe([]);

    $owner = siblingMerged(siblingFieldOfBody($outcome->operations))->properties['owner'];

    expect($owner->properties['name']->maxLength)->toBe(5)
        ->and($owner->properties['address']->properties['city']->maxLength)->toBe(9);
});

/**
 * The root schema of the first operation's body.
 *
 * @param  list<Operation>  $operations
 */
function siblingFieldOfBody(array $operations): Schema
{
    $body = $operations[0]->requestBody;
    assert($body !== null);

    return $body->content['application/json'];
}

// A marker is what a walk answers on meeting a schema it is already inside. The hop is inside its own keywords, so a
// `$ref` back at it ends in a marker the first time round, and the wrapper it points at is the alias that wrote them.
it('cuts a recursion that closes through a sibling at the first repeat', function (): void {
    $outcome = readSiblingDocument(
        ['/things' => siblingPostPath(['$ref' => '#/components/schemas/Alias'])],
        [
            'Alias' => ['$ref' => '#/components/schemas/Node', 'properties' => ['p' => ['$ref' => '#/components/schemas/Alias']]],
            'Node' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ],
    );

    expect($outcome->faults)->toBe([]);

    $alias = siblingFieldOfBody($outcome->operations);
    $marker = $alias->properties['p'];

    expect($alias->name)->toBe('Alias')
        ->and($alias->source)->toBe('#/components/schemas/Alias')
        ->and($marker->recursesTo)->toBe('#/components/schemas/Alias')
        ->and($marker->name)->toBe('Alias')
        ->and($marker->properties)->toBe([]);
});

it('refuses a nested $ref whose target does not exist', function (): void {
    $outcome = readSiblingDocument([
        '/broken' => siblingPostPath(['$ref' => '#/components/schemas/Base', 'properties' => [
            'owner' => ['$ref' => '#/components/schemas/Missing'],
        ]], 'createBroken'),
        '/fine' => siblingPostPath(['$ref' => '#/components/schemas/Base'], 'createFine'),
    ], ['Base' => ['type' => 'object']]);

    expect($outcome->faults)->toHaveCount(1)
        ->and($outcome->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($outcome->faults[0]->getMessage())->toContain('#/components/schemas/Missing')
        ->and(array_map(static fn (Operation $operation): ?string => $operation->operationId, $outcome->operations))
        ->toBe(['createFine']);
});

it('refuses a nested $ref into $defs beside a $ref, as anywhere else', function (): void {
    $outcome = readSiblingDocument(
        ['/things' => siblingPostPath(['$ref' => '#/components/schemas/Base', 'properties' => [
            'amount' => ['$ref' => '#/components/schemas/Money/$defs/Amount'],
        ]])],
        ['Base' => ['type' => 'object'], 'Money' => ['type' => 'object', '$defs' => ['Amount' => ['type' => 'integer']]]],
    );

    expect($outcome->faults)->toHaveCount(1)
        ->and($outcome->faults[0])->toBeInstanceOf(RejectedConstructException::class)
        ->and($outcome->faults[0]->getMessage())->toContain('inside a `$defs`');
});

it('resolves a $ref two levels down inside a target built from the raw document', function (): void {
    $operations = readSiblingField(
        ['$ref' => '#/components/schemas/Base', 'properties' => ['owner' => ['$ref' => '#/components/schemas/User']]],
        ['Base' => ['type' => 'object'], ...SIBLING_USER, ...SIBLING_ADDRESS],
    );
    $owner = siblingMerged(siblingFieldOf($operations))->properties['owner'];

    expect($owner->properties['address']->properties['city']->maxLength)->toBe(9)
        ->and(RuleSetBuilder::for($operations[0])->rules['field.owner.address.city'])
        ->toBe(['sometimes', 'string', 'max:9']);
});

// D42 b: `enum` and `example` are data. A key spelled `$ref` inside them is part of the value, and the parser would turn
// it into a reference, so both are read from the raw document, on every node.
it('reads an enum and an example that write a $ref key from the raw document', function (string $keyword, bool $beside): void {
    $written = $keyword === 'enum'
        ? ['enum' => [['$ref' => '#/components/schemas/Code'], 'a']]
        : ['example' => ['$ref' => '#/components/schemas/Code']];
    $field = $beside
        ? ['$ref' => '#/components/schemas/Code', ...$written]
        : ['type' => 'string', ...$written];

    $schema = siblingFieldOf(readSiblingField($field));

    $data = ['$ref' => '#/components/schemas/Code'];

    expect($keyword === 'enum' ? $schema->enum : $schema->examples)
        ->toBe($keyword === 'enum' ? [$data, 'a'] : [$data]);
})->with([
    'an enum beside a $ref' => ['enum', true],
    'an enum read directly' => ['enum', false],
    'an example beside a $ref' => ['example', true],
    'an example read directly' => ['example', false],
]);

// D42 b: an `allOf` is a list. Written as a mapping it has no branches to read, and the parser would drop it silently.
it('refuses an allOf written as a mapping, beside a $ref and at 3.0', function (array $field, string $version): void {
    $read = fn (): array => readSiblingField($field, SIBLING_CODE, $version);

    expect($read)->toThrow(InvalidDocumentException::class, '`allOf`');
})->with([
    'beside a $ref at 3.1' => [['$ref' => '#/components/schemas/Code', 'allOf' => ['$ref' => '#/components/schemas/Code']], '3.1.0'],
    'directly at 3.1' => [['allOf' => ['$ref' => '#/components/schemas/Code']], '3.1.0'],
    'directly at 3.0' => [['allOf' => ['$ref' => '#/components/schemas/Code']], '3.0.3'],
]);

it('refuses a nested chain that ends on a cycle in another file', function (): void {
    $outcome = readSiblingDocument(
        ['/things' => siblingPostPath(['$ref' => '#/components/schemas/Base', 'properties' => [
            'owner' => ['$ref' => './a.yaml#/A'],
        ]])],
        ['Base' => ['type' => 'object']],
        files: [
            'a.yaml' => ['A' => ['$ref' => './b.yaml#/B']],
            'b.yaml' => ['B' => ['$ref' => './a.yaml#/A']],
        ],
    );

    expect($outcome->faults)->toHaveCount(1)
        ->and($outcome->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($outcome->operations)->toBe([]);
});

it('refuses a malformed target reached through a nested $ref without crashing the read', function (): void {
    $outcome = readSiblingDocument([
        '/broken' => siblingPostPath(['$ref' => '#/components/schemas/Base', 'properties' => [
            'owner' => ['$ref' => './bad.yaml#/Bad'],
        ]], 'createBroken'),
        '/fine' => siblingPostPath(['$ref' => '#/components/schemas/Base'], 'createFine'),
    ], ['Base' => ['type' => 'object']], files: [
        'bad.yaml' => ['Bad' => ['type' => 'array', 'items' => 'x']],
    ]);

    expect($outcome->faults)->toHaveCount(1)
        ->and($outcome->faults[0])->toBeInstanceOf(InvalidDocumentException::class)
        ->and($outcome->faults[0]->getMessage())->toContain('bad.yaml#/Bad')
        ->and(array_map(static fn (Operation $operation): ?string => $operation->operationId, $outcome->operations))
        ->toBe(['createFine']);
});

// The raw document answers only for what it can reach. A `$ref` the walk cannot follow, a URL written by hand inside a
// local file (docs/guide/remote-references.md), is one the parser resolved alone, and its enum is still the rule.
it('keeps the parser\'s enum where the raw document can\'t reach the node', function (): void {
    $directory = sys_get_temp_dir().'/lsf-siblings-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/status.yaml', Yaml::dump(['Status' => ['type' => 'string', 'enum' => ['open', 'closed']]]));

    try {
        $outcome = readSiblingDocument(
            ['/things' => siblingPostPath(['type' => 'object', 'properties' => [
                'field' => ['$ref' => './alias.yaml#/Alias'],
            ]])],
            files: ['alias.yaml' => ['Alias' => ['$ref' => 'file://'.$directory.'/status.yaml#/Status']]],
        );
    } finally {
        exec('rm -rf '.escapeshellarg($directory));
    }

    $in = array_values(array_filter(
        RuleSetBuilder::for($outcome->operations[0])->rules['field'],
        static fn (mixed $rule): bool => $rule instanceof InRule,
    ));

    expect($outcome->faults)->toBe([])
        ->and($in)->toHaveCount(1)
        ->and($in[0]->values)->toBe(['open', 'closed']);
});
