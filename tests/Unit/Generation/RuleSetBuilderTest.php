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
use Gcob\LaraSpecFirst\Generation\InRule;
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
 * @return array<string, list<string|InRule>>
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
            SchemaType::Array, SchemaType::Object => 'array',
            SchemaType::Null => null,
        }, $type === SchemaType::Array ? 'list' : null]))]);
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
it('does not require a lone property when the whole body may be absent', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(
            ['name' => new Schema(types: [SchemaType::String])],
            ['name'],
        )],
        bodyRequired: false,
    ));

    // One required property has no sibling to name, and Laravel cannot tell
    // an absent body from an empty one.
    expect($set->rules)->toBe(['name' => ['sometimes', 'string']])
        ->and(implode('', $set->findings))->toContain('may be absent entirely');
});

// The finding is about a required key with no sibling to name. A body that
// declares one key and requires none has nothing to say, and the finding used to
// fire anyway, with an empty name.
it('says nothing about an absent body that declares one optional property', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['name' => new Schema(types: [SchemaType::String])])],
        bodyRequired: false,
    ));

    expect($set->rules)->toBe(['name' => ['sometimes', 'string']])
        ->and(implode('', $set->findings))->not->toContain('may be absent entirely')
        ->and(implode('', $set->findings))->not->toContain('requires  ');
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

    expect($set->rules)->toEqual([
        'name' => ['sometimes', 'string'],
        'dryRun' => ['required', new InRule(['true', 'false', '1', '0'])],
    ]);
});

// A query string is not JSON: `?tag=a&tag=b` keeps only the last value in PHP,
// and `explode: false` arrives as one string. Every rule about the shape would
// refuse a valid request, so only presence is enforced and the rest reported.
it('enforces only the presence of an array or object query parameter', function (SchemaType $type): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [new QueryParameter('tag', new Schema(types: [$type], items: new Schema(types: [SchemaType::String])), required: true)],
    ));

    expect($set->rules)->toBe(['tag' => ['present']])
        ->and(implode('', $set->findings))->toContain('serialization is not read');
})->with([SchemaType::Array]);

// The default `form` style with `explode` sends an object as one key per
// property, `?status=a`, and never the parameter's own name, so even its
// presence would refuse a valid request.
it('enforces nothing about an object query parameter, not even its presence', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [new QueryParameter('filter', new Schema(types: [SchemaType::Object]), required: true)],
    ));

    expect($set->rules)->toBe(['filter' => ['sometimes']])
        ->and(implode('', $set->findings))->toContain('not even its presence');
});

it('merges the body and the query into one rule set, body first', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['email' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('notify', new Schema(types: [SchemaType::Boolean]))],
    ));

    expect(array_keys($set->rules))->toBe(['email', 'notify']);
});

// The emitted request reads exactly these names from the query string, so the list
// is the parameters that have a rule, as the document writes them.
it('names the query parameters that have a rule', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['email' => new Schema(types: [SchemaType::String])])],
        query: [
            new QueryParameter('notify', new Schema(types: [SchemaType::Boolean])),
            new QueryParameter('tag', new Schema(types: [SchemaType::Array]), required: true),
            new QueryParameter('filter', new Schema(types: [SchemaType::Object])),
            new QueryParameter('page*', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect($set->queryKeys)->toBe(['notify', 'tag', 'filter']);
});

it('names no query parameter for an operation that declares none', function (): void {
    expect(RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['email' => new Schema(types: [SchemaType::String])])],
    ))->queryKeys)->toBe([]);
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

// --- Every constraint maps or reports: one case per row of the table ---

/**
 * The rules one field gets, alone in an otherwise empty object body.
 *
 * @return list<string|InRule>
 */
function fieldRules(Schema $field): array
{
    return rulesFor(['field' => $field])['field'];
}

