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
use Gcob\LaraSpecFirst\Generation\RuleSet;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;

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
 * @param  array<string, array<string, string>>  $encoding  media type, then part, then its `contentType`
 */
function operationWithInput(
    string $method = 'post',
    array $content = [],
    bool $bodyRequired = true,
    array $query = [],
    string $path = '/things',
    array $encoding = [],
): Operation {
    return new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: 'doThing',
        requestBody: $content === [] ? null : new RequestBody($content, $bodyRequired, $encoding),
        queryParameters: $query,
    );
}

/**
 * The rule set of an operation whose JSON body is one object schema.
 *
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $required
 * @return array<string, list<string|InRule|NullOrAbsent>>
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
    // A JSON body keeps its types, so `"42"` is not an integer and `true` is not one either: `:strict` is what
    // Laravel's loose `integer`, `numeric` and `boolean` do not do.
    'integer' => [SchemaType::Integer, 'integer:strict'],
    // `numeric` rather than `number`: Laravel has no rule by that name, and
    // `numeric` is what accepts both an integer and a float.
    'number' => [SchemaType::Number, 'numeric:strict'],
    'boolean' => [SchemaType::Boolean, 'boolean:strict'],
]);

it('keeps the loose scalar rules for a query parameter and a form field', function (): void {
    $scalars = [
        'count' => new Schema(types: [SchemaType::Integer]),
        'ratio' => new Schema(types: [SchemaType::Number]),
    ];

    // The trap: the body is JSON, and the query walk comes after it on the same operation.
    $queried = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['name' => new Schema(types: [SchemaType::String])])],
        query: [
            new QueryParameter('limit', $scalars['count']),
            new QueryParameter('scale', $scalars['ratio']),
        ],
    ))->rules;
    $form = RuleSetBuilder::for(operationWithInput(
        content: ['application/x-www-form-urlencoded' => objectSchema($scalars)],
    ))->rules;
    $multipart = RuleSetBuilder::for(operationWithInput(
        content: ['multipart/form-data' => objectSchema($scalars)],
    ))->rules;

    expect($queried['limit'])->toBe(['sometimes', 'integer'])
        ->and($queried['scale'])->toBe(['sometimes', 'numeric'])
        ->and($form['count'])->toBe(['sometimes', 'integer'])
        ->and($form['ratio'])->toBe(['sometimes', 'numeric'])
        ->and($multipart['count'])->toBe(['sometimes', 'integer'])
        ->and($multipart['ratio'])->toBe(['sometimes', 'numeric']);
});

// `a.b` and `items.*` are as much the body as a top-level key is.
it('reads every level of a JSON body as strict', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema([
        'a' => objectSchema(['b' => new Schema(types: [SchemaType::Integer])]),
        'items' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Number])),
        'flags' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Boolean])),
    ])]));

    expect($set->rules['a.b'])->toBe(['sometimes', 'integer:strict'])
        ->and($set->rules['items.*'])->toBe(['numeric:strict'])
        ->and($set->rules['flags.*'])->toBe(['boolean:strict']);
});

// One sentence per request, however many integers it has, and none when it has no integer to refuse a
// float for: the request is stricter than JSON Schema 2020-12 there, where `1.0` is an integer.
it('says once that a JSON integer written as a float is refused', function (): void {
    $json = static fn (array $properties) => RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema($properties)],
    ));
    $integer = new Schema(types: [SchemaType::Integer]);

    $two = $json(['one' => $integer, 'two' => $integer]);
    $none = $json(['ratio' => new Schema(types: [SchemaType::Number])]);
    $form = RuleSetBuilder::for(operationWithInput(
        content: ['application/x-www-form-urlencoded' => objectSchema(['one' => $integer])],
    ));

    $floats = array_values(array_filter(
        $two->findings,
        static fn (string $finding): bool => str_contains($finding, '`1.0`') && str_contains($finding, '`integer:strict`'),
    ));

    expect($floats)->toHaveCount(1)
        ->and($two->stricter)->toBe($floats)
        ->and($none->findings)->toBe([])
        ->and($none->stricter)->toBe([])
        ->and(implode("\n", $form->findings))->not->toContain('integer:strict')
        ->and($form->stricter)->toBe([]);
});

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
            SchemaType::Integer => 'integer:strict',
            SchemaType::Number => 'numeric:strict',
            SchemaType::Boolean => 'boolean:strict',
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
        'published' => [$booleanPresence, 'boolean:strict'],
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

// An empty content is refused by the request itself for a required body with no content type or a JSON one.
// What stays ambiguous is a form: its fields need not show in the raw content, so one with a type and no
// field passes, and the file says so wherever the body is required and read as a form.
it('says that an empty form is not read as an absent body when the body is required', function (array $media, bool $required, bool $says): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: array_fill_keys($media, objectSchema(['q' => new Schema(types: [SchemaType::String])])),
        bodyRequired: $required,
    ));

    expect(str_contains(implode("\n", $set->findings), 'a form with a `Content-Type` and no field'))->toBe($says);
})->with([
    'urlencoded, required' => [['application/x-www-form-urlencoded'], true, true],
    'multipart, required' => [['multipart/form-data'], true, true],
    'JSON and a form, required' => [['application/json', 'multipart/form-data'], true, true],
    'a form, optional' => [['multipart/form-data'], false, false],
    'JSON only, required' => [['application/json'], true, false],
]);

// An optional body with two required properties asks each through the other, so `{}` is accepted, and no rule
// can tell it from an absent body.
it('says that an optional body requiring two properties accepts an empty object', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(
            ['a' => new Schema(types: [SchemaType::String]), 'b' => new Schema(types: [SchemaType::String])],
            ['a', 'b'],
        )],
        bodyRequired: false,
    ));

    expect(implode("\n", $set->findings))->toContain('`{}` is accepted, which the contract refuses');
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

// A `query` parameter is merged into the data the body is validated against, so a key of the body's own
// failures that is its name would answer for both.
it('answers on a root key no query parameter has either', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['title' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('body', new Schema(types: [SchemaType::String]), required: true)],
    ));

    expect($set->rootKey)->toBe('_body');
});

// A form delivers `page[size]` below `page` and `user.name` as `user_name`, so a query parameter
// with that name is the same key to Laravel, and validationData() drops the body's copy of it.
it('refuses a query parameter named like the key a form delivers a body name under', function (array $media, string $body, string $query, string $delivered): void {
    $schema = objectSchema([$body => new Schema(types: [SchemaType::Integer])], [$body]);
    $build = fn () => RuleSetBuilder::for(operationWithInput(
        content: array_fill_keys($media, $schema),
        query: [new QueryParameter($query, new Schema(types: [SchemaType::Integer]))],
    ));

    expect($build)->toThrow(ConflictingInputException::class, sprintf(
        'declares "%s" as a property of its request body, which a form delivers under the key "%s", and "%s" as a `query` parameter',
        $body,
        $delivered,
        $query,
    ));
})->with([
    'a bracketed name, form only' => [['application/x-www-form-urlencoded'], 'page[size]', 'page', 'page'],
    'a dotted name, form only' => [['multipart/form-data'], 'user.name', 'user_name', 'user_name'],
    'a dotted name, JSON and form' => [['application/json', 'multipart/form-data'], 'user.name', 'user_name', 'user_name'],
]);

it('leaves a dotted body name beside a query parameter alone when the body is JSON only', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['user.name' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('user_name', new Schema(types: [SchemaType::String]))],
    ));

    expect(array_keys($set->rules))->toBe(['user\\.name', 'user_name']);
});

it('refuses two read media types describing two different shapes', function (): void {
    expect(fn () => RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => objectSchema(['a' => new Schema(types: [SchemaType::String])]),
        'multipart/form-data' => objectSchema(['b' => new Schema(types: [SchemaType::String])]),
    ])))->toThrow(ConflictingInputException::class, 'cannot hold two rule sets');
});

// The comparison is strict: `==` called `null` and `0`, `null` and `false`, `[0]` and `[false]` equal, so a
// constraint written on one media type only was dropped for the other. They are two schemas, and two rule sets.
it('refuses two read media types whose shapes differ only by a value loose equality would forgive', function (Schema $one, Schema $other): void {
    expect(fn () => RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => objectSchema(['a' => $one]),
        'multipart/form-data' => objectSchema(['a' => $other]),
    ])))->toThrow(ConflictingInputException::class, 'cannot hold two rule sets');
})->with([
    'minimum: 0 on one side' => [
        new Schema(types: [SchemaType::Integer], minimum: 0),
        new Schema(types: [SchemaType::Integer]),
    ],
    'minLength: 0 on one side' => [
        new Schema(types: [SchemaType::String], minLength: 0),
        new Schema(types: [SchemaType::String]),
    ],
    'additionalProperties: false on one side' => [
        new Schema(types: [SchemaType::Object], additionalProperties: false),
        new Schema(types: [SchemaType::Object]),
    ],
    'enum [0] against [false]' => [
        new Schema(enum: [0]),
        new Schema(enum: [false]),
    ],
]);

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

// An ignored keyword is written at a position, and two media types writing the
// same shape inline write it at two. The position is what the doctor reports and
// no rule is built from it, so it is not a difference between the two shapes.
it('accepts two read media types whose shapes differ only in where an ignored keyword is written', function (): void {
    $at = static fn (string $media): Schema => objectSchema([
        'a' => new Schema(types: [SchemaType::String], ignored: ['default' => '#/'.$media.'/properties/a/default']),
    ]);

    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $at('json'),
        'multipart/form-data' => $at('multipart'),
    ]));

    expect($set->rules)->toBe(['a' => ['sometimes', 'string']]);
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
 * @return list<string|InRule|NullOrAbsent>
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
        ['sometimes', 'integer:strict', 'min:1', 'max:9'],
    ],
    'exclusiveMinimum, exclusiveMaximum' => [
        new Schema(types: [SchemaType::Number], exclusiveMinimum: 0.0, exclusiveMaximum: 1.5),
        ['sometimes', 'numeric:strict', 'gt:0', 'lt:1.5'],
    ],
    'multipleOf' => [
        new Schema(types: [SchemaType::Number], multipleOf: 0.25),
        ['sometimes', 'numeric:strict', 'multiple_of:0.25'],
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
    // `int32` is a range, so it is two bounds, and a bound the contract states beside it is kept when it is tighter.
    'format: int32' => [
        new Schema(types: [SchemaType::Integer], format: 'int32'),
        ['sometimes', 'integer:strict', 'min:-2147483648', 'max:2147483647'],
    ],
    'format: int32 with tighter bounds' => [
        new Schema(types: [SchemaType::Integer], format: 'int32', minimum: 1.0, maximum: 100.0),
        ['sometimes', 'integer:strict', 'min:1', 'max:100'],
    ],
    'format: int32 with looser bounds' => [
        new Schema(types: [SchemaType::Integer], format: 'int32', minimum: -5000000000.0, maximum: 5000000000.0),
        ['sometimes', 'integer:strict', 'min:-2147483648', 'max:2147483647'],
    ],
    'format: int32 with one tighter bound' => [
        new Schema(types: [SchemaType::Integer], format: 'int32', maximum: 10.0),
        ['sometimes', 'integer:strict', 'min:-2147483648', 'max:10'],
    ],
    // `integer` is PHP's `FILTER_VALIDATE_INT`, which is the 64-bit range, and `numeric` takes any JSON number.
    'format: int64 on an integer' => [new Schema(types: [SchemaType::Integer], format: 'int64'), ['sometimes', 'integer:strict']],
    'format: double on a number' => [new Schema(types: [SchemaType::Number], format: 'double'), ['sometimes', 'numeric:strict']],
    // An annotation for a form to hide what is typed: it asserts nothing.
    'format: password' => [new Schema(types: [SchemaType::String], format: 'password'), ['sometimes', 'string']],
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
        'address.floor' => ['sometimes', 'integer:strict'],
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
        'code' => ['present_with:street,city', 'integer:strict'],
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
        'age' => ['sometimes', 'integer:strict'],
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

    expect($set->rules['n'])->toBe(['sometimes', 'integer:strict']);
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
    'a file part in a JSON body' => [new Schema(types: [SchemaType::String], isFilePart: true), '`field` states `format`'],
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
 * @return array{0: array<string, list<string|InRule|NullOrAbsent>>, 1: string}
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

// A file part keeps `present_with` rather than `required`: the swap to `required` fires on `present` alone, and
// `required_with` would let a blank sibling trigger nothing. What refuses an empty slot is `file`, after it.
it('asks for a required file part of an optional body with present_with naming the other parts', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['multipart/form-data' => objectSchema([
            'avatar' => new Schema(types: [SchemaType::String], isFilePart: true),
            'caption' => new Schema(types: [SchemaType::String]),
            'alt' => new Schema(types: [SchemaType::String]),
        ], ['avatar', 'caption'])],
        bodyRequired: false,
    ));

    expect($set->rules)->toBe([
        'avatar' => ['present_with:caption,alt', 'file'],
        'caption' => ['present_with:avatar,alt', 'string'],
        'alt' => ['sometimes', 'string'],
    ]);
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

/*
 * `encoding.<part>.contentType`: what narrows a file part, ahead of `contentMediaType`.
 *
 * Only a top-level part of a multipart body is read, by the name the contract writes. A part the body does not read as
 * a file (a non-file part, a mixed body, a form) is reported unless its type is OpenAPI's default for it, and the
 * document's own faults (a part the schema does not declare) go in the generated file alone.
 */
