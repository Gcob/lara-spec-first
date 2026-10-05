<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;

/*
 * One rule set, asserted rule by rule.
 *
 * **Two halves, and the second is the one that makes the first safe to ship.**
 * What this pass translates is small on purpose; what it does not translate has
 * to leave a finding naming the keyword and the field, because a rule set that
 * is silently incomplete is the failure this package exists against. So every
 * case below asserts one of the two, and the pair of them is the contract.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */

/**
 * A schema of scalar properties, named rather than positional.
 *
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $required
 */
function objectSchema(array $properties, array $required = []): Schema
{
    return new Schema(types: [SchemaType::Object], properties: $properties, required: $required);
}

/**
 * One operation carrying a body, a set of query parameters, or both.
 *
 * @param  array<string, Schema>  $content
 * @param  list<QueryParameter>  $query
 */
function operationWithInput(
    string $method = 'post',
    array $content = [],
    bool $bodyRequired = true,
    array $query = [],
    string $path = '/things',
): Operation {
    return new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: 'doThing',
        requestBody: $content === [] ? null : new RequestBody($content, $bodyRequired),
        queryParameters: $query,
    );
}

/**
 * The rule set of an operation whose JSON body is one object schema.
 *
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $required
 * @return array<string, list<string>>
 */
function rulesFor(array $properties, array $required = [], string $method = 'post'): array
{
    return RuleSetBuilder::for(operationWithInput(
        method: $method,
        content: ['application/json' => objectSchema($properties, $required)],
    ))->rules;
}

// --- The four types this pass maps, and the one rule each becomes ---

it('maps a scalar type to the rule that means the same thing', function (
    SchemaType $type,
    string $rule,
): void {
    expect(rulesFor(['field' => new Schema(types: [$type])]))->toBe(['field' => ['sometimes', $rule]]);
})->with([
    'string' => [SchemaType::String, 'string'],
    'integer' => [SchemaType::Integer, 'integer'],
    // `numeric` rather than `number`: Laravel has no rule by that name, and
    // `numeric` is what accepts both an integer and a float.
    'number' => [SchemaType::Number, 'numeric'],
    'boolean' => [SchemaType::Boolean, 'boolean'],
]);

it('reads nullability off the type list', function (): void {
    expect(rulesFor(['field' => new Schema(types: [SchemaType::String, SchemaType::Null])]))
        ->toBe(['field' => ['sometimes', 'nullable', 'string']]);
});

// --- Presence, which is where the method and the body's own required flag meet ---

// JSON Schema's `required` asks for the key; Laravel's refuses `null`, `""`,
// `[]` and `{}`. So `required` is kept only where the type rule refuses every
// empty value anyway, and every other type asks for the key with `present`.
it('asks for a required key the way its type allows', function (SchemaType $type, string $rule): void {
    expect(rulesFor(['field' => new Schema(types: [$type])], ['field']))
        ->toBe(['field' => array_values(array_filter([$rule, match ($type) {
            SchemaType::String => 'string',
            SchemaType::Integer => 'integer',
            SchemaType::Number => 'numeric',
            SchemaType::Boolean => 'boolean',
            default => null,
        }]))]);
})->with([
    // `""` is a string the contract allows unless it says `minLength`.
    'a string' => [SchemaType::String, 'present'],
    // `[]` and `{}` are values the contract allows unless it says otherwise.
    'an array' => [SchemaType::Array, 'present'],
    'an object' => [SchemaType::Object, 'present'],
    // The type rule refuses every empty value here, so the two rules agree.
    'an integer' => [SchemaType::Integer, 'required'],
    'a number' => [SchemaType::Number, 'required'],
    'a boolean' => [SchemaType::Boolean, 'required'],
]);

it('asks only for the key when a required property states no type', function (): void {
    expect(rulesFor(['field' => new Schema], ['field']))->toBe(['field' => ['present']]);
});

// Laravel's `required` refuses `null`, and a schema requiring a nullable
// property is asking for the key rather than for a value.
it('asks for the key rather than a value when a required property may be null', function (): void {
    $schema = new Schema(types: [SchemaType::String, SchemaType::Null]);

    expect(rulesFor(['name' => $schema], ['name']))
        ->toBe(['name' => ['present', 'nullable', 'string']]);
});