it('maps each constraint to the Laravel rule that means the same thing', function (
    Schema $field,
    array $expected,
): void {
    expect(fieldRules($field))->toEqual($expected);
})->with([
    // Beside `string`, `min` and `max` count characters.
    'minLength, maxLength' => [
        new Schema(types: [SchemaType::String], minLength: 2, maxLength: 10),
        ['sometimes', 'string', 'min:2', 'max:10'],
    ],
    // Beside `integer` or `numeric`, they compare the value.
    'minimum, maximum' => [
        new Schema(types: [SchemaType::Integer], minimum: 1.0, maximum: 9.0),
        ['sometimes', 'integer', 'min:1', 'max:9'],
    ],
    'exclusiveMinimum, exclusiveMaximum' => [
        new Schema(types: [SchemaType::Number], exclusiveMinimum: 0.0, exclusiveMaximum: 1.5),
        ['sometimes', 'numeric', 'gt:0', 'lt:1.5'],
    ],
    'multipleOf' => [
        new Schema(types: [SchemaType::Number], multipleOf: 0.25),
        ['sometimes', 'numeric', 'multiple_of:0.25'],
    ],
    'format: date' => [
        new Schema(types: [SchemaType::String], format: 'date'),
        ['sometimes', 'string', 'date_format:Y-m-d'],
    ],
    'format: date-time' => [
        new Schema(types: [SchemaType::String], format: 'date-time'),
        [
            'sometimes',
            'string',
            'regex:/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])[Tt]([01][0-9]|2[0-3]):[0-5][0-9]:([0-5][0-9]|60)(\.[0-9]+)?([Zz]|[+-]([01][0-9]|2[0-3]):[0-5][0-9])$/D',
            'date',
        ],
    ],
    'format: email' => [new Schema(types: [SchemaType::String], format: 'email'), ['sometimes', 'string', 'email']],
    'format: uuid' => [new Schema(types: [SchemaType::String], format: 'uuid'), ['sometimes', 'string', 'uuid']],
    'format: ipv4' => [new Schema(types: [SchemaType::String], format: 'ipv4'), ['sometimes', 'string', 'ipv4']],
    'format: ipv6' => [new Schema(types: [SchemaType::String], format: 'ipv6'), ['sometimes', 'string', 'ipv6']],
    // `D` gives PCRE's `$` the ECMA-262 reading, and `u` counts a multibyte
    // character as one.
    'pattern' => [
        new Schema(types: [SchemaType::String], pattern: '^[a-z]+/[a-z]+$'),
        ['sometimes', 'string', 'regex:/^[a-z]+\/[a-z]+$/uD'],
    ],
    // An array, never `in:a,b`: a comma inside a value would split it.
    'enum' => [
        new Schema(types: [SchemaType::String], enum: ['a,b', 'c']),
        ['sometimes', 'string', new InRule(['a,b', 'c'])],
    ],
    // A value has to satisfy the type list and the enumeration at once, so
    // `null` is allowed only when both allow it.
    'enum with null, type allowing null' => [
        new Schema(types: [SchemaType::String, SchemaType::Null], enum: ['a', null]),
        ['sometimes', 'nullable', 'string', new InRule(['a'])],
    ],
    'enum with null, type refusing it' => [
        new Schema(types: [SchemaType::String], enum: ['a', null]),
        ['sometimes', 'string', new InRule(['a'])],
    ],
    'type allowing null, enum refusing it' => [
        new Schema(types: [SchemaType::String, SchemaType::Null], enum: ['a']),
        ['sometimes', 'string', new InRule(['a'])],
    ],
    // `Rule::in` compares as strings; every allowed value being a string is the
    // contract saying the field is one.
    'an untyped enumeration of strings' => [
        new Schema(enum: ['1', '2']),
        ['sometimes', 'string', new InRule(['1', '2'])],
    ],
    // `list` beside `array`: Laravel's `array` passes for an associative one.
    'an array, minItems, maxItems' => [
        new Schema(types: [SchemaType::Array], minItems: 1, maxItems: 3),
        ['sometimes', 'array', 'list', 'min:1', 'max:3'],
    ],
    'an object that closes itself' => [
        new Schema(types: [SchemaType::Object], properties: ['a' => new Schema], additionalProperties: false),
        ['sometimes', 'array:a'],
    ],
]);