it('narrows a file part from encoding', function (
    array $properties,
    array $encoding,
    array $mediaTypes,
    array $rules,
    array $says,
    array $unenforced,
    ?array $formEncoding = null,
): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: array_fill_keys($mediaTypes, objectSchema($properties)),
        encoding: ['multipart/form-data' => $encoding, 'application/x-www-form-urlencoded' => $formEncoding ?? $encoding],
    ));
    $findings = implode("\n", $set->findings);

    expect($set->rules)->toBe($rules)
        ->and($set->unenforced)->toEqual($unenforced)
        ->and($findings)->not->toContain('`encoding.<part>.contentType` beside the body is not read');

    foreach ($says as $sentence) {
        expect($findings)->toContain($sentence);
    }
})->with(function (): array {
    $file = new Schema(types: [SchemaType::String], isFilePart: true);
    $gif = new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/gif');
    $text = new Schema(types: [SchemaType::String]);
    $multipart = ['multipart/form-data'];

    return [
        'a list' => [['avatar' => $file], ['avatar' => 'image/png, image/jpeg'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png,image/jpeg']], ['A `mimetypes` rule'], []],
        'a single type' => [['avatar' => $file], ['avatar' => 'image/png'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png']], [], []],
        'a wildcard' => [['avatar' => $file], ['avatar' => 'image/*'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/*']], [], []],
        'parameters, case and spaces dropped' => [['avatar' => $file], ['avatar' => ' Image/PNG; q=0.8 ,image/jpeg;q=0.5'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png,image/jpeg']], [], []],
        'a type written twice' => [['avatar' => $file], ['avatar' => 'image/png, IMAGE/png;q=0.5, image/jpeg'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png,image/jpeg']], [], []],
        'any bytes in a list' => [['avatar' => $file], ['avatar' => 'image/png, application/octet-stream'], $multipart,
            ['avatar' => ['sometimes', 'file']], [], []],
        'the any-type wildcard in a list' => [['avatar' => $file], ['avatar' => '*/*; q=0.1, image/png'], $multipart,
            ['avatar' => ['sometimes', 'file']], [], []],
        'any bytes beside a text type' => [['avatar' => $file], ['avatar' => 'text/csv, application/octet-stream'], $multipart,
            ['avatar' => ['sometimes', 'file']], [], []],
        'a text type in a list' => [['avatar' => $file], ['avatar' => 'image/png, text/csv'], $multipart,
            ['avatar' => ['sometimes', 'file']],
            ['The `encoding` of `avatar` declares `contentType: image/png, text/csv`, and no `mimetypes` rule is emitted for it',
                'a rule listing it would refuse every real file of that type'],
            ['encoding' => ['`avatar`']]],
        'a text wildcard, which Laravel matches' => [['avatar' => $file], ['avatar' => 'image/png, Text/*'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png,text/*']], [], []],
        'no contentType, so contentMediaType applies' => [['avatar' => $gif], [], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/gif']], [], []],
        'a contentType for another part, so contentMediaType applies' => [['avatar' => $gif, 'note' => $text], ['note' => 'text/plain'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/gif'], 'note' => ['sometimes', 'string']], [], []],
        'a blank contentType, so contentMediaType applies' => [['avatar' => $gif], ['avatar' => ' , '], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/gif']], [], []],
        'encoding over contentMediaType' => [['avatar' => $gif], ['avatar' => 'image/png'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png']], [], []],
        'encoding over contentMediaType, though it is looser' => [['avatar' => $gif], ['avatar' => 'application/octet-stream'], $multipart,
            ['avatar' => ['sometimes', 'file']], [], []],
        'encoding over a contentMediaType no rule can match' => [
            ['avatar' => new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'text/csv')],
            ['avatar' => 'image/png'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png']], [], []],
        'an array of files' => [
            ['avatar' => new Schema(types: [SchemaType::Array], items: $gif)],
            ['avatar' => 'image/png'], $multipart,
            ['avatar' => ['sometimes', 'array', 'list'], 'avatar.*' => ['file', 'mimetypes:image/png']], [], []],
        'an array of non-files' => [
            ['tags' => new Schema(types: [SchemaType::Array], items: $text)],
            ['tags' => 'image/png'], $multipart,
            ['tags' => ['sometimes', 'array', 'list'], 'tags.*' => ['string']],
            ['The `encoding` of `tags` declares `contentType: image/png`'], ['encoding' => ['`tags`']]],
        'a non-file part' => [['name' => $text], ['name' => 'image/png'], $multipart,
            ['name' => ['sometimes', 'string']],
            ['The `encoding` of `name` declares `contentType: image/png`', 'not a file part'], ['encoding' => ['`name`']]],
        'a nested object, which is a part of its own' => [
            ['meta' => objectSchema(['doc' => $file])], ['meta' => 'image/png'], $multipart,
            ['meta' => ['sometimes', 'array'], 'meta.doc' => ['sometimes', 'file']],
            ['The `encoding` of `meta` declares `contentType: image/png`'], ['encoding' => ['`meta`']]],
        'a nested part is not named by its own name' => [
            ['meta' => objectSchema(['doc' => $file])], ['doc' => 'image/png'], $multipart,
            ['meta' => ['sometimes', 'array'], 'meta.doc' => ['sometimes', 'file']],
            ['`encoding` names `doc`, which the body does not declare'], []],
        'a part the schema does not declare' => [['avatar' => $file], ['avatar' => 'image/png', 'ghost' => 'image/png'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png']],
            ['`encoding` names `ghost`, which the body does not declare'], []],
        'the defaults OpenAPI gives, spelled out' => [
            [
                'data' => objectSchema([]),
                'note' => $text,
                'count' => new Schema(types: [SchemaType::Integer]),
                'tags' => new Schema(types: [SchemaType::Array], items: $text),
                'rows' => new Schema(types: [SchemaType::Array], items: objectSchema([])),
                'doc' => $file,
            ],
            ['data' => 'application/json; charset=utf-8', 'note' => 'text/plain', 'count' => 'Text/Plain', 'tags' => 'text/plain', 'rows' => 'application/json', 'doc' => 'application/octet-stream'],
            $multipart,
            ['data' => ['sometimes', 'array'], 'note' => ['sometimes', 'string'], 'count' => ['sometimes', 'integer'],
                'tags' => ['sometimes', 'array', 'list'], 'tags.*' => ['string'], 'rows' => ['sometimes', 'array', 'list'], 'rows.*' => ['array'],
                'doc' => ['sometimes', 'file']],
            [], []],
        // The versions disagree (3.1.0 gives a primitive `application/octet-stream`, 3.0 and 3.1.1 `text/plain`), so a
        // contract is never asked which one it meant: every type written being one of them is silent.
        'the default of another version, spelled out' => [
            ['note' => $text, 'count' => new Schema(types: [SchemaType::Integer]), 'free' => new Schema],
            ['note' => 'application/octet-stream', 'count' => 'text/plain, application/octet-stream', 'free' => 'application/octet-stream'],
            $multipart,
            ['note' => ['sometimes', 'string'], 'count' => ['sometimes', 'integer'], 'free' => ['sometimes']],
            [], []],
        'a file part in a form, with the default 3.1.1 gives a string' => [['avatar' => $file], ['avatar' => 'text/plain'], ['application/x-www-form-urlencoded'],
            ['avatar' => ['sometimes', 'string']], [], ['format' => ['`avatar`']]],
        'an object is not given a primitive default' => [['data' => objectSchema([])], ['data' => 'text/plain'], $multipart,
            ['data' => ['sometimes', 'array']], ['The `encoding` of `data` declares `contentType: text/plain`'], ['encoding' => ['`data`']]],
        'a default beside another type is not the default' => [['note' => $text], ['note' => 'text/plain, image/png'], $multipart,
            ['note' => ['sometimes', 'string']], ['The `encoding` of `note` declares'], ['encoding' => ['`note`']]],
        'a plain part in a form' => [['name' => $text], ['name' => 'text/csv'], ['application/x-www-form-urlencoded'],
            ['name' => ['sometimes', 'string']],
            ['The `encoding` of `name` declares `contentType: text/csv`', 'it is not a file part'], ['encoding' => ['`name`']]],
        'a file part in a form' => [['avatar' => $file], ['avatar' => 'image/png'], ['application/x-www-form-urlencoded'],
            ['avatar' => ['sometimes', 'string']],
            ['The `encoding` of `avatar` declares `contentType: image/png`', 'a form-encoded body is read as text'],
            ['encoding' => ['`avatar`'], 'format' => ['`avatar`']]],
        'a file part in a mixed body' => [['avatar' => $file], ['avatar' => 'image/png'], ['multipart/form-data', 'application/json'],
            ['avatar' => ['sometimes', 'string']],
            ['The `encoding` of `avatar` declares `contentType: image/png`', 'the body is also JSON, which reads a file part as a string'],
            ['encoding' => ['`avatar`'], 'format' => ['`avatar`']]],
        'a plain part in a mixed body' => [['name' => $text], ['name' => 'image/png'], ['multipart/form-data', 'application/json'],
            ['name' => ['sometimes', 'string']],
            ['The `encoding` of `name` declares `contentType: image/png`', 'it is not a file part'], ['encoding' => ['`name`']]],
        // The two media types `encoding` applies to are read in a fixed order, multipart first. A part is read as a
        // file only when the body is multipart alone, and then the form-encoded one is not read at all.
        'a part written under both, in a multipart body alone' => [['avatar' => $file], ['avatar' => 'image/png'], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/png']], [], [], ['avatar' => 'image/gif']],
        'a part written under both, in a body that is both' => [
            ['avatar' => $file], ['avatar' => 'image/png'], ['multipart/form-data', 'application/x-www-form-urlencoded'],
            ['avatar' => ['sometimes', 'string']],
            ['The `encoding` of `avatar` declares `contentType: image/png, image/gif`'],
            ['encoding' => ['`avatar`'], 'format' => ['`avatar`']], ['avatar' => 'image/gif']],
        'a part written under both with the default, in a body that is both' => [
            ['note' => $text], ['note' => 'text/plain'], ['multipart/form-data', 'application/x-www-form-urlencoded'],
            ['note' => ['sometimes', 'string']], [], [], ['note' => 'Text/Plain; charset=utf-8']],
        'a part written under both, one of them not the default' => [
            ['note' => $text], ['note' => 'text/plain'], ['multipart/form-data', 'application/x-www-form-urlencoded'],
            ['note' => ['sometimes', 'string']],
            ['The `encoding` of `note` declares `contentType: text/plain, image/png`'], ['encoding' => ['`note`']], ['note' => 'image/png']],
        // The specification says one comma-separated string. A YAML list or a number is a fault in the document, said
        // in the generated file, and read as nothing: never dropped quietly, never turned into a rule.
        'a contentType written as a YAML list' => [['avatar' => $file], ['avatar' => ['image/png', 'image/jpeg']], $multipart,
            ['avatar' => ['sometimes', 'file']],
            ['`encoding` writes a `contentType` for `avatar` that is not a string: that is a fault in the document, and nothing is read from it'],
            []],
        'a contentType written as a number' => [['avatar' => $file], ['avatar' => 5], $multipart,
            ['avatar' => ['sometimes', 'file']],
            ['`encoding` writes a `contentType` for `avatar` that is not a string'], []],
        'a contentType that is not a string, over a contentMediaType' => [['avatar' => $gif], ['avatar' => ['image/png']], $multipart,
            ['avatar' => ['sometimes', 'file', 'mimetypes:image/gif']],
            ['`encoding` writes a `contentType` for `avatar` that is not a string'], []],
        'a contentType that is not a string, on a part the schema does not declare' => [['avatar' => $file], ['ghost' => ['image/png']], $multipart,
            ['avatar' => ['sometimes', 'file']],
            ['`encoding` names `ghost`, which the body does not declare'], []],
        'JSON, where encoding does not apply' => [['avatar' => $file], ['avatar' => 'image/png'], ['application/json'],
            ['avatar' => ['sometimes', 'string']], [], ['format' => ['`avatar`']]],
    ];
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
        // `isFilePart` is no word of a contract: it is `format: binary` here, which is what the finding names.
        ->and($findings)->toContain('`avatar` states `format`')
        ->and($findings)->not->toContain('isFilePart');
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
 * OpenAPI 3.0: `required` on a `readOnly` property applies to responses only, so a
 * request that leaves it out is a valid one. 3.1 defers to JSON Schema, where `readOnly` is an
 * annotation, and the package reads a 3.1 document the same way.
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
        'id' => ['sometimes', 'integer:strict'],
        'name' => ['present', 'string'],
    ])->and(implode("\n", $set->findings))->toContain('`id` is `readOnly` and required')
        // 3.0 states it; 3.1 dropped the sentence, so a 3.1 document is read the same way, not by rule.
        ->and(implode("\n", $set->findings))->toContain('3.0 states it, and a 3.1 document is read the same way');
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

    expect($set->rules['id'])->toBe(['sometimes', 'integer:strict']);
});

it('says nothing about a readOnly property that is not required', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['id' => new Schema(types: [SchemaType::Integer], readOnly: true)],
    )]));

    // The integral-float sentence is all it says: nothing about `id` itself.
    expect($set->rules)->toBe(['id' => ['sometimes', 'integer:strict']])
        ->and($set->findings)->toBe($set->stricter);
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
    // A wildcard is matched on the type's first half, which a text file's sniffed type has: `text/*` holds for a real CSV.
})->with(['image/png', 'application/pdf', 'application/zip', 'image/svg+xml', 'text/*']);

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

// The one place a request is stricter than the contract on purpose: the schema accepts the JSON
// string `"Ada"` there, and a rule set cannot say "an object, or anything else" at the root.
it('says a body with properties and no type refuses a body that is not an object', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => new Schema(
        properties: ['name' => new Schema(types: [SchemaType::String])],
    )]));

    expect(implode("\n", $set->findings))->toContain('names `properties` and no `type`, so it is read as an object')
        ->and(implode("\n", $set->findings))->toContain('stricter than the contract');
});

// A form cannot send a root that is not an object, so the finding is true of a JSON body only.
it('says nothing about a non-object root of a body with properties and no type that is only a form', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        properties: ['name' => new Schema(types: [SchemaType::String])],
    )]));

    expect(implode("\n", $set->findings))->not->toContain('no `type`')
        ->and($set->jsonRoot)->toBeFalse();
});

it('has the request check the root of an object body read as JSON', function (): void {
    $typed = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['name' => new Schema(types: [SchemaType::String])],
    )]));
    $both = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => objectSchema(['name' => new Schema(types: [SchemaType::String])]),
        'multipart/form-data' => objectSchema(['name' => new Schema(types: [SchemaType::String])]),
    ]));

    expect($typed->jsonRoot)->toBeTrue()
        ->and($typed->nullableRoot)->toBeFalse()
        ->and($both->jsonRoot)->toBeTrue();
});

it('says nothing of the kind when the body states type: object', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['name' => new Schema(types: [SchemaType::String])],
    )]));

    expect(implode("\n", $set->findings))->not->toContain('no `type`');
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
        ->and(implode("\n", $set->findings))->toContain("`validated('notify')` holds the string as sent")
        // The contract writes `true` and `false`: the finding owns up to the two more, and to the
        // read that works, since `"false"` is a truthy string.
        ->and(implode("\n", $set->findings))->toContain('`"1"` and `"0"` are accepted too')
        // `filter_var(null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)` is `false`, so an absent
        // parameter would read as `false`: the suggested read asks `has()` first.
        ->and(implode("\n", $set->findings))->toContain('`$request->query->has(\'notify\') ? filter_var($request->validated(\'notify\'), FILTER_VALIDATE_BOOL) : null`')
        ->and(implode("\n", $set->findings))->toContain('`null` when it was not sent');
});

it('reads a boolean field of a form or multipart body the same way', function (string $mediaType): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [$mediaType => objectSchema(
        ['active' => new Schema(types: [SchemaType::Boolean, SchemaType::Null])],
    )]));

    expect($set->rules['active'])->toEqual(['sometimes', 'nullable', new InRule(['true', 'false', '1', '0'])])
        ->and(implode("\n", $set->findings))->toContain('`active` is a boolean read from a form body')
        ->and(implode("\n", $set->findings))->toContain('`"1"` and `"0"` are accepted too');
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

it('reads a boolean of a JSON body as a boolean and nothing looser', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['active' => new Schema(types: [SchemaType::Boolean])],
    )]));

    expect($set->rules['active'])->toBe(['sometimes', 'boolean:strict'])
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

    expect($typed)->toContain('A `mimetypes` rule in this request is stricter than the contract in one case')
        ->and($typed)->toContain('PHP extension')
        ->and($untyped)->not->toContain('stricter than the contract');
});

it('says it once for a request with several typed file parts', function (): void {
    $png = new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/png');
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(['a' => $png, 'b' => $png])]));

    expect(substr_count(implode("\n", $set->findings), 'stricter than the contract'))->toBe(1);
});

/*
 * PHP renames a name before Laravel sees a form-encoded or multipart body, the way it does a
 * query string (measured with a real PHP SAPI, `curl -d` and `curl -F`: `user.name` arrives as
 * `user_name`, `a b` as `a_b`, `page[size]` as `page => [size => ...]`), and only the top-level
 * name: `address[a.b]` keeps its dot. A JSON body keeps every name as written.
 */

it('keys the rules of a top-level body name PHP rewrites by the name a form delivers', function (string $mediaType): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [$mediaType => objectSchema(
        [
            'user.name' => new Schema(types: [SchemaType::String]),
            'a b' => new Schema(types: [SchemaType::String]),
            'page[size]' => new Schema(types: [SchemaType::Integer]),
            'plain' => new Schema(types: [SchemaType::String]),
            'address' => objectSchema(['a.b' => new Schema(types: [SchemaType::String])]),
        ],
        ['user.name', 'a b', 'plain'],
    )]));

    expect($set->rules)->toBe([
        'user_name' => ['present', 'string'],
        'a_b' => ['present', 'string'],
        'page.size' => ['sometimes', 'integer'],
        'plain' => ['present', 'string'],
        'address' => ['sometimes', 'array'],
        'address.a\.b' => ['sometimes', 'string'],
    ])
        // What `dto()` reads the value from, by the name the contract writes.
        ->and($set->formPaths)->toBe(['user.name' => ['user_name'], 'a b' => ['a_b'], 'page[size]' => ['page', 'size']])
        ->and($set->uncheckedNames)->toBe([])
        ->and(implode("\n", $set->findings))->not->toContain('rewrites');
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

it('escapes a dot a delivered path keeps below the top level', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['address[a.b]' => new Schema(types: [SchemaType::String])],
    )]));

    expect($set->rules)->toBe(['address.a\.b' => ['sometimes', 'string']])
        ->and($set->formPaths)->toBe(['address[a.b]' => ['address', 'a.b']]);
});

it('keeps the rule for a dotted name in a JSON body', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema(
        ['user.name' => new Schema(types: [SchemaType::String])],
        ['user.name'],
    )]));

    expect($set->rules)->toBe(['user\.name' => ['present', 'string']])
        ->and($set->formPaths)->toBe([])
        ->and($set->uncheckedNames)->toBe([]);
});