it('leaves a property the schema does not require optional', function (): void {
    expect(rulesFor(['name' => new Schema(types: [SchemaType::String])]))
        ->toBe(['name' => ['sometimes', 'string']]);
});

// Scenario: PUT requires the full body / PATCH makes the same fields optional.
it('reads the required list on a PUT and reads it as empty on a PATCH', function (
    string $method,
    string $stringPresence,
    string $booleanPresence,
): void {
    $properties = [
        'title' => new Schema(types: [SchemaType::String]),
        'body' => new Schema(types: [SchemaType::String]),
        'published' => new Schema(types: [SchemaType::Boolean]),
    ];

    expect(rulesFor($properties, ['title', 'body', 'published'], $method))->toBe([
        'title' => [$stringPresence, 'string'],
        'body' => [$stringPresence, 'string'],
        'published' => [$booleanPresence, 'boolean'],
    ]);
})->with([
    'put' => ['put', 'present', 'required'],
    'patch' => ['patch', 'sometimes', 'sometimes'],
]);

// "And every other constraint from the schema survives" — the scenario's second
// line, which is the one a naive implementation drops by rebuilding the rule
// set from the method instead of from the schema.
it('keeps every other rule when a PATCH empties the required list', function (): void {
    $properties = ['name' => new Schema(types: [SchemaType::String, SchemaType::Null])];

    expect(rulesFor($properties, ['name'], 'patch'))
        ->toBe(['name' => ['sometimes', 'nullable', 'string']]);
});

// A plain `required` on an optional body would refuse a request the contract
// allows, which is the one direction worse than a missing rule.
it('does not require a property when the whole body may be absent', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(
            ['name' => new Schema(types: [SchemaType::String])],
            ['name'],
        )],
        bodyRequired: false,
    ));

    expect($set->rules)->toBe(['name' => ['sometimes', 'string']])
        ->and(implode('', $set->findings))->toContain('may be absent entirely');
});

// --- Query parameters, whose presence is their own and never the method's ---

it('keys a query parameter by its own name and its own required flag', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [
            new QueryParameter('page', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('tenant', new Schema(types: [SchemaType::String]), required: true),
        ],
    ));

    expect($set->rules)->toBe([
        'page' => ['sometimes', 'integer'],
        // `present` for the same reason a body string gets it: `?tenant=` is
        // a string the contract allows.
        'tenant' => ['present', 'string'],
    ]);
});

// `?notify=1` is as required on a PATCH as on a PUT: a query parameter is not
// part of the representation a partial update is partial about.
it('leaves a required query parameter required on a PATCH', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'patch',
        content: ['application/json' => objectSchema(
            ['name' => new Schema(types: [SchemaType::String])],
            ['name'],
        )],
        query: [new QueryParameter('dryRun', new Schema(types: [SchemaType::Boolean]), required: true)],
    ));

    expect($set->rules)->toBe([
        'name' => ['sometimes', 'string'],
        'dryRun' => ['required', 'boolean'],
    ]);
});

it('merges the body and the query into one rule set, body first', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['email' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('notify', new Schema(types: [SchemaType::Boolean]))],
    ));

    expect(array_keys($set->rules))->toBe(['email', 'notify']);
});

// --- What is refused rather than guessed at ---

it('refuses a name declared by both the body and a query parameter', function (): void {
    expect(fn () => RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['page' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    )))->toThrow(ConflictingInputException::class, 'both as a property of its request body');
});

it('refuses two read media types describing two different shapes', function (): void {
    expect(fn () => RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => objectSchema(['a' => new Schema(types: [SchemaType::String])]),
        'multipart/form-data' => objectSchema(['b' => new Schema(types: [SchemaType::String])]),
    ])))->toThrow(ConflictingInputException::class, 'cannot hold two rule sets');
});