it('keys the element rules of an array under field.*', function (): void {
    expect(rulesFor(['tags' => new Schema(
        types: [SchemaType::Array],
        items: new Schema(types: [SchemaType::String], maxLength: 20),
        uniqueItems: true,
    )]))->toBe([
        'tags' => ['sometimes', 'array', 'list'],
        // `distinct:strict` rather than `distinct`: the loose comparison
        // would call `1` and `"1"` the same element.
        'tags.*' => ['string', 'max:20', 'distinct:strict'],
    ]);
});

// JSON Schema's `required` asks for keys on the object itself, and so does
// `required_array_keys`. On the parent rather than on each child, so a nullable
// object may be `null`: a child's rule would fire on the parent's key whatever
// its value.
it('keys a nested object with dots, and asks the object for its required keys', function (): void {
    expect(rulesFor(['address' => new Schema(
        types: [SchemaType::Object],
        properties: [
            'city' => new Schema(types: [SchemaType::String]),
            'floor' => new Schema(types: [SchemaType::Integer]),
            'note' => new Schema(types: [SchemaType::String]),
        ],
        required: ['city', 'floor'],
    )], ['address']))->toBe([
        'address' => ['present', 'array', 'required_array_keys:city,floor'],
        'address.city' => ['sometimes', 'string'],
        'address.floor' => ['sometimes', 'integer'],
        'address.note' => ['sometimes', 'string'],
    ]);
});

// The same for an array element, which keeps `[null]` acceptable when the
// element may be null.
it('asks an array element for its required keys', function (): void {
    expect(rulesFor(['lines' => new Schema(
        types: [SchemaType::Array],
        items: new Schema(
            types: [SchemaType::Object],
            properties: ['sku' => new Schema(types: [SchemaType::String])],
            required: ['sku'],
        ),
    )]))->toBe([
        'lines' => ['sometimes', 'array', 'list'],
        'lines.*' => ['array', 'required_array_keys:sku'],
        'lines.*.sku' => ['sometimes', 'string'],
    ]);
});

it('reads dependentRequired as a presence that follows its trigger', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(
        types: [SchemaType::Object],
        properties: [
            'card' => new Schema(types: [SchemaType::String]),
            'postcode' => new Schema(types: [SchemaType::String]),
        ],
        dependentRequired: ['card' => ['postcode', 'billing']],
    )]));

    expect($set->rules)->toBe([
        'card' => ['sometimes', 'string'],
        'postcode' => ['present_with:card', 'string'],
        'billing' => ['present_with:card'],
    ]);
});

// "All or none": nothing sent, nothing required; one sent, the rest required.
it('makes an optional body all or none', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema([
            'street' => new Schema(types: [SchemaType::String]),
            'city' => new Schema(types: [SchemaType::String]),
            'code' => new Schema(types: [SchemaType::Integer]),
        ], ['street', 'city', 'code'])],
        bodyRequired: false,
    ));

    expect($set->rules)->toBe([
        'street' => ['present_with:city,code', 'string'],
        'city' => ['present_with:street,code', 'string'],
        // `present_with` even for an integer: `required_with` fires only on a
        // non-blank trigger, so `{"street": null}` would require nothing.
        'code' => ['present_with:street,city', 'integer'],
    ]);
});

it('merges allOf into one rule set', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(allOf: [
        objectSchema(['name' => new Schema(types: [SchemaType::String], maxLength: 50)], ['name']),
        objectSchema(['name' => new Schema(maxLength: 20), 'age' => new Schema(types: [SchemaType::Integer])]),
    ])]));

    // The tighter bound wins, and the required list accumulates.
    expect($set->rules)->toBe([
        'name' => ['present', 'string', 'max:20'],
        'age' => ['sometimes', 'integer'],
    ]);
});