// One rule set serves both, and no key finds both names: the JSON name keeps its type, and what a
// form client sends is not checked. A presence rule would refuse every form client, which is
// stricter than the contract, so a required name is not asked for.
it('keeps the type of the JSON name when the body is also a form, and says where a form value arrives', function (): void {
    $schema = objectSchema(['user.name' => new Schema(types: [SchemaType::String])], ['user.name']);
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $schema,
        'application/x-www-form-urlencoded' => $schema,
    ]));

    expect($set->rules)->toBe(['user\.name' => ['sometimes', 'string']])
        ->and($set->formPaths)->toBe([])
        ->and(array_keys($set->uncheckedNames))->toBe(['user.name'])
        ->and(implode("\n", $set->findings))
        ->toContain('`user.name` is a property of a body that is JSON and a form at once')
        ->toContain('A form client sends it as `user.name`, which PHP delivers as `user_name`')
        ->toContain('not checked there')
        // The JSON client loses it too: one rule set serves both, and `rules()` does not branch on the media type.
        ->toContain('not asked for from a JSON client either')
        ->toContain('a JSON body that leaves it out is accepted, which the contract refuses');
});

it('names a delivered path in the finding of a mixed body', function (): void {
    $schema = objectSchema(['page[size]' => new Schema(types: [SchemaType::Integer])]);
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $schema,
        'multipart/form-data' => $schema,
    ]));

    expect(implode("\n", $set->findings))->toContain('A form client sends it as `page[size]`, which PHP delivers as `page.size`')
        ->and($set->rules)->toBe(['page[size]' => ['sometimes', 'integer']]);
});