// Two media types over one schema are not a conflict, and comparing by identity
// rather than by value would have made them one: nothing guarantees a `$ref`
// reaches two media types as the same object.
it('accepts two read media types describing one shape', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => objectSchema(['a' => new Schema(types: [SchemaType::String])]),
        'multipart/form-data' => objectSchema(['a' => new Schema(types: [SchemaType::String])]),
    ]));

    expect($set->rules)->toBe(['a' => ['sometimes', 'string']])
        ->and(implode('', $set->findings))->toContain('over one schema');
});

// Reported rather than refused: refusing the whole build over a media type this
// package does not serve would turn away an operation whose other half is fine.
it('reports a media type it does not read rather than refusing it', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/xml' => objectSchema(['a' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));

    expect($set->rules)->toBe(['page' => ['sometimes', 'integer']])
        ->and(implode('', $set->findings))->toContain('`application/xml`, which this package does not read');
});

// --- Every constraint maps or reports ---

it('names a keyword it does not translate, and the field it was written on', function (
    Schema $schema,
    string $keyword,
): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['field' => $schema])],
    ));

    expect(implode('', $set->findings))->toContain('`field` states `'.$keyword.'`');
})->with([
    'format' => [new Schema(types: [SchemaType::String], format: 'email'), 'format'],
    'pattern' => [new Schema(types: [SchemaType::String], pattern: '^a+$'), 'pattern'],
    'minLength' => [new Schema(types: [SchemaType::String], minLength: 1), 'minLength'],
    'maxLength' => [new Schema(types: [SchemaType::String], maxLength: 10), 'maxLength'],
    'minimum' => [new Schema(types: [SchemaType::Integer], minimum: 1.0), 'minimum'],
    'maximum' => [new Schema(types: [SchemaType::Integer], maximum: 9.0), 'maximum'],
    'exclusiveMinimum' => [new Schema(types: [SchemaType::Number], exclusiveMinimum: 0.0), 'exclusiveMinimum'],
    'multipleOf' => [new Schema(types: [SchemaType::Number], multipleOf: 2.0), 'multipleOf'],
    'enum' => [new Schema(types: [SchemaType::String], enum: ['a', 'b']), 'enum'],
    'minItems' => [new Schema(types: [SchemaType::Array], minItems: 1), 'minItems'],
    'uniqueItems' => [new Schema(types: [SchemaType::Array], uniqueItems: true), 'uniqueItems'],
    'items' => [new Schema(types: [SchemaType::Array], items: new Schema), 'items'],
    'properties' => [new Schema(types: [SchemaType::Object], properties: ['a' => new Schema]), 'properties'],
    'dependentRequired' => [new Schema(dependentRequired: ['a' => ['b']]), 'dependentRequired'],
    'allOf' => [new Schema(allOf: [new Schema]), 'allOf'],
    'additionalProperties' => [new Schema(types: [SchemaType::Object], additionalProperties: false), 'additionalProperties'],
    'isFilePart' => [new Schema(types: [SchemaType::String], isFilePart: true), 'isFilePart'],
    'contentMediaType' => [new Schema(types: [SchemaType::String], contentMediaType: 'image/png'), 'contentMediaType'],
]);

// `true` asks for nothing, so there is no rule for it to be missing: a finding
// about it would be noise on a keyword that constrains nothing.
it('says nothing about a nested object that allows undeclared fields', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema([
            'field' => new Schema(types: [SchemaType::Object], additionalProperties: true),
        ])],
    ));

    expect(implode('', $set->findings))->not->toContain('additionalProperties');
});

it('says why a field got no type rule at all', function (Schema $schema, string $reason): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['field' => $schema])],
    ));

    expect(implode('', $set->findings))->toContain($reason);
})->with([
    'an array' => [new Schema(types: [SchemaType::Array]), 'is an array, whose element rules'],
    'an object' => [new Schema(types: [SchemaType::Object]), 'is an object, whose properties'],
    // Adding `numeric` of our own would be stricter than the contract, which is
    // the direction a missing rule is preferable to.
    'a union' => [new Schema(types: [SchemaType::String, SchemaType::Integer]), 'declares more than one type'],
    'no type at all' => [new Schema, 'states no type'],
]);