it('refuses allOf branches that cannot be said as one', function (Schema $first, Schema $second, string $what): void {
    expect(fn () => RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => objectSchema(['field' => new Schema(allOf: [$first, $second])]),
    ])))->toThrow(ConflictingInputException::class, $what);
})->with([
    'two types' => [new Schema(types: [SchemaType::String]), new Schema(types: [SchemaType::Integer]), 'one branch is `string`'],
    'two enumerations' => [new Schema(enum: ['a']), new Schema(enum: ['b']), 'share no value'],
    'two formats' => [new Schema(format: 'email'), new Schema(format: 'uuid'), '`format`'],
    // JSON Schema's own trap: a closed branch forbids what the other declares.
    'a closed branch' => [
        new Schema(types: [SchemaType::Object], properties: ['a' => new Schema], additionalProperties: false),
        new Schema(types: [SchemaType::Object], properties: ['b' => new Schema]),
        'additionalProperties: false',
    ],
]);

// Each side of a property written in two branches is merged first, so an
// `allOf` nested inside it survives the outer merge.
it('keeps a nested allOf when two branches declare the same property', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(allOf: [
        objectSchema(['code' => new Schema(types: [SchemaType::String])]),
        objectSchema(['code' => new Schema(allOf: [new Schema(maxLength: 3)])]),
    ])]));

    expect($set->rules['code'])->toBe(['sometimes', 'string', 'max:3']);
});

// An integer is a number, so the two branches agree on the integer.
it('merges integer and number into integer', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema([
        'n' => new Schema(allOf: [new Schema(types: [SchemaType::Number]), new Schema(types: [SchemaType::Integer])]),
    ])]));

    expect($set->rules['n'])->toBe(['sometimes', 'integer']);
});

// A dot is read as nesting before a key-list rule sees the data, and a comma
// splits the list, so such a name is reported rather than matching nothing.
it('reports a nested key name Laravel cannot list', function (string $name): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(['n' => new Schema(
        types: [SchemaType::Object],
        properties: [$name => new Schema(types: [SchemaType::String])],
        required: [$name],
        additionalProperties: false,
    )])]));

    expect($set->rules['n'])->toBe(['sometimes', 'array'])
        ->and(implode('', $set->findings))->toContain('cannot name in `required_array_keys`')
        ->and(implode('', $set->findings))->toContain('cannot name in `array:`');
})->with(['a dot' => ['a.b'], 'a comma' => ['a,b']]);

// `null` alone is a value no Laravel rule allows exactly, so it is reported
// rather than read as "anything".
it('reports an enumeration of null alone', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema([
        'field' => new Schema(enum: [null]),
    ])]));

    expect(implode('', $set->findings))->toContain('`field` states `enum`');
});

// `distinct` compares across the whole outer list under a wildcard, and never
// compares object or array elements themselves, so both shapes are reported.
it('reports uniqueItems where distinct would not mean it', function (Schema $field): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(['field' => $field])]));

    expect(json_encode($set->rules))->not->toContain('distinct')
        ->and(implode('', $set->findings))->toContain('`uniqueItems`');
})->with([
    'an array under another array' => [new Schema(
        types: [SchemaType::Array],
        items: new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String]), uniqueItems: true),
    )],
    'object elements' => [new Schema(
        types: [SchemaType::Array],
        items: new Schema(types: [SchemaType::Object]),
        uniqueItems: true,
    )],
    // The 3.0 idiom of wrapping a `$ref` in `allOf`: the type is only known
    // once the branches are merged.
    'object elements behind allOf' => [new Schema(
        types: [SchemaType::Array],
        items: new Schema(allOf: [new Schema(types: [SchemaType::Object])]),
        uniqueItems: true,
    )],
    'untyped elements' => [new Schema(types: [SchemaType::Array], items: new Schema, uniqueItems: true)],
    'no items at all' => [new Schema(types: [SchemaType::Array], uniqueItems: true)],
    // `distinct` lets two nulls through.
    'nullable scalar elements' => [new Schema(
        types: [SchemaType::Array],
        items: new Schema(types: [SchemaType::String, SchemaType::Null]),
        uniqueItems: true,
    )],
]);

it('closes the root to the keys it declares', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(
        types: [SchemaType::Object],
        properties: ['a' => new Schema(types: [SchemaType::String])],
        additionalProperties: false,
    )]));

    expect($set->closedKeys)->toBe(['a']);
});