// Two contract names PHP delivers as one key, or a declared name equal to the delivered one, cannot
// be told apart in the data: no rule for any of them, one finding naming both.
it('gives no rule to names PHP delivers as one key, and names both', function (array $names): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        [...array_fill_keys($names, new Schema(types: [SchemaType::String])), 'plain' => new Schema(types: [SchemaType::String])],
        array_values($names),
    )]));

    expect($set->rules)->toBe(['plain' => ['sometimes', 'string']])
        ->and(array_keys($set->uncheckedNames))->toBe($names)
        ->and($set->formPaths)->toBe([])
        ->and(implode("\n", $set->findings))
        ->toContain('`'.$names[0].'` and `'.$names[1].'` reach Laravel under one name, `');
})->with([
    'two rewritten names' => [['a b', 'a.b']],
    'a rewritten and a plain name' => [['user.name', 'user_name']],
]);

// A name delivered below a top-level key another name uses: `page[size]` is `page => [size => ...]`.
it('gives no rule to a bracketed name delivered below a declared top-level name', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['page' => new Schema(types: [SchemaType::String]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'plain' => new Schema(types: [SchemaType::String])],
    )]));

    expect($set->rules)->toBe(['plain' => ['sometimes', 'string']])
        ->and(array_keys($set->uncheckedNames))->toBe(['page[size]', 'page'])
        ->and(implode("\n", $set->findings))->toContain('`page[size]` is delivered by a form as `page.size`, below the key `page` that `page` uses');
});

// The same collisions in a body that is JSON too: a rule for `page` judges the array a form builds from
// `page[size]`, so a valid form client would be refused. The names go without a rule, as in a form alone.
it('gives colliding names no rule when the body is also JSON, and names both', function (array $properties, array $names, string $finding): void {
    $schema = objectSchema([...$properties, 'plain' => new Schema(types: [SchemaType::String])]);
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $schema,
        'multipart/form-data' => $schema,
    ]));

    expect($set->rules)->toBe(['plain' => ['sometimes', 'string']])
        ->and(array_keys($set->uncheckedNames))->toEqual($names)
        ->and(implode("\n", $set->findings))->toContain($finding);
})->with([
    'a bracketed name below a declared one' => [
        ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer])],
        ['page[size]', 'page'],
        '`page[size]` is delivered by a form as `page.size`, below the key `page` that `page` uses',
    ],
    'a rewritten and a plain name' => [
        ['user.name' => new Schema(types: [SchemaType::String]), 'user_name' => new Schema(types: [SchemaType::String])],
        ['user.name', 'user_name'],
        '`user.name` and `user_name` reach Laravel under one name, `user_name`',
    ],
]);

it('lets two bracketed names share the top-level key they are delivered below', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['page[size]' => new Schema(types: [SchemaType::Integer]), 'page[number]' => new Schema(types: [SchemaType::Integer])],
    )]));

    expect($set->rules)->toBe(['page.size' => ['sometimes', 'integer'], 'page.number' => ['sometimes', 'integer']]);
});

it('keeps the rule of a name PHP does not rewrite beside one it does', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['user.name' => new Schema(types: [SchemaType::String]), 'user_id' => new Schema(types: [SchemaType::Integer])],
    )]));

    expect($set->rules)->toBe(['user_name' => ['sometimes', 'string'], 'user_id' => ['sometimes', 'integer']]);
});

// An optional body asks for `plain` when any other key is sent, and a form sends `user.name` as
// `user_name`: the sibling is named by the key that carries it.
it('names the delivered key of a sibling an optional body waits on', function (array $media, string $expected): void {
    $schema = objectSchema(
        ['plain' => new Schema(types: [SchemaType::String]), 'user.name' => new Schema(types: [SchemaType::String])],
        ['plain'],
    );
    $set = RuleSetBuilder::for(operationWithInput(
        content: array_fill_keys($media, $schema),
        bodyRequired: false,
    ));

    expect($set->rules['plain'])->toBe([$expected, 'string']);
})->with([
    'a form' => [['multipart/form-data'], 'present_with:user_name'],
    'JSON' => [['application/json'], 'present_with:user\.name'],
    'both' => [['application/json', 'multipart/form-data'], 'present_with:user\.name,user_name'],
]);

it('names the delivered key of a dependentRequired trigger', function (array $media, string $expected): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['user.name' => new Schema(types: [SchemaType::String]), 'email' => new Schema(types: [SchemaType::String])],
        dependentRequired: ['user.name' => ['email']],
    );
    $set = RuleSetBuilder::for(operationWithInput(content: array_fill_keys($media, $schema)));

    expect($set->rules['email'])->toBe([$expected, 'string']);
})->with([
    'a form' => [['application/x-www-form-urlencoded'], 'present_with:user_name'],
    'JSON' => [['application/json'], 'present_with:user\.name'],
    'both' => [['application/json', 'application/x-www-form-urlencoded'], 'present_with:user\.name,user_name'],
]);

// The trigger of a `dependentRequired` that has no rule loses what it asked for, and the finding
// says which dependency: once, where it used to be said twice and about the name alone.
it('says once which dependency a trigger without a rule loses', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: [
            'a b' => new Schema(types: [SchemaType::String]),
            'a.b' => new Schema(types: [SchemaType::String]),
            'email' => new Schema(types: [SchemaType::String]),
        ],
        dependentRequired: ['a b' => ['email']],
    )]));

    expect($set->rules['email'])->toBe(['sometimes', 'string'])
        ->and(substr_count(implode("\n", $set->findings), 'would make `email` required'))->toBe(1)
        ->and(implode("\n", $set->findings))->toContain('`a b` would make `email` required');
});

// A name that a form client sends under another key loses its presence rule, and the contract asks for it
// differently when it is required and when only a trigger makes it so.
it('words what a mixed body loses for a required name and for a dependent one', function (): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: [
            'user.name' => new Schema(types: [SchemaType::String]),
            'a.b' => new Schema(types: [SchemaType::String]),
            'trigger' => new Schema(types: [SchemaType::String]),
        ],
        required: ['user.name'],
        dependentRequired: ['trigger' => ['a.b']],
    );
    $findings = implode("\n", RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $schema,
        'multipart/form-data' => $schema,
    ]))->findings);

    expect($findings)->toContain('The contract requires it, and a presence rule')
        ->and($findings)->toContain('The contract requires it when `trigger` is sent, and a presence rule')
        ->and(substr_count($findings, 'The contract requires it, and'))->toBe(1)
        ->and($findings)->toContain('a JSON body that sends the trigger and leaves it out is accepted');
});