// The root goes through the same sweep its properties do. Without it a keyword
// written on the body's own schema is read and enforced by nothing, and an
// empty findings list makes the generated file claim the opposite.
it('names a keyword written on the body itself', function (Schema $root, string $keyword): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $root]));

    expect(implode('', $set->findings))->toContain('The body itself states `'.$keyword.'`');
})->with([
    'dependentRequired' => [
        new Schema(
            types: [SchemaType::Object],
            properties: ['a' => new Schema(types: [SchemaType::String])],
            dependentRequired: ['a' => ['b']],
        ),
        'dependentRequired',
    ],
    'enum' => [new Schema(types: [SchemaType::Object], enum: [['a' => 1]]), 'enum'],
]);

// Four keywords are skipped at the root because something above already speaks
// to them, and naming one twice reads as two problems.
it('does not name a root keyword another finding already speaks to', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(
        types: [SchemaType::Object],
        properties: ['a' => new Schema(types: [SchemaType::String])],
        additionalProperties: false,
        allOf: [new Schema],
    )]));

    $findings = implode('', $set->findings);

    expect($findings)->not->toContain('The body itself states')
        ->and($findings)->toContain('writes `allOf`')
        ->and($findings)->toContain('forbids properties it did not declare');
});

// `required` naming a key `properties` does not declare is legal OpenAPI: the
// key must be present, any value. Presence is the whole of what the contract
// states about it, so presence is the whole rule, and a finding says why the
// rule set knows nothing else about it.
it('requires a key the schema requires without declaring it', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(
            ['title' => new Schema(types: [SchemaType::String])],
            ['title', 'ghost'],
        )],
    ));

    expect($set->rules)->toBe([
        'title' => ['present', 'string'],
        'ghost' => ['present'],
    ])->and(implode('', $set->findings))->toContain('requires `ghost` without declaring it');
});

// `*` is the validator's wildcard in a rule key and has no escape, so a rule
// keyed with one would apply to keys the contract never declared. Short by a
// field rather than wrong about one.
it('reports a field name Laravel would read as a wildcard', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema([
            'a*b' => new Schema(types: [SchemaType::String]),
            'plain' => new Schema(types: [SchemaType::String]),
        ])],
        query: [new QueryParameter('page*', new Schema(types: [SchemaType::Integer]))],
    ));

    expect(array_keys($set->rules))->toBe(['plain'])
        ->and(implode('', $set->findings))->toContain('`a*b` carries a `*`')
        ->and(implode('', $set->findings))->toContain('`page*` carries a `*`');
});

// The same report for a name `required` lists without declaring it: a key
// carrying `*` would be a wildcard that never fires, so no rule is emitted.
it('reports an undeclared required name Laravel would read as a wildcard', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['plain' => new Schema(types: [SchemaType::String])],
        ['plain', 'a*b'],
    )]));

    expect(array_keys($set->rules))->toBe(['plain'])
        ->and(implode('', $set->findings))->toContain('`a*b` carries a `*`')
        ->and(implode('', $set->findings))->not->toContain('requires `a*b`');
});

it('says the rule set is complete when it is', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(
            ['name' => new Schema(types: [SchemaType::String])],
            ['name'],
        )],
    ));

    expect($set->findings)->toBe([]);
});

// A `PUT` cannot fill an absent field from a schema default, so the class names
// the seam a project writing replacement semantics would override.
it('names update() on a PUT whose schema leaves a property optional', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'put',
        content: ['application/json' => objectSchema(
            [
                'title' => new Schema(types: [SchemaType::String]),
                'subtitle' => new Schema(types: [SchemaType::String]),
            ],
            ['title'],
        )],
    ));

    expect(implode('', $set->findings))->toContain('Override `update()`')
        ->and(implode('', $set->findings))->toContain('`subtitle`');
});

// Laravel reads an unescaped dot in a rule key as nesting, so a literal
// `user.name` would generate rules for a `name` key inside a `user` object the
// contract never declared.
it('escapes a dot in a property name', function (): void {
    expect(array_keys(rulesFor(['user.name' => new Schema(types: [SchemaType::String])])))
        ->toBe(['user\\.name']);
});

it('reports a body whose own schema is not an object', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => new Schema(types: [SchemaType::String])],
    ));

    expect($set->rules)->toBe([])
        ->and(implode('', $set->findings))->toContain('is not an object');
});