it('leaves the root open when it does not close itself', function (): void {
    expect(RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema([])]))->closedKeys)
        ->toBeNull();
});

it('reports what no Laravel rule means the same as', function (Schema $field, string $reason): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(['field' => $field])]));

    expect(implode('', $set->findings))->toContain($reason);
})->with([
    // Laravel's `url` turns away the non-hierarchical URIs JSON Schema allows.
    'format: uri' => [new Schema(types: [SchemaType::String], format: 'uri'), '`format: uri`'],
    'a pattern using \d' => [new Schema(types: [SchemaType::String], pattern: '^\d+$'), 'uses `\d`'],
    'a pattern using lookbehind' => [new Schema(types: [SchemaType::String], pattern: '(?<=a)b'), 'lookbehind'],
    'a pattern PCRE cannot compile' => [new Schema(types: [SchemaType::String], pattern: '('), 'does not compile'],
    // Risk 2: without a type rule Laravel reads `min` as a string length.
    'a bound on an untyped field' => [new Schema(minimum: 1.0), '`field` states `minimum`'],
    'a recursion marker' => [Schema::recursion('#/components/schemas/Node'), 'points back at'],
    // A file part in a JSON body is a string: JSON cannot carry a file.
    'a file part in a JSON body' => [new Schema(types: [SchemaType::String], isFilePart: true), '`isFilePart`'],
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
    'a union' => [new Schema(types: [SchemaType::String, SchemaType::Integer]), 'declares more than one type'],
    'no type at all' => [new Schema, 'states no type'],
]);

// The root goes through the same sweep a field does.
it('names a keyword written on the body itself', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(
        types: [SchemaType::Object],
        enum: [['a' => 1]],
    )]));

    expect(implode('', $set->findings))->toContain('The body itself states `enum`');
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

/*
 * Part 3: what a file part becomes, as strings. `GeneratedRequestTest` is the
 * proof that Laravel reads these the way the contract meant.
 */

/**
 * The rules and findings of one multipart property.
 *
 * @param  array<int, string>  $mediaTypes
 * @return array{0: array<string, list<string|InRule>>, 1: string}
 */
function filePartRules(Schema $part, bool $required = true, array $mediaTypes = ['multipart/form-data'], string $method = 'post'): array
{
    $set = RuleSetBuilder::for(operationWithInput(
        method: $method,
        content: array_fill_keys($mediaTypes, objectSchema(['avatar' => $part], $required ? ['avatar'] : [])),
    ));

    return [$set->rules, implode("\n", $set->findings)];
}

it('reads a file part as file, not string', function (): void {
    [$rules] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true));

    expect($rules['avatar'])->toBe(['required', 'file']);
});

it('reads the types of a file part from contentMediaType', function (?string $declared, array $expected): void {
    [$rules] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: $declared));

    expect($rules['avatar'])->toBe(['required', 'file', ...$expected]);
})->with([
    'none' => [null, []],
    'a type' => ['image/png', ['mimetypes:image/png']],
    'a wildcard' => ['image/*', ['mimetypes:image/*']],
    'parameters dropped, lower case' => ['Image/PNG; charset=binary', ['mimetypes:image/png']],
    'any bytes' => ['application/octet-stream', []],
    'the any-type wildcard' => ['*/*', []],
]);

// A query string carries no file, so a parameter is never read as one, whatever the
// body of the same operation is.
it('does not read a query parameter as a file in a multipart operation', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['multipart/form-data' => objectSchema(['a' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('f', new Schema(types: [SchemaType::String], isFilePart: true))],
    ));

    expect($set->rules['f'])->toBe(['sometimes', 'string']);
});

it('reads a file part with no type at 3.1 as a file', function (): void {
    [$rules] = filePartRules(new Schema(isFilePart: true, contentMediaType: 'image/png'));

    expect($rules['avatar'])->toBe(['required', 'file', 'mimetypes:image/png']);
});