// The closed root accepts the head PHP delivers a bracketed name under, so the head gets a rule of
// its own that closes it to the keys the contract declares below it.
it('closes the head of a bracketed name in a closed form body', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: [
            'page[size]' => new Schema(types: [SchemaType::Integer]),
            'page[number]' => new Schema(types: [SchemaType::Integer]),
            'q' => new Schema(types: [SchemaType::String]),
        ],
        additionalProperties: false,
    )]));

    expect($set->rules['page'])->toBe(['sometimes', 'array:size,number'])
        ->and($set->closedKeys)->toBe(['page', 'q'])
        ->and(implode("\n", $set->findings))->not->toContain('closed to its declared names');
});

// A bracketed name closes every level above its leaf, so `filter[evil]=1`, `filter=abc` and
// `filter[created][lt]=5` are refused, while every name the contract declares still fits.
it('closes every level above a name bracketed more than once', function (array $properties, array $expected): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: array_map(static fn (): Schema => new Schema(types: [SchemaType::Integer]), array_flip($properties)),
        additionalProperties: false,
    )]));

    foreach ($expected as $key => $rule) {
        expect($set->rules[$key] ?? null)->toBe($rule);
    }

    expect($set->closedKeys)->toBe(['filter'])
        ->and(implode("\n", $set->findings))->not->toContain('so the key check accepts `filter`');
})->with([
    'the deep name alone' => [
        ['filter[created][gte]'],
        ['filter' => ['sometimes', 'array:created'], 'filter.created' => ['sometimes', 'array:gte']],
    ],
    'beside a one-level sibling' => [
        ['filter[created][gte]', 'filter[x]'],
        ['filter' => ['sometimes', 'array:created,x'], 'filter.created' => ['sometimes', 'array:gte']],
    ],
]);

it('leaves open a level whose key array: cannot name, and says so', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: ['filter[a,b][gte]' => new Schema(types: [SchemaType::Integer])],
        additionalProperties: false,
    )]));

    expect($set->rules)->not->toHaveKey('filter')
        ->and(implode("\n", $set->findings))->toContain('so the key check accepts `filter`');
});

// The collision finding already names both names, and the contract declares the key the other one arrives
// under, so saying the check accepts a key "the contract does not declare" would be wrong.
it('does not say a closed body accepts a key it declares, when two names collide', function (array $properties, string $finding): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: array_map(static fn (): Schema => new Schema(types: [SchemaType::String]), array_flip($properties)),
        additionalProperties: false,
    )]));

    expect(implode("\n", $set->findings))->not->toContain('closed to its declared names')
        ->and(implode("\n", $set->findings))->toContain($finding);
})->with([
    'a dotted and a plain name' => [['user.name', 'user_name'], 'reach Laravel under one name'],
    'a bracketed name below a declared one' => [['page', 'page[size]'], 'below the key `page` that `page` uses'],
]);

it('leaves the head of a bracketed name open when the body is open', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['page[size]' => new Schema(types: [SchemaType::Integer])],
    )]));

    expect(array_keys($set->rules))->toBe(['page.size']);
});

// `user.name` and `user_name` are one key once PHP has delivered them, so the closed root takes the
// second for the first, and the file says so.
it('says what a closed form body accepts that the contract does not', function (array $media): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['user.name' => new Schema(types: [SchemaType::String]), 'plain' => new Schema(types: [SchemaType::String])],
        additionalProperties: false,
    );
    $set = RuleSetBuilder::for(operationWithInput(content: array_fill_keys($media, $schema)));

    expect(implode("\n", $set->findings))
        ->toContain('The body is closed to its declared names, but a form delivers `user.name` as `user_name`')
        ->toContain('so the key check accepts `user_name`');
})->with([
    'a form' => [['multipart/form-data']],
    'JSON and a form' => [['application/json', 'multipart/form-data']],
]);

it('says nothing of a closed body whose names PHP leaves alone', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: ['plain' => new Schema(types: [SchemaType::String])],
        additionalProperties: false,
    )]));

    expect(implode("\n", $set->findings))->not->toContain('closed to its declared names');
});

it('closes a form body to the name PHP gives a key, and a mixed body to both', function (): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['user.name' => new Schema(types: [SchemaType::String]), 'plain' => new Schema(types: [SchemaType::String])],
        additionalProperties: false,
    );

    expect(RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => $schema]))->closedKeys)
        ->toBe(['user_name', 'plain'])
        ->and(RuleSetBuilder::for(operationWithInput(content: ['application/json' => $schema, 'multipart/form-data' => $schema]))->closedKeys)
        ->toBe(['user.name', 'user_name', 'plain']);
});

// --- What a translated keyword leaves unenforced, as data ---

// The doctor reports these per operation, and it cannot read them out of the
// prose in `findings`: that list also carries what was merely worth saying (two
// media types over one schema, a stricter-than-the-contract note), which must
// never gate an exit code. So the keyword and the field are recorded beside the
// sentence, and only for the four ways a keyword the rule set reads comes out
// unenforced.
it('names the keyword and the field for each way a read keyword is left unenforced', function (
    array $content,
    array $query,
    array $expected,
): void {
    assert(array_is_list($query));

    $set = RuleSetBuilder::for(operationWithInput(content: $content, query: $query));

    expect($set->unenforced)->toBe($expected);
})->with([
    'a format no Laravel rule means the same as' => [
        ['application/json' => objectSchema(['website' => new Schema(types: [SchemaType::String], format: 'uri')])],
        [],
        ['format' => ['`website`']],
    ],
    'a format on a number, which nothing reads' => [
        ['application/json' => objectSchema(['price' => new Schema(types: [SchemaType::Number], format: 'float')])],
        [],
        ['format' => ['`price`']],
    ],
    'a format on a string that is not a mapped one' => [
        ['application/json' => objectSchema(['blob' => new Schema(types: [SchemaType::String], format: 'byte')])],
        [],
        ['format' => ['`blob`']],
    ],
    // A format meant for the other kind of number says nothing the type rule carries.
    'int32 on a number' => [
        ['application/json' => objectSchema(['count' => new Schema(types: [SchemaType::Number], format: 'int32')])],
        [],
        ['format' => ['`count`']],
    ],
    'int64 on a number' => [
        ['application/json' => objectSchema(['count' => new Schema(types: [SchemaType::Number], format: 'int64')])],
        [],
        ['format' => ['`count`']],
    ],
    // What the rules already carry, or what asserts nothing: handled, so nothing to report.
    'int64 on an integer, which the integer rule enforces' => [
        ['application/json' => objectSchema(['count' => new Schema(types: [SchemaType::Integer], format: 'int64')])],
        [],
        [],
    ],
    'int32 on an integer, which becomes its two bounds' => [
        ['application/json' => objectSchema(['count' => new Schema(types: [SchemaType::Integer], format: 'int32')])],
        [],
        [],
    ],
    'double on a number, which the numeric rule enforces' => [
        ['application/json' => objectSchema(['price' => new Schema(types: [SchemaType::Number], format: 'double')])],
        [],
        [],
    ],
    'password, an annotation' => [
        ['application/json' => objectSchema(['pwd' => new Schema(types: [SchemaType::String], format: 'password')])],
        [],
        [],
    ],
    // A field the rule set drops whole states constraints nothing enforces, and the doctor names each one.
    'a query parameter whose name PHP rewrites' => [
        [],
        [new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer], minimum: 1.0, maximum: 100.0), required: true)],
        ['required' => ['`page[size]`'], 'minimum' => ['`page[size]`'], 'maximum' => ['`page[size]`']],
    ],
    'a query parameter whose name carries a star' => [
        [],
        [new QueryParameter('a*b', new Schema(types: [SchemaType::String], pattern: '^a'), required: true)],
        ['required' => ['`a*b`'], 'pattern' => ['`a*b`']],
    ],
    'a body property whose key carries a star' => [
        ['application/json' => objectSchema(['a*b' => new Schema(types: [SchemaType::String], maxLength: 3)], ['a*b'])],
        [],
        ['required' => ['`a*b`'], 'maxLength' => ['`a*b`']],
    ],
    'a nested body property whose key carries a star, and is not required' => [
        ['application/json' => objectSchema(['user' => objectSchema(['a*b' => new Schema(types: [SchemaType::Integer], minimum: 2.0)])])],
        [],
        ['minimum' => ['`user.a*b`']],
    ],
    'a required body key with no property, and a star' => [
        ['application/json' => objectSchema([], ['a*b'])],
        [],
        ['required' => ['`a*b`']],
    ],
    // `isFilePart` is no word of a contract, so it is named by the keyword the contract wrote: `format: binary`, or a
    // `contentMediaType` at 3.1.
    'a file part written as format: binary, in a JSON body' => [
        ['application/json' => objectSchema(['doc' => new Schema(types: [SchemaType::String], isFilePart: true)])],
        [],
        ['format' => ['`doc`']],
    ],
    'a file part written as contentMediaType, in a JSON body' => [
        ['application/json' => objectSchema(['doc' => new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'application/pdf')])],
        [],
        ['contentMediaType' => ['`doc`']],
    ],
    'int64 on a query parameter' => [
        [],
        [new QueryParameter('after', new Schema(types: [SchemaType::Integer], format: 'int64'))],
        [],
    ],
    'a pattern across the ECMA-262 / PCRE boundary' => [
        ['application/json' => objectSchema(['code' => new Schema(types: [SchemaType::String], pattern: '^\d+$')])],
        [],
        ['pattern' => ['`code`']],
    ],
    'a content media type no rule can match' => [
        ['multipart/form-data' => objectSchema(['doc' => new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'text/csv')])],
        [],
        ['contentMediaType' => ['`doc`']],
    ],
    'a keyword the walk reached no rule for, on the body itself' => [
        ['application/json' => new Schema(types: [SchemaType::Object], enum: [[]])],
        [],
        ['enum' => ['The body itself']],
    ],
    'a keyword on a query parameter' => [
        [],
        [new QueryParameter('sort', new Schema(types: [SchemaType::String], format: 'duration'))],
        ['format' => ['`sort`']],
    ],
    // OpenAPI 3.0: `required` on a `readOnly` property takes effect on the response only, so a request that
    // leaves it out does what the contract says. Its prose finding stays in the generated file, and the doctor
    // has nothing to report.
    'required on a readOnly property' => [
        ['application/json' => objectSchema(['id' => new Schema(types: [SchemaType::Integer], readOnly: true)], ['id'])],
        [],
        [],
    ],
]);

it('lists every field one keyword is unenforced on, once each, in the order they are met', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema([
        'website' => new Schema(types: [SchemaType::String], format: 'uri'),
        'tags' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String], format: 'uri')),
        'age' => new Schema(types: [SchemaType::Integer], minimum: 1.0),
    ])]));

    expect($set->unenforced)->toBe(['format' => ['`website`', '`tags`[]']]);
});

// The sentence a reader opens the generated file for is not the data the doctor
// gates on. A body over two media types, a closed body a form cannot close and a
// `query` array whose serialization is not read are all worth a line and none is
// a keyword the contract wrote that nothing enforces.
it('records nothing for a finding that is information rather than an unenforced keyword', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: [
            'application/json' => objectSchema(['a' => new Schema(types: [SchemaType::String])]),
            'multipart/form-data' => objectSchema(['a' => new Schema(types: [SchemaType::String])]),
        ],
        query: [new QueryParameter('tags', new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String])))],
    ));

    expect($set->findings)->not->toBe([])
        ->and($set->unenforced)->toBe([]);
});

it('records nothing for a keyword that became a rule', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema([
        'email' => new Schema(types: [SchemaType::String], format: 'email', pattern: '^[a-z]+$', maxLength: 10),
    ])]));

    expect($set->unenforced)->toBe([]);
});

// A recursive schema is validated down to the depth it states with `x-max-depth`, and a
// payload deeper than that is refused. A level is one crossing of the recursion marker,
// the expected keys below are written out by hand rather than derived from the schema,
// and the cases are the ones the rule set could get wrong: the default, a stated depth,
// zero, a list, a recursive pair whose sides disagree, and an `allOf`.
//
// @see docs/guide/code-generation/request-validation.md — "A recursive schema is validated to a stated depth"

/**
 * `Address`, which holds one `Address` as `parent`, the way a component that refers to itself reaches the rule set.
 */
function recursiveAddress(?int $maxDepth = null, bool $parentRequired = false, bool $nullable = false): Schema
{
    return new Schema(
        name: 'Address',
        types: $nullable ? [SchemaType::Object, SchemaType::Null] : [SchemaType::Object],
        properties: [
            'city' => new Schema(types: [SchemaType::String]),
            'parent' => Schema::recursion('#/components/schemas/Address', 'Address'),
        ],
        required: $parentRequired ? ['parent'] : [],
        maxDepth: $maxDepth,
        source: '#/components/schemas/Address',
    );
}

it('validates a recursion down to the depth the schema states and refuses what is below', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(maxDepth: 2)]));

    expect($set->rules)->toBe([
        'city' => ['sometimes', 'string'],
        'parent' => ['sometimes', 'array'],
        'parent.city' => ['sometimes', 'string'],
        'parent.parent' => ['sometimes', 'array'],
        'parent.parent.city' => ['sometimes', 'string'],
        'parent.parent.parent' => ['missing'],
    ]);
});

it('reads three levels when the schema states no depth', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress()]));

    expect(array_keys($set->rules))->toBe([
        'city',
        'parent',
        'parent.city',
        'parent.parent',
        'parent.parent.city',
        'parent.parent.parent',
        'parent.parent.parent.city',
        'parent.parent.parent.parent',
    ])->and($set->rules['parent.parent.parent'])->toBe(['sometimes', 'array'])
        ->and($set->rules['parent.parent.parent.parent'])->toBe(['missing']);
});

it('refuses the recursive field itself when the schema states a depth of zero', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(maxDepth: 0)]));

    expect($set->rules)->toBe([
        'city' => ['sometimes', 'string'],
        'parent' => ['missing'],
    ]);
});

// `missing` says the key is absent. A required field that cannot be null keeps the presence the contract asks of it
// beside `missing`, so a payload that reaches the cut is refused, where `missing` alone would have let it through
// looser than the contract. Below the root that presence is the parent's `required_array_keys`, which the unrolled
// level already carries (a child's own presence is `sometimes`, so a tree that stops earlier is unaffected), and an
// optional field carries `missing` alone.
it('keeps the presence a required recursive field asks for beside the cut', function (): void {
    $requiredAtOnce = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(
        maxDepth: 0,
        parentRequired: true,
    )]));
    $optionalAtOnce = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(
        maxDepth: 0,
    )]));
    $requiredBelow = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(
        maxDepth: 1,
        parentRequired: true,
    )]));

    expect($requiredAtOnce->rules['parent'])->toBe(['present', 'missing'])
        ->and($optionalAtOnce->rules['parent'])->toBe(['missing'])
        ->and($requiredBelow->rules['parent'])->toBe(['present', 'array', 'required_array_keys:parent'])
        ->and($requiredBelow->rules['parent.parent'])->toBe(['missing']);
});

// A schema that allows `null` allows it at the cut as well, and nothing else: no Laravel rule says "null or absent"
// (`missing` refuses `null`, `prohibited` lets `""` and `[]` through), so the field carries the package's own.
// What the contract asks of the key stays beside it.
it('lets a nullable recursive field be null at the cut and nothing else', function (): void {
    $optional = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(
        maxDepth: 1,
        nullable: true,
    )]));
    $required = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(
        maxDepth: 0,
        parentRequired: true,
        nullable: true,
    )]));

    expect($optional->rules['parent.parent'])->toEqual(['sometimes', new NullOrAbsent])
        ->and($required->rules['parent'])->toEqual(['present', new NullOrAbsent]);
});

it('reads the rule at a list element cut as the rule alone', function (): void {
    $node = new Schema(
        name: 'Node',
        types: [SchemaType::Object, SchemaType::Null],
        properties: [
            'children' => new Schema(
                types: [SchemaType::Array],
                items: Schema::recursion('#/components/schemas/Node', 'Node'),
            ),
        ],
        maxDepth: 1,
        source: '#/components/schemas/Node',
    );

    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $node]));

    expect($set->rules['children.*.children.*'])->toEqual([new NullOrAbsent]);
});

it('unrolls a recursion through a list element', function (): void {
    $node = new Schema(
        name: 'Node',
        types: [SchemaType::Object],
        properties: [
            'children' => new Schema(
                types: [SchemaType::Array],
                items: Schema::recursion('#/components/schemas/Node', 'Node'),
            ),
        ],
        maxDepth: 1,
        source: '#/components/schemas/Node',
    );

    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $node]));

    expect($set->rules)->toBe([
        'children' => ['sometimes', 'array', 'list'],
        'children.*' => ['array'],
        'children.*.children' => ['sometimes', 'array', 'list'],
        'children.*.children.*' => ['missing'],
    ]);
});

// The limit at a marker is the depth of the schema it points to, so the two sides of a
// pair never have to agree, and the same pair reached from either side reads each side's
// own limit.
it('reads the limit at a marker from the schema it points to', function (): void {
    $author = static fn (Schema $book): Schema => new Schema(
        name: 'Author',
        types: [SchemaType::Object],
        properties: ['books' => new Schema(types: [SchemaType::Array], items: $book)],
        maxDepth: 1,
        source: '#/components/schemas/Author',
    );
    $book = static fn (Schema $author): Schema => new Schema(
        name: 'Book',
        types: [SchemaType::Object],
        properties: ['author' => $author],
        maxDepth: 2,
        source: '#/components/schemas/Book',
    );
    $authorMarker = Schema::recursion('#/components/schemas/Author', 'Author');
    $bookMarker = Schema::recursion('#/components/schemas/Book', 'Book');

    $fromAuthor = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $author($book($authorMarker))]));
    $fromBook = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $book($author($bookMarker))]));

    expect(array_keys($fromAuthor->rules))->toBe([
        'books',
        'books.*',
        'books.*.author',
        'books.*.author.books',
        'books.*.author.books.*',
        'books.*.author.books.*.author',
    ])->and($fromAuthor->rules['books.*.author'])->toBe(['sometimes', 'array'])
        ->and($fromAuthor->rules['books.*.author.books.*.author'])->toBe(['missing'])
        ->and(array_keys($fromBook->rules))->toBe([
            'author',
            'author.books',
            'author.books.*',
            'author.books.*.author',
            'author.books.*.author.books',
            'author.books.*.author.books.*',
            'author.books.*.author.books.*.author',
            'author.books.*.author.books.*.author.books',
            'author.books.*.author.books.*.author.books.*',
        ])->and($fromBook->rules['author.books.*.author.books.*'])->toBe(['array'])
        ->and($fromBook->rules['author.books.*.author.books.*.author.books.*'])->toBe(['missing']);
});

// The merged schema is what a marker re-enters, and it carries the component's own depth.
it('takes the depth from the component and not from a branch of its allOf', function (): void {
    $base = new Schema(
        name: 'Base',
        types: [SchemaType::Object],
        properties: ['id' => new Schema(types: [SchemaType::Integer])],
        maxDepth: 9,
        source: '#/components/schemas/Base',
    );
    $node = new Schema(
        name: 'Node',
        allOf: [
            $base,
            new Schema(properties: ['next' => Schema::recursion('#/components/schemas/Node', 'Node')]),
        ],
        maxDepth: 1,
        source: '#/components/schemas/Node',
    );

    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $node]));

    expect(array_keys($set->rules))->toBe(['id', 'next', 'next.id', 'next.next'])
        ->and($set->rules['next.next'])->toBe(['missing']);
});