// Laravel's `max` takes a decimal, so a byte ceiling is exact: 2500 bytes over
// 1024 is `2.44140625`, which has a finite expansion whatever the count.
it('turns maxLength on a file into an exact decimal of kilobytes', function (int $bytes, string $rule): void {
    [$rules] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true, maxLength: $bytes));

    expect($rules['avatar'])->toBe(['required', 'file', $rule]);
})->with([
    'exactly one kilobyte' => [1024, 'max:1'],
    'two kilobytes' => [2048, 'max:2'],
    'between two and three' => [2500, 'max:2.44140625'],
    'under a kilobyte' => [500, 'max:0.48828125'],
]);

it('reports the minimum size of a file, which no rule carries', function (): void {
    [, $findings] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true, minLength: 10));

    expect($findings)->toContain('`avatar` states `minLength`');
});

// `encoding` never reaches the contract (card #90), and the walk cannot tell a
// part that has one from a part that has none, so every file part says so.
it('says that encoding is not read for every file part', function (): void {
    [, $findings] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/png'));

    expect($findings)->toContain('`avatar` is a file part')
        ->toContain('`encoding.<part>.contentType` beside the body is not read');
});

it('asks for an optional file part with sometimes, and a nullable one with present', function (): void {
    [$optional] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true), required: false);
    [$nullable] = filePartRules(new Schema(types: [SchemaType::String, SchemaType::Null], isFilePart: true));

    expect($optional['avatar'])->toBe(['sometimes', 'file'])
        ->and($nullable['avatar'])->toBe(['present', 'nullable', 'file']);
});

it('lets a PATCH leave a file part out', function (): void {
    [$rules] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true), method: 'patch');

    expect($rules['avatar'])->toBe(['sometimes', 'file']);
});

it('puts the file rules under part.* for several files', function (): void {
    [$rules] = filePartRules(new Schema(
        types: [SchemaType::Array],
        items: new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/png', maxLength: 2048),
        minItems: 1,
        maxItems: 5,
    ));

    expect($rules['avatar'])->toBe(['present', 'array', 'list', 'min:1', 'max:5'])
        ->and($rules['avatar.*'])->toBe(['file', 'mimetypes:image/png', 'max:2']);
});

// A file in JSON is a string, and the contract reaching it through both media
// types gets the string, because no rule serves a file and a string at once.
it('reads a file part as a string unless multipart is the only body', function (array $mediaTypes): void {
    [$rules, $findings] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true), mediaTypes: $mediaTypes);

    expect($rules['avatar'])->toBe(['present', 'string'])
        ->and($findings)->toContain('`avatar` states `isFilePart`');
})->with([
    'JSON' => [['application/json']],
    'a form' => [['application/x-www-form-urlencoded']],
    'multipart and JSON' => [['multipart/form-data', 'application/json']],
]);

it('says why a mixed body reads its file parts as strings', function (): void {
    [, $findings] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true), mediaTypes: ['multipart/form-data', 'application/json']);

    expect($findings)->toContain('A part the schema marks as a file is then read as a string');
});

it('does not read a file part that states another type as one', function (): void {
    [$rules] = filePartRules(new Schema(types: [SchemaType::Integer], isFilePart: true));

    expect($rules['avatar'])->toBe(['required', 'integer']);
});

it('keeps format: byte a string', function (): void {
    [$rules, $findings] = filePartRules(new Schema(types: [SchemaType::String], format: 'byte'));

    expect($rules['avatar'])->toBe(['present', 'string'])
        ->and($findings)->toContain('`format: byte`');
});

/*
 * `readOnly` on a request.
 *
 * OpenAPI: `required` on a `readOnly` property applies to responses only, so a
 * request that leaves it out is a valid one.
 */

it('asks for no presence on a readOnly property the schema requires', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        [
            'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
            'name' => new Schema(types: [SchemaType::String]),
        ],
        ['id', 'name'],
    )]));

    expect($set->rules)->toBe([
        'id' => ['sometimes', 'integer'],
        'name' => ['present', 'string'],
    ])->and(implode("\n", $set->findings))->toContain('`id` is `readOnly` and required')
        ->and(implode("\n", $set->findings))->toContain('responses only');
});

it('keeps the other rules of a readOnly property, and still reads its allOf', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['id' => new Schema(allOf: [new Schema(types: [SchemaType::String], minLength: 3, readOnly: true)])],
        ['id'],
    )]));

    expect($set->rules)->toBe(['id' => ['sometimes', 'string', 'min:3']]);
});

it('asks a nested object for no readOnly key', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['address' => new Schema(
            types: [SchemaType::Object],
            properties: [
                'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
                'city' => new Schema(types: [SchemaType::String]),
            ],
            required: ['id', 'city'],
        )],
    )]));

    expect($set->rules['address'])->toBe(['sometimes', 'array', 'required_array_keys:city'])
        ->and(implode("\n", $set->findings))->toContain('`address.id` is `readOnly` and required');
});

it('does not make a readOnly property required through dependentRequired either', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(
        types: [SchemaType::Object],
        properties: [
            'card' => new Schema(types: [SchemaType::String]),
            'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
        ],
        dependentRequired: ['card' => ['id']],
    )]));

    expect($set->rules['id'])->toBe(['sometimes', 'integer']);
});

it('says nothing about a readOnly property that is not required', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['id' => new Schema(types: [SchemaType::Integer], readOnly: true)],
    )]));

    expect($set->rules)->toBe(['id' => ['sometimes', 'integer']])
        ->and($set->findings)->toBe([]);
});

// PHP rewrites a query name before Laravel sees it: `.` and a space become `_`,
// and `page[size]` becomes a nested array. No key a rule names is ever found, so
// the name is reported rather than given a rule that refuses or ignores every
// request.
it('reports a query name PHP rewrites, and gives it no rule', function (string $name): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [
            new QueryParameter($name, new Schema(types: [SchemaType::Integer]), required: true),
            new QueryParameter('limit', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect($set->rules)->toBe(['limit' => ['sometimes', 'integer']])
        ->and($set->queryKeys)->toBe(['limit'])
        ->and(implode("\n", $set->findings))->toContain('`'.$name.'`')
        ->and(implode("\n", $set->findings))->toContain('PHP rewrites');
})->with(['user.name', 'a b', 'page[size]']);

// Laravel's `mimetypes` reads the type libmagic sniffs from the content, and a
// real CSV, Markdown or YAML file sniffs as `text/plain` (an XML file as
// `text/xml`), so a rule for the type the contract names refuses every real file.
it('emits no mimetypes for a type the content cannot be sniffed as', function (string $declared): void {
    [$rules, $findings] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: $declared));

    expect($rules['avatar'])->toBe(['required', 'file'])
        ->and($findings)->toContain('`avatar` declares `contentMediaType: '.strtolower(explode(';', $declared)[0]).'`')
        ->and($findings)->toContain('sniffs');
})->with([
    'text/csv',
    'text/markdown',
    'text/*',
    'Text/CSV; charset=utf-8',
    'application/json',
    'application/xml',
    'application/ld+json',
    'application/yaml',
    'application/x-yaml',
]);

it('keeps mimetypes for a binary type the content is sniffed as', function (string $declared): void {
    [$rules] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: $declared));

    expect($rules['avatar'])->toBe(['required', 'file', 'mimetypes:'.$declared]);
})->with(['image/png', 'application/pdf', 'application/zip', 'image/svg+xml']);

// JSON Schema applies `properties` to objects only, and a body that names properties
// is an object whether or not it says `type: object`: generators that omit the type
// are common. Read as a bare value, it got no rule at all.
it('reads a body that names properties and no type as an object', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(
        properties: ['name' => new Schema(types: [SchemaType::String])],
        required: ['name'],
        additionalProperties: false,
    )]));

    expect($set->rules)->toBe(['name' => ['present', 'string']])
        ->and($set->closedKeys)->toBe(['name'])
        ->and(implode('', $set->findings))->not->toContain('not an object');
});

it('still reports a body that states no type and no properties', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(required: ['name'])]));

    expect($set->rules)->toBe([])
        ->and(implode('', $set->findings))->toContain('is not an object');
});