// The cut refuses something the contract allows, which the principle permits when the
// generated file says so, and names the lever.
it('says in one finding that a deeper payload is refused and names x-max-depth as the lever', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(maxDepth: 2)]));
    $findings = array_values(array_filter($set->findings, static fn (string $finding): bool => str_contains($finding, '`x-max-depth`')));

    expect($findings)->toHaveCount(1)
        ->and($findings[0])
        ->toContain('`Address`')
        ->toContain('`x-max-depth: 2`')
        ->toContain('`parent.parent.parent`')
        ->toContain('`missing`')
        ->toContain('absent')
        ->toContain('`null` passes only where the schema is nullable')
        ->toContain('422');
});

// The four findings that say the request is stricter than the contract (D41): each is in `findings` and in `stricter`,
// and none leaves a constraint unenforced. "Nothing below it is validated" is looser, and does.
it('names the findings that say the request is stricter than the contract', function (RuleSet $set, bool $leavesUnenforced): void {
    expect($set->findings)->not->toBe([])
        ->and($set->stricter)->not->toBe([])
        ->and(array_diff($set->stricter, $set->findings))->toBe([])
        ->and($set->leavesUnenforced())->toBe($leavesUnenforced);
})->with(function (): array {
    $png = new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/png');
    $stricter = static fn (array $content): RuleSet => RuleSetBuilder::for(operationWithInput(content: $content));

    return [
        'the untyped root' => [
            $stricter(['application/json' => new Schema(properties: ['name' => new Schema(types: [SchemaType::String])])]),
            false,
        ],
        'a depth cut' => [$stricter(['application/json' => recursiveAddress(maxDepth: 2)]), false],
        // An optional body: a required form says, looser, that an empty one reads as absent.
        'mimetypes' => [RuleSetBuilder::for(operationWithInput(
            content: ['multipart/form-data' => objectSchema(['avatar' => $png])],
            bodyRequired: false,
        )), false],
        'an integral float' => [$stricter(['application/json' => objectSchema(['n' => new Schema(types: [SchemaType::Integer])])]), false],
        'nothing below it is validated' => [RuleSetBuilder::for(fixtureOperation('recursion-allof-two-markers.yaml', 'createThing')), true],
    ];
});

it('says the depth is the default when the schema states none', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress()]));
    $finding = implode("\n", $set->findings);

    expect($finding)->toContain('states no `x-max-depth`')
        ->toContain('3 levels')
        ->toContain('`parent.parent.parent.parent`');
});

it('says nothing about a recursion it never had to cut', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => objectSchema([
        'address' => new Schema(types: [SchemaType::Object]),
    ])]));

    expect(implode('', $set->findings))->not->toContain('x-max-depth');
});

it('counts the fields it refuses once, in the finding, however many the recursion branches into', function (): void {
    $tree = new Schema(
        name: 'Tree',
        types: [SchemaType::Object],
        properties: [
            'left' => Schema::recursion('#/components/schemas/Tree', 'Tree'),
            'right' => Schema::recursion('#/components/schemas/Tree', 'Tree'),
        ],
        maxDepth: 2,
        source: '#/components/schemas/Tree',
    );

    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $tree]));
    $cut = array_keys(array_filter($set->rules, static fn (array $rules): bool => in_array('missing', $rules, true)));

    expect($cut)->toBe([
        'left.left.left',
        'left.left.right',
        'left.right.left',
        'left.right.right',
        'right.left.left',
        'right.left.right',
        'right.right.left',
        'right.right.right',
    ])->and(implode('', $set->findings))->toContain('`left.left.left` and 7 more');
});

// The doctor gates on keywords the contract wrote that nothing enforces. `x-max-depth` is read
// and acted on, and the cut is a rule: there is nothing for the doctor to name.
it('leaves the doctor nothing to report for a recursion it cut', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress()]));

    expect($set->unenforced)->toBe([]);
});

// Nothing to unroll when the ancestor is not one the walk holds: it is written inline, so it
// has no name to be found by. Presence and a finding, as before.
it('keeps presence and a finding for a recursion through a schema it cannot name', function (): void {
    $inline = new Schema(
        types: [SchemaType::Object],
        properties: ['next' => Schema::recursion('#/paths/~1things/post/requestBody/content/application~1json/schema')],
    );

    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $inline]));

    expect($set->rules)->toBe(['next' => ['sometimes']])
        ->and(implode('', $set->findings))->toContain('points back at');
});

// The same thing from a document, not from objects built to look like one: the marker the
// extractor cuts must name a position the schema it points to is found under, whether that
// schema is written in the document or in another file, and whichever version wrote the depth.
it('unrolls a recursion read from a document', function (string $fixture, array $keys): void {
    $operation = extractFixture($fixture)[0];

    expect(array_keys(RuleSetBuilder::for($operation)->rules))->toBe($keys);
})->with([
    'a schema in the document, no depth stated' => ['recursive-schema-in-body.yaml', [
        'name', 'children', 'children.*',
        'children.*.name', 'children.*.children', 'children.*.children.*',
        'children.*.children.*.name', 'children.*.children.*.children', 'children.*.children.*.children.*',
        'children.*.children.*.children.*.name', 'children.*.children.*.children.*.children',
        'children.*.children.*.children.*.children.*',
    ]],
    'a schema in another file' => ['external-recursion/main.yaml', [
        'name', 'children', 'children.*',
        'children.*.name', 'children.*.children', 'children.*.children.*',
        'children.*.children.*.name', 'children.*.children.*.children', 'children.*.children.*.children.*',
        'children.*.children.*.children.*.name', 'children.*.children.*.children.*.children',
        'children.*.children.*.children.*.children.*',
    ]],
    'a depth stated at 3.0' => ['max-depth-3.0.yaml', [
        'city', 'parent', 'parent.city', 'parent.parent', 'parent.parent.city', 'parent.parent.parent',
    ]],
    'a depth stated at 3.1' => ['max-depth-3.1.yaml', [
        'city', 'parent', 'parent.city', 'parent.parent', 'parent.parent.city', 'parent.parent.parent',
    ]],
]);

// An `allOf` around a recursion, read from a document at both versions. What the wrapper states belongs to its own
// level, and what is written beside a marker belongs to every level the marker is crossed at. The expected keys and
// rules are written out by hand from `recursion-allof-*.yaml`.

/**
 * The operation of a fixture with the given `operationId`.
 */
function fixtureOperation(string $fixture, string $operationId): Operation
{
    foreach (extractFixture($fixture) as $operation) {
        if ($operation->operationId === $operationId) {
            return $operation;
        }
    }

    throw new RuntimeException(sprintf('`%s` has no operation `%s`.', $fixture, $operationId));
}

it('keeps what a wrapper around a recursive component states at the wrapper level', function (string $fixture): void {
    $set = RuleSetBuilder::for(fixtureOperation($fixture, 'createCategory'));

    expect($set->rules['name'])->toBe(['present', 'string'])
        ->and($set->rules['children.*'])->toBe(['array'])
        ->and($set->rules['children.*.children.*'])->toBe(['array'])
        ->and($set->rules['children.*.children.*.children.*'])->toBe(['array'])
        ->and($set->rules['children.*.children.*.children.*.children.*'])->toBe(['missing'])
        ->and(count($set->rules))->toBe(16);
})->with([
    'OpenAPI 3.0' => ['recursion-allof-3.0.yaml'],
    'OpenAPI 3.1' => ['recursion-allof-3.1.yaml'],
]);

// A mixin is the common way a component inherits and contains itself. The site is the marker merged with a named schema,
// so it carries that schema's name and source, and what decides is the ancestor the walk holds. The rules are the ones
// the walk wrote before the DTO side asked the same question: `main` at the commit that merged the request side.
it('unrolls a recursion behind a mixin', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-mixin.yaml', 'createNode'));

    expect($set->rules)->toBe([
        'label' => ['sometimes', 'string'],
        'child' => ['sometimes', 'array'],
        'child.label' => ['sometimes', 'string'],
        'child.child' => ['sometimes', 'array'],
        'child.child.label' => ['sometimes', 'string'],
        'child.child.child' => ['missing'],
        'child.child.tag' => ['sometimes', 'string'],
        'child.tag' => ['sometimes', 'string'],
    ])->and(implode("\n", $set->findings))->not->toContain('points back at');
});

// One predicate for the request and the DTO, and it is the ancestor the walk holds that answers.
it('says a marker unrolls when the walk holds its ancestor and the site has no marker branch left', function (): void {
    $marker = Schema::recursion('#/components/schemas/Node', 'Node');
    $ancestor = new Schema(name: 'Node', source: '#/components/schemas/Node', types: [SchemaType::Object]);
    $twoMarkers = new Schema(
        name: 'Node',
        allOf: [Schema::recursion('#/components/schemas/Other', 'Other')],
        recursesTo: '#/components/schemas/Node',
    );
    // A named schema merged ahead of the marker gives the site its own name and source.
    $mixin = new Schema(name: 'Mixin', source: '#/components/schemas/Mixin', recursesTo: '#/components/schemas/Node');

    expect(RuleSetBuilder::unrolls($marker, $ancestor))->toBeTrue()
        ->and(RuleSetBuilder::unrolls($mixin, $ancestor))->toBeTrue()
        // An unnamed ancestor is not on the stack: an inline schema has no name to be found by.
        ->and(RuleSetBuilder::unrolls($marker, null))->toBeFalse()
        ->and(RuleSetBuilder::unrolls($twoMarkers, $ancestor))->toBeFalse()
        ->and(RuleSetBuilder::unrolls(new Schema(types: [SchemaType::Object]), $ancestor))->toBeFalse();
});

it('applies what is written beside a marker at every level the marker is crossed at', function (string $fixture): void {
    $set = RuleSetBuilder::for(fixtureOperation($fixture, 'createNode'));

    expect($set->rules)->toBe([
        'label' => ['sometimes', 'string'],
        'next' => ['sometimes', 'array', 'required_array_keys:label'],
        'next.label' => ['sometimes', 'string'],
        'next.next' => ['sometimes', 'array', 'required_array_keys:label'],
        'next.next.label' => ['sometimes', 'string'],
        'next.next.next' => ['missing'],
    ]);
})->with([
    'OpenAPI 3.0' => ['recursion-allof-3.0.yaml'],
    'OpenAPI 3.1' => ['recursion-allof-3.1.yaml'],
]);

it('unrolls a recursive component a named allOf includes', function (string $fixture): void {
    $set = RuleSetBuilder::for(fixtureOperation($fixture, 'createNamedCategory'));

    expect($set->rules['children.*'])->toBe(['array'])
        ->and($set->rules['children.*.children.*.children.*.children.*'])->toBe(['missing'])
        ->and(count($set->rules))->toBe(16)
        ->and(implode('', $set->findings))->not->toContain('points back at');
})->with([
    'OpenAPI 3.0' => ['recursion-allof-3.0.yaml'],
    'OpenAPI 3.1' => ['recursion-allof-3.1.yaml'],
]);

it('unrolls a recursive component wrapped on a field, and keeps the wrapper to the wrapped level', function (string $fixture): void {
    $set = RuleSetBuilder::for(fixtureOperation($fixture, 'createTree'));

    expect($set->rules['root'])->toBe(['sometimes', 'array', 'required_array_keys:extra'])
        ->and($set->rules['root.next'])->toBe(['sometimes', 'array'])
        ->and($set->rules['root.next.next.next'])->toBe(['sometimes', 'array'])
        ->and($set->rules['root.next.next.next.next'])->toBe(['missing']);
})->with([
    'OpenAPI 3.0' => ['recursion-allof-3.0.yaml'],
    'OpenAPI 3.1' => ['recursion-allof-3.1.yaml'],
]);

// A depth written in the component's own inline branch is the component's: the finding must not tell its author the
// schema states none, and the included component's own depth (9) must not be the one used.
it('reads an x-max-depth written in a component\'s own inline allOf branch', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-depth-in-allof-branch.yaml', 'createNode'));

    expect(array_keys($set->rules))->toBe(['id', 'next', 'next.id', 'next.next'])
        ->and($set->rules['next.next'])->toBe(['missing'])
        ->and(implode('', $set->findings))->toContain('`x-max-depth: 1`')
        ->and(implode('', $set->findings))->not->toContain('states no `x-max-depth`');
});

// The finding says what a payload below the depth gets: `null` passes only when the schema is nullable, and the
// count of levels agrees with its noun.
it('says null passes below the depth only when the schema is nullable', function (bool $nullable, string $says, string $notSays): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(
        maxDepth: 2,
        nullable: $nullable,
    )]));
    $finding = implode("\n", $set->findings);

    expect($finding)->toContain($says)
        ->and($finding)->not->toContain($notSays);
})->with([
    'a nullable schema' => [true, '`null` passes because `Address` is nullable', '`null` passes only'],
    'a schema that is not nullable' => [false, '`null` passes only where the schema is nullable, and `Address` is not', 'because `Address` is nullable'],
]);

// An empty list of the recursive schema carries nothing, and the cut is on its elements, so it passes: the finding says
// so for a list, and does not call `[]` a 422 where the cut is a list element.
it('says an empty list of the recursive schema passes at the cut', function (): void {
    $node = new Schema(
        name: 'Node',
        types: [SchemaType::Object],
        properties: [
            'children' => new Schema(
                types: [SchemaType::Array],
                items: Schema::recursion('#/components/schemas/Node', 'Node'),
            ),
        ],
        maxDepth: 1,
        source: '#/components/schemas/Node',
    );

    $finding = implode("\n", RuleSetBuilder::for(operationWithInput(content: ['application/json' => $node]))->findings);

    expect($finding)->toContain('An empty list of `Node` passes')
        ->and($finding)->not->toContain('`[]` answer 422');
});

it('says one level, not 1 levels', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => recursiveAddress(maxDepth: 1)]));
    $finding = implode("\n", $set->findings);

    expect($finding)->toContain('1 level deep')
        ->and($finding)->not->toContain('1 levels');
});

// A recursion that branches grows as `k` to the power of the depth, so a depth that reads as reasonable for a tree is
// more rules than a request should evaluate, and then more than a process holds. When the stated depth does not fit
// the ceiling, the depth is lowered evenly for the operation and every branch is cut at the same level.
//
// By hand, for `Tree` (`value`, `left`, `right`): an expansion at crossing `c` below an applied depth `N` writes its own
// key, `value`, and two markers, so `T(c) = 2 + 2 T(c + 1)` and `T(N) = 1` (the cut key), which is `3 * 2^(N - c) - 2`.
// The root's `left` and `right` are crossing 0, so the unrolled keys are `2 * T(0) = 6 * 2^N - 4`: 764 at N = 7, which
// fits 1,000, and 1,532 at N = 8, which does not. The rule set is those and the root's `value`: 765.
it('lowers the depth evenly until the rules fit the ceiling, and says so', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-binary-tree-deep.yaml', 'createTree'));
    $cut = array_keys(array_filter($set->rules, static fn (array $rules): bool => $rules === ['missing']));
    $findings = array_values(array_filter($set->findings, static fn (string $finding): bool => str_contains($finding, 'ceiling')));

    expect(count($set->rules))->toBe(765)
        ->and($set->rules[str_repeat('left.', 6).'left'])->toBe(['sometimes', 'array'])
        ->and($set->rules[str_repeat('left.', 7).'left'])->toBe(['missing'])
        ->and($set->rules[str_repeat('right.', 7).'right'])->toBe(['missing'])
        ->and($cut)->toHaveCount(2 ** 8)
        ->and($findings)->toHaveCount(1)
        ->and($findings[0])
        ->toContain('`Tree`')
        ->toContain('`x-max-depth: 20`')
        ->toContain('1000')
        ->toContain('7 levels deep')
        ->toContain('on every branch')
        ->toContain('`x-max-depth`');
});

it('leaves no branch with its presence rule alone once the depth is lowered', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-binary-tree-deep.yaml', 'createTree'));
    $presenceOnly = array_filter($set->rules, static fn (array $rules): bool => $rules === ['sometimes']);

    expect($presenceOnly)->toBe([])
        ->and(implode('', $set->findings))->not->toContain('presence rule');
});

// The depth is lowered for the operation, as one cap on every recursive schema: a schema whose own depth is under it
// keeps it, and says nothing about a ceiling.
it('lowers only the schemas that are deeper than the depth that fits', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-ceiling-two-schemas.yaml', 'createThing'));
    $about = static fn (string $schema): string => implode("\n", array_filter(
        $set->findings,
        static fn (string $finding): bool => str_starts_with($finding, '`'.$schema.'` recurses'),
    ));

    expect($set->rules['meta.up.up.up'])->toBe(['missing'])
        ->and(array_key_exists('meta.up.up.up.up', $set->rules))->toBeFalse()
        ->and($set->rules['tree.'.str_repeat('left.', 6).'left'])->toBe(['sometimes', 'array'])
        ->and($set->rules['tree.'.str_repeat('left.', 7).'left'])->toBe(['missing'])
        ->and($about('Meta'))->toContain('`x-max-depth: 2`')
        ->and($about('Meta'))->not->toContain('ceiling')
        ->and($about('Tree'))->toContain('ceiling');
});

// By hand, for `Tree` and `Chain` (`note`, `next`) stated at 20 in one operation. A chain applied at `N` writes two keys
// for each of its `N` expansions and one for the cut, `2 N + 1`, and the tree writes `6 * 2^N - 4`. At a shared cap of 7
// that is 764 + 15 = 779 keys, which fits 1,000, and at 8 it is 1,532 + 17, which does not. The two are tied on 8, so the
// one with the most keys, the tree, is lowered to 7: 764 + 17 = 781 fits, and the chain keeps 8. The four keys the
// two roots hold without recursing are not unrolled, so the rule set is 785.
it('lowers the heavier of two tied schemas first, and only as far as the rules need', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-ceiling-tie.yaml', 'createThing'));
    $about = static fn (string $schema): string => implode("\n", array_filter(
        $set->findings,
        static fn (string $finding): bool => str_starts_with($finding, '`'.$schema.'` recurses'),
    ));

    expect($set->rules['tree.'.str_repeat('left.', 6).'left'])->toBe(['sometimes', 'array'])
        ->and($set->rules['tree.'.str_repeat('left.', 7).'left'])->toBe(['missing'])
        ->and($set->rules['chain.'.str_repeat('next.', 7).'next'])->toBe(['sometimes', 'array'])
        ->and($set->rules['chain.'.str_repeat('next.', 8).'next'])->toBe(['missing'])
        ->and(count($set->rules))->toBe(4 + 764 + 17)
        ->and($about('Tree'))->toContain('`x-max-depth: 20`')->toContain('7 levels deep')
        ->and($about('Chain'))->toContain('`x-max-depth: 20`')->toContain('8 levels deep');
});

// The `and`, `or` and `not` filter of a search endpoint, with no depth stated. By hand: an expansion writes its own
// key, three scalars and `and` and `or`, then crosses three markers, so the root's 5 keys and three crossings of 105
// make 320, which is under the ceiling and so unrolled in full.
it('unrolls the default depth of a search filter in full, under the ceiling', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-filter.yaml', 'search'));

    expect(count($set->rules))->toBe(320)
        ->toBeLessThan(RuleSetBuilder::MAX_UNROLLED_KEYS)
        ->and(implode('', $set->findings))->not->toContain('ceiling');
});

// A field that two branches of an `allOf` write as a marker to two different components is one field that has to
// satisfy both, and a rule set that unrolled one of them would silently drop the other. It keeps the presence rule it
// always had and the finding that nothing below it is validated, naming both.
it('keeps presence and a finding for a field that two allOf branches point back from', function (): void {
    $set = RuleSetBuilder::for(fixtureOperation('recursion-allof-two-markers.yaml', 'createThing'));

    expect($set->rules)->toBe([
        'part' => ['sometimes'],
        'a' => ['sometimes', 'string'],
        'b' => ['sometimes', 'integer:strict'],
    ])->and(implode("\n", $set->findings))
        ->toContain('`part` points back at `#/components/schemas/Left` and `#/components/schemas/Right`')
        ->toContain('nothing below it is validated');
});