// The sentence explains why a file part is a string, so it is written only when the
// schema has one. Under a body with none it described something that was not there.
it('does not explain a file part the mixed body does not have', function (): void {
    [, $findings] = filePartRules(new Schema(types: [SchemaType::String]), mediaTypes: ['multipart/form-data', 'application/json']);

    expect($findings)->toContain('over one schema')
        ->and($findings)->not->toContain('A part the schema marks as a file');
});

it('explains a file part that sits inside a nested object, an array or an allOf', function (Schema $schema): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'multipart/form-data' => $schema,
        'application/json' => $schema,
    ]));

    expect(implode("\n", $set->findings))->toContain('A part the schema marks as a file');
})->with([
    'a nested object' => [objectSchema(['meta' => objectSchema(['doc' => new Schema(types: [SchemaType::String], isFilePart: true)])])],
    'an array' => [objectSchema(['docs' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String], isFilePart: true))])],
    'an allOf' => [new Schema(allOf: [objectSchema(['doc' => new Schema(types: [SchemaType::String], isFilePart: true)])])],
]);

/*
 * A boolean the way a form writes one. OpenAPI's default `form` style serializes
 * `true` as the text `true`, which Laravel's `boolean` refuses, so a query
 * parameter and a form-encoded or multipart field accept the four spellings.
 */

it('reads a boolean query parameter as the spellings a query string can carry', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [new QueryParameter('notify', new Schema(types: [SchemaType::Boolean]), required: true)],
    ));

    expect($set->rules)->toEqual(['notify' => ['required', new InRule(['true', 'false', '1', '0'])]])
        ->and(implode("\n", $set->findings))->toContain('`notify` is a boolean read from a query string')
        ->and(implode("\n", $set->findings))->toContain("`validated('notify')` holds the string as sent");
});

it('reads a boolean field of a form or multipart body the same way', function (string $mediaType): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [$mediaType => objectSchema(
        ['active' => new Schema(types: [SchemaType::Boolean, SchemaType::Null])],
    )]));

    expect($set->rules['active'])->toEqual(['sometimes', 'nullable', new InRule(['true', 'false', '1', '0'])])
        ->and(implode("\n", $set->findings))->toContain('`active` is a boolean read from a form body');
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

it('keeps boolean for a JSON body', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['active' => new Schema(types: [SchemaType::Boolean])],
    )]));

    expect($set->rules['active'])->toBe(['sometimes', 'boolean'])
        ->and($set->findings)->toBe([]);
});

// One rule set cannot hold `boolean` and the form's spellings at once without
// accepting `null`, so a body that is both keeps the JSON rule and says so.
it('keeps boolean for a body that is JSON and a form, and says what that refuses', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => objectSchema(['active' => new Schema(types: [SchemaType::Boolean])]),
        'application/x-www-form-urlencoded' => objectSchema(['active' => new Schema(types: [SchemaType::Boolean])]),
    ]));

    expect($set->rules['active'])->toBe(['sometimes', 'boolean'])
        ->and(implode("\n", $set->findings))->toContain('`active` is a boolean in a body that is JSON and also a form')
        ->and(implode("\n", $set->findings))->toContain('refuses the string `"true"`');
});

it('reads the booleans of an enumeration as every spelling of them', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [new QueryParameter('on', new Schema(types: [SchemaType::Boolean], enum: [true]))],
    ));

    expect($set->rules['on'])->toEqual(['sometimes', new InRule(['true', '1'])]);
});

// The one place a `mimetypes` rule is stricter than the contract: Laravel turns away a
// file named like PHP whatever its type, and no rule turns that off. The principle allows
// it when matching the contract would be far too complex, and the file says so.
it('says that mimetypes refuses a file named like PHP, and only when it emits mimetypes', function (): void {
    [, $typed] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/png'));
    [, $untyped] = filePartRules(new Schema(types: [SchemaType::String], isFilePart: true));

    expect($typed)->toContain('`avatar` has a `mimetypes` rule that is stricter than the contract in one case')
        ->and($typed)->toContain('PHP extension')
        ->and($untyped)->not->toContain('stricter than the contract');
});
