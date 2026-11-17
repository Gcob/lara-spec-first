<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\EncodingDefaults;
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
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi30Strategy;
use Gcob\LaraSpecFirst\Parsing\Version\OpenApi31Strategy;
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
 * @param  EncodingDefaults|null  $encodingDefaults  null is a body built by hand, which gets the widest table
 */
function operationWithInput(
    string $method = 'post',
    array $content = [],
    bool $bodyRequired = true,
    array $query = [],
    string $path = '/things',
    array $encoding = [],
    ?EncodingDefaults $encodingDefaults = null,
): Operation {
    return new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: 'doThing',
        requestBody: $content === [] ? null : new RequestBody($content, $bodyRequired, $encoding, $encodingDefaults ?? new EncodingDefaults),
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

    // One required property has no sibling to name, and Laravel cannot tell an absent body from an empty
    // one: the request reads the raw JSON itself, so the finding is gone and the key is listed.
    expect($set->rules)->toBe(['name' => ['sometimes', 'string']])
        ->and($set->requiredOnceSent)->toBe(['name' => 'present'])
        ->and(implode('', $set->findings))->not->toContain('may be absent entirely');
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

// The finding is about a form no rule refuses. A required key asked with `present` or `required` already refuses
// an empty form, so there the finding would be noise. A key that only follows another (`dependentRequired`), one
// the request leaves unchecked, a `readOnly` one and a `PATCH` ask nothing of an empty form: the finding stays.
it('drops the empty-form finding when a required field already refuses an empty form', function (
    array $media,
    string $method,
    Schema $body,
    bool $says,
): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: $method,
        content: array_fill_keys($media, $body),
    ));

    expect(str_contains(implode("\n", $set->findings), 'a form with a `Content-Type` and no field'))->toBe($says);
})->with([
    'a required string' => [
        ['application/x-www-form-urlencoded'], 'post',
        objectSchema(['a' => new Schema(types: [SchemaType::String]), 'b' => new Schema(types: [SchemaType::String])], ['a']),
        false,
    ],
    'a required integer' => [
        ['multipart/form-data'], 'post',
        objectSchema(['a' => new Schema(types: [SchemaType::Integer])], ['a']),
        false,
    ],
    'a required file part' => [
        ['multipart/form-data'], 'post',
        objectSchema(['cover' => new Schema(types: [SchemaType::String], isFilePart: true)], ['cover']),
        false,
    ],
    'a required name properties does not declare' => [
        ['multipart/form-data'], 'post',
        objectSchema(['q' => new Schema(types: [SchemaType::String])], ['id']),
        false,
    ],
    'JSON and a form, a required name PHP does not rewrite' => [
        ['application/json', 'multipart/form-data'], 'post',
        objectSchema(['a' => new Schema(types: [SchemaType::String])], ['a']),
        false,
    ],
    'a PATCH with a required list' => [
        ['multipart/form-data'], 'patch',
        objectSchema(['a' => new Schema(types: [SchemaType::String])], ['a']),
        true,
    ],
    'a required readOnly property alone' => [
        ['multipart/form-data'], 'post',
        objectSchema(['id' => new Schema(types: [SchemaType::Integer], readOnly: true)], ['id']),
        true,
    ],
    'a required name withheld by a collision' => [
        ['multipart/form-data'], 'post',
        objectSchema(
            ['user.name' => new Schema(types: [SchemaType::String]), 'user_name' => new Schema(types: [SchemaType::String])],
            ['user.name'],
        ),
        true,
    ],
    'a property required only through dependentRequired' => [
        ['multipart/form-data'], 'post',
        new Schema(
            types: [SchemaType::Object],
            properties: ['card' => new Schema(types: [SchemaType::String]), 'postcode' => new Schema(types: [SchemaType::String])],
            dependentRequired: ['card' => ['postcode']],
        ),
        true,
    ],
    'JSON and a form, the only required name PHP rewrites' => [
        ['application/json', 'multipart/form-data'], 'post',
        objectSchema(['user.name' => new Schema(types: [SchemaType::String])], ['user.name']),
        true,
    ],
]);

// An optional body with two required properties asks each through the other, so a form that sends none of them is
// accepted, and no rule can tell it from an absent body. A JSON body has the raw content to read, so only a body read
// as a form says so.
it('says that an optional form body requiring two properties accepts a form with none of them', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['multipart/form-data' => objectSchema(
            ['a' => new Schema(types: [SchemaType::String]), 'b' => new Schema(types: [SchemaType::String])],
            ['a', 'b'],
        )],
        bodyRequired: false,
    ));

    expect(implode("\n", $set->findings))->toContain('from a form that sends none of the body\'s keys, since both leave every key out of the data, so the form is accepted, which the contract refuses');
});

// The finding is about a form, since the request closes the JSON gap itself: it is written for a body read as a
// form, alone or beside JSON, and never for a body that is JSON only. It names a form, not `{}`.
it('says what an optional body lets through only when it is read as a form', function (
    array $media,
    array $properties,
    array $required,
    ?string $says,
): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: array_fill_keys($media, objectSchema(
            array_fill_keys($properties, new Schema(types: [SchemaType::String])),
            array_values($required),
        )),
        bodyRequired: false,
    ));
    $findings = implode("\n", $set->findings);

    expect(str_contains($findings, 'may be absent entirely'))->toBe($says !== null)
        ->and($findings)->not->toContain('`{}`');

    if ($says !== null) {
        expect($findings)->toContain($says)
            ->toContain('a form that sends none of the body\'s keys');
    }
})->with([
    'JSON only' => [['application/json'], ['a', 'b'], ['a'], null],
    'a form only, one name' => [['multipart/form-data'], ['a', 'b'], ['a'], 'so it is required only when another key of the body is sent'],
    'a form only, two names' => [['multipart/form-data'], ['a', 'b'], ['a', 'b'], 'so each is required only when another key of the body is sent'],
    'JSON and a form, one name' => [['application/json', 'multipart/form-data'], ['a', 'b'], ['a'], 'so it is required only when another key of the body is sent'],
    'JSON and a form, two names' => [['application/json', 'application/x-www-form-urlencoded'], ['a', 'b'], ['a', 'b'], 'so each is required only when another key of the body is sent'],
    'a form only, no sibling' => [['multipart/form-data'], ['a'], ['a'], 'declares nothing else'],
]);

// The keys an optional JSON body must carry once it is sent, which the rules ask for only through a sibling: the rule
// each would get in a required body. The request reads them from the raw content, since a rule cannot see `{}`.
it('lists the keys an optional JSON body requires once it is sent', function (
    array $media,
    string $method,
    bool $bodyRequired,
    Schema $body,
    array $expected,
): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: $method,
        content: array_fill_keys($media, $body),
        bodyRequired: $bodyRequired,
    ));

    expect($set->requiredOnceSent)->toBe($expected);
})->with([
    'a string and an integer' => [
        ['application/json'], 'post', false,
        objectSchema(['a' => new Schema(types: [SchemaType::String]), 'n' => new Schema(types: [SchemaType::Integer])], ['a', 'n']),
        ['a' => 'present', 'n' => 'required'],
    ],
    'a nullable string' => [
        ['application/json'], 'post', false,
        objectSchema(['a' => new Schema(types: [SchemaType::String, SchemaType::Null])], ['a']),
        ['a' => 'present'],
    ],
    'a name properties does not declare' => [
        ['application/json'], 'post', false,
        objectSchema(['a' => new Schema(types: [SchemaType::String])], ['ghost']),
        ['ghost' => 'present'],
    ],
    'a readOnly name and a name with a star left out' => [
        ['application/json'], 'post', false,
        objectSchema(
            [
                'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
                'x*y' => new Schema(types: [SchemaType::String]),
                'b' => new Schema(types: [SchemaType::String]),
            ],
            ['id', 'x*y', 'b'],
        ),
        ['b' => 'present'],
    ],
    'nothing required' => [
        ['application/json'], 'post', false,
        objectSchema(['a' => new Schema(types: [SchemaType::String])]),
        [],
    ],
    'a required body' => [
        ['application/json'], 'post', true,
        objectSchema(['a' => new Schema(types: [SchemaType::String])], ['a']),
        [],
    ],
    'a PATCH' => [
        ['application/json'], 'patch', false,
        objectSchema(['a' => new Schema(types: [SchemaType::String])], ['a']),
        [],
    ],
    'a form only' => [
        ['multipart/form-data'], 'post', false,
        objectSchema(['a' => new Schema(types: [SchemaType::String])], ['a']),
        [],
    ],
    'JSON and a form, the rewritten name left out' => [
        ['application/json', 'multipart/form-data'], 'post', false,
        objectSchema(
            ['user.name' => new Schema(types: [SchemaType::String]), 'a' => new Schema(types: [SchemaType::String])],
            ['user.name', 'a'],
        ),
        ['a' => 'present'],
    ],
]);

/**
 * An object schema that allows `null`, which is the root of a body that may be `null` or whole.
 *
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $required
 */
function nullableObjectSchema(array $properties, array $required = []): Schema
{
    return new Schema(types: [SchemaType::Object, SchemaType::Null], properties: $properties, required: $required);
}

/**
 * A nullable root that requires `street`, `age` (an integer) and the dotted `user.name`, beside an optional `note`.
 */
function nullableAddressSchema(): Schema
{
    return nullableObjectSchema(
        [
            'street' => new Schema(types: [SchemaType::String]),
            'age' => new Schema(types: [SchemaType::Integer]),
            'user.name' => new Schema(types: [SchemaType::String]),
            'note' => new Schema(types: [SchemaType::String]),
        ],
        ['street', 'age', 'user.name'],
    );
}

// `null` is a body the contract allows, and it reaches the rules as `[]`, so a plain `present` on a required key would
// refuse it. A required body whose root allows `null` therefore asks for each required key through its siblings, as an
// optional body does: nothing sent, nothing required. What a rule cannot see, `{}`, is the request's to refuse from the
// raw content, so the map lists the names it reads, as written, with the rule a required body would give each.
it('asks a nullable body\'s required keys through each other', function (
    array $media,
    string $method,
    Schema $body,
    array $asked,
    array $once,
    array $says,
): void {
    $set = RuleSetBuilder::for(operationWithInput(method: $method, content: array_fill_keys($media, $body)));

    foreach ($asked as $key => $rule) {
        expect($set->rules[$key][0] ?? null)->toBe($rule);
    }

    expect($set->requiredOnceSent)->toBe($once);

    foreach ($says as $text) {
        expect(implode("\n", $set->findings))->toContain($text);
    }
})->with([
    'JSON only, three required and one optional' => [
        ['application/json'], 'post', nullableAddressSchema(),
        [
            'street' => 'present_with:age,user\.name,note',
            'age' => 'present_with:street,user\.name,note',
            'user\.name' => 'present_with:street,age,note',
            'note' => 'sometimes',
        ],
        ['street' => 'present', 'age' => 'required', 'user.name' => 'present'],
        [],
    ],
    'JSON and a form: both keys of a rewritten sibling, and the rewritten name left out of the map' => [
        ['application/json', 'application/x-www-form-urlencoded'], 'post', nullableAddressSchema(),
        [
            'street' => 'present_with:age,user\.name,user_name,note',
            'age' => 'present_with:street,user\.name,user_name,note',
            'user\.name' => 'sometimes',
        ],
        ['street' => 'present', 'age' => 'required'],
        [],
    ],
    'a lone undeclared name has no rule, keeps its finding and is in the map' => [
        ['application/json'], 'post', nullableObjectSchema([], ['ghost']),
        [],
        ['ghost' => 'present'],
        ['The body requires `ghost` without declaring it under `properties`, so the request asks for the key'],
    ],
    'a lone readOnly name is sometimes, out of the map, and keeps its finding' => [
        ['application/json'], 'post', nullableObjectSchema(['id' => new Schema(types: [SchemaType::Integer], readOnly: true)], ['id']),
        ['id' => 'sometimes'],
        [],
        ['`id` is `readOnly` and required'],
    ],
    'the same body not nullable asks as it always did' => [
        ['application/json'], 'post', objectSchema(
            ['street' => new Schema(types: [SchemaType::String]), 'age' => new Schema(types: [SchemaType::Integer])],
            ['street', 'age'],
        ),
        ['street' => 'present', 'age' => 'required'],
        [],
        [],
    ],
    'a form-only nullable body is not read as JSON' => [
        ['multipart/form-data'], 'post', nullableObjectSchema(
            ['street' => new Schema(types: [SchemaType::String]), 'age' => new Schema(types: [SchemaType::Integer])],
            ['street', 'age'],
        ),
        ['street' => 'present', 'age' => 'required'],
        [],
        [],
    ],
    'a PATCH asks nothing' => [
        ['application/json'], 'patch', nullableAddressSchema(),
        ['street' => 'sometimes', 'age' => 'sometimes', 'user\.name' => 'sometimes'],
        [],
        [],
    ],
]);

// The same finding, the same reason to drop it: the body is required and read as a form, and a required key an empty
// form cannot satisfy refuses it. For a nullable body that is the request's own check of the required keys (the rules
// only follow a sibling), so the finding goes where the map is not empty and stays where it is.
it('drops the empty-form finding when a nullable required body checks its required keys', function (Schema $body, bool $says): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $body,
        'application/x-www-form-urlencoded' => $body,
    ]));

    expect(str_contains(implode("\n", $set->findings), 'a form with a `Content-Type` and no field'))->toBe($says);
})->with([
    'a required string' => [nullableObjectSchema(['a' => new Schema(types: [SchemaType::String])], ['a']), false],
    'a required name properties does not declare' => [nullableObjectSchema(['q' => new Schema(types: [SchemaType::String])], ['id']), false],
    'the only required name is one PHP rewrites' => [nullableObjectSchema(['user.name' => new Schema(types: [SchemaType::String])], ['user.name']), true],
    'nothing required' => [nullableObjectSchema(['a' => new Schema(types: [SchemaType::String])]), true],
]);

// `required` is implicit and refuses `""` for an integer, a number or a boolean even where nothing turns `""` into
// `null`; `present_with` and `sometimes` do not. So a nullable required body adds `filled` to those, and only to those:
// not a nullable one (`filled` refuses `null`, which it allows), not a string, not an optional body (its caveat stays).
it('adds filled to a required scalar of a nullable required body only', function (
    bool $nullableBody,
    bool $bodyRequired,
    SchemaType $type,
    bool $nullableProperty,
    bool $filled,
): void {
    $property = new Schema(types: [$type, ...($nullableProperty ? [SchemaType::Null] : [])]);
    $body = new Schema(
        types: [SchemaType::Object, ...($nullableBody ? [SchemaType::Null] : [])],
        properties: ['n' => $property, 'other' => new Schema(types: [SchemaType::String])],
        required: ['n'],
    );
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $body], bodyRequired: $bodyRequired));

    expect(in_array('filled', $set->rules['n'], true))->toBe($filled);
})->with([
    'an integer' => [true, true, SchemaType::Integer, false, true],
    'a number' => [true, true, SchemaType::Number, false, true],
    'a boolean' => [true, true, SchemaType::Boolean, false, true],
    'a nullable integer' => [true, true, SchemaType::Integer, true, false],
    'a string' => [true, true, SchemaType::String, false, false],
    'an integer of an optional body' => [true, false, SchemaType::Integer, false, false],
    'an integer of a body that cannot be null' => [false, true, SchemaType::Integer, false, false],
]);

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

// The emitted request reads exactly these keys from the query string, so the list
// is the parameters that have a rule, by the key each arrives under.
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
// failures that is its name would answer for both. A name PHP rewrites is validated under the key it is delivered
// as, and that is the one compared (`" body"` is `body`, and `body[x]` is `body.x`, a key apart).
it('answers on a root key no query parameter has either', function (string $name, string $rootKey): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['title' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter($name, new Schema(types: [SchemaType::String]), required: true)],
    ));

    expect($set->rootKey)->toBe($rootKey);
})->with([
    'a name equal to it' => ['body', '_body'],
    'a name PHP delivers as it' => [' body', '_body'],
    'a name delivered below it' => ['body[x]', 'body'],
    'a name delivered under no key' => ['[x]', 'body'],
]);

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

// A value schema is a constraint on every key the object has, and no rule carries it yet: the object with `properties`
// and a value schema is reported, at the root and nested, and the doctor's data names the keyword as the author wrote it.
it('reports an additionalProperties schema no rule carries', function (bool $nested): void {
    $map = new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [SchemaType::Integer])], additionalPropertiesSchema: new Schema(types: [SchemaType::String]));
    $body = $nested ? objectSchema(['meta' => $map]) : $map;
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $body]));
    $label = $nested ? '`meta`' : 'The body itself';

    expect(array_keys($set->unenforced))->toBe(['additionalProperties'])
        ->and(implode("\n", $set->findings))->toContain($label.' states `additionalProperties`, which nothing in this rule set enforces.')
        ->and(implode("\n", $set->findings))->not->toContain('additionalPropertiesSchema');
})->with(['at the root' => [false], 'nested' => [true]]);

// Where no sweep runs, the schema is still reported: the doctor reported it `Ignored` before it was read, and reading it
// must not make it quieter. A root that is not an object, and a `query` parameter typed `object` or `array`, are the two
// places a schema is dropped whole.
it('leaves an additionalProperties schema unenforced where no sweep reads the schema', function (array $content, array $query, string $label): void {
    assert(array_is_list($query));

    $set = RuleSetBuilder::for(operationWithInput(content: $content, query: $query));

    expect($set->unenforced)->toBe(['additionalProperties' => [$label]]);
})->with(function (): Generator {
    $map = new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::Integer]));

    yield 'a body with no type and no property' => [
        ['application/json' => new Schema(additionalPropertiesSchema: new Schema(types: [SchemaType::Integer]))],
        [],
        'The body',
    ];
    yield 'an array body of maps' => [
        ['application/json' => new Schema(types: [SchemaType::Array], items: $map)],
        [],
        'The body',
    ];
    yield 'a query parameter typed object' => [
        [],
        [new QueryParameter('filter', new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::String])))],
        '`filter`',
    ];
    yield 'a query parameter typed array of maps' => [
        [],
        [new QueryParameter('filters', new Schema(types: [SchemaType::Array], items: $map))],
        '`filters`',
    ];
});

// `additionalProperties: {}` allows any value, as `true` does: no value rule and no finding. A keyword nothing reads is
// an annotation here (its position is kept apart), so a value schema that only writes one is as open. At the root the
// wildcard that keeps every key in `validated()` asks nothing of the value.
it('reads an empty additionalProperties schema as an open object', function (Schema $value, bool $nested): void {
    $map = new Schema(types: [SchemaType::Object], additionalPropertiesSchema: $value);
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $nested ? objectSchema(['meta' => $map]) : $map]));

    expect($set->rules)->toBe($nested ? ['meta' => ['sometimes', 'array']] : ['*' => ['sometimes']])
        ->and($set->findings)->toBe([])
        ->and($set->unenforced)->toBe([]);
})->with(function (): Generator {
    $annotation = new Schema(ignored: ['description' => '#/components/schemas/Anything/additionalProperties/description']);

    foreach (['at the root' => false, 'nested' => true] as $where => $nested) {
        yield 'an empty schema, '.$where => [new Schema, $nested];
        yield 'an annotation alone, '.$where => [$annotation, $nested];
        // Where the empty schema is written (a `$ref` to a component) is not what it states.
        yield 'a $ref to an empty schema, '.$where => [new Schema(name: 'AnyValue', source: '#/components/schemas/AnyValue'), $nested];
    }
});

// The wildcard reaches every key at its level, declared or not (measured on Laravel's validator), so beside declared
// properties it would judge them by the value schema too. That is a different answer from the contract's, so the values
// are reported instead and the declared property keeps exactly its own rules.
it('leaves the values of a map unvalidated beside declared properties', function (bool $nested): void {
    $map = new Schema(
        types: [SchemaType::Object],
        properties: ['id' => new Schema(types: [SchemaType::Integer])],
        additionalPropertiesSchema: new Schema(types: [SchemaType::String]),
    );
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $nested ? objectSchema(['meta' => $map]) : $map]));
    $label = $nested ? '`meta`' : 'The body itself';

    expect(array_keys($set->rules))->toBe($nested ? ['meta', 'meta.id'] : ['id'])
        ->and($set->rules[$nested ? 'meta.id' : 'id'])->toBe(['sometimes', 'integer:strict'])
        ->and(array_values(array_filter($set->findings, static fn (string $finding): bool => str_contains($finding, 'additionalProperties'))))
        ->toBe([$label.' states `additionalProperties`, which nothing in this rule set enforces.'])
        ->and($set->unenforced)->toBe(['additionalProperties' => [$label]]);
})->with(['at the root' => [false], 'nested' => [true]]);

// A query parameter shares the validated data with the body, so a wildcard at the body's root would judge the query's
// keys by the map's value schema too. The root map's values are reported there; a nested map is not at risk, since its
// keys sit under a name of its own.
it('leaves the values of a map body unvalidated beside query parameters', function (): void {
    $integer = new Schema(types: [SchemaType::Integer]);
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => new Schema(types: [SchemaType::Object], additionalPropertiesSchema: $integer)],
        query: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));
    $nested = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['meta' => new Schema(types: [SchemaType::Object], additionalPropertiesSchema: $integer)])],
        query: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));

    // The wildcard that keeps every body key in `validated()` judges none of them, and the parameter's own key keeps its
    // rules.
    expect($set->rules)->toBe(['*' => ['sometimes'], 'page' => ['sometimes', 'integer']])
        ->and($set->unenforced)->toBe(['additionalProperties' => ['The body itself']])
        ->and(array_values(array_filter($set->findings, static fn (string $finding): bool => str_contains($finding, 'additionalProperties'))))
        ->toBe(['The body itself states `additionalProperties`, which nothing in this rule set enforces.'])
        ->and($nested->rules['meta.*'])->toBe(['integer:strict'])
        ->and($nested->unenforced)->toBe([]);
});

// A form delivers a key PHP rewrites (`a.b` as `a_b`, `a b` as `a_b`) under the rewritten name, and a map's keys are all
// undeclared, so the request cannot say which key the client meant. A JSON body keeps every key as sent.
it('says a form delivers a map key PHP rewrites under its rewritten name', function (array $mediaTypes, int $findings): void {
    assert(array_is_list($mediaTypes));
    $content = array_fill_keys($mediaTypes, new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::Integer])));
    $set = RuleSetBuilder::for(operationWithInput(content: $content));

    $named = array_values(array_filter($set->findings, static fn (string $finding): bool => str_contains($finding, 'PHP rewrites')));

    // Information, not a constraint left unenforced.
    expect($named)->toHaveCount($findings)
        ->and(array_values(array_intersect($named, $set->information)))->toBe($named);
})->with([
    'form-encoded' => [['application/x-www-form-urlencoded'], 1],
    'multipart' => [['multipart/form-data'], 1],
    'JSON and a form' => [['application/json', 'application/x-www-form-urlencoded'], 1],
    'JSON only' => [['application/json'], 0],
]);

// A finding that loses no constraint (a body key that echoes a `query` parameter, a name a form rewrites) is in the file
// and out of the build's "read a constraint they do not enforce" warning, which counts only what the request leaves
// unenforced: a body that is `{type: object}` states nothing the rule set fails to carry, and has no finding at all.
it('keeps the findings that lose no constraint out of the unenforced warning', function (array $content, array $query, bool $informs, bool $unenforced): void {
    assert(array_is_list($query));
    $set = RuleSetBuilder::for(operationWithInput(content: $content, query: $query));

    expect($set->leavesUnenforced())->toBe($unenforced)
        ->and($set->findings !== [])->toBe($informs || $unenforced);
})->with(function (): array {
    $integers = new Schema(types: [SchemaType::Integer]);
    $page = [new QueryParameter('page', $integers)];

    return [
        'an open object' => [['application/json' => new Schema(types: [SchemaType::Object])], [], false, false],
        'additionalProperties: true' => [['application/json' => new Schema(types: [SchemaType::Object], additionalProperties: true)], [], false, false],
        'a map of strings' => [['application/json' => new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::String]))], [], false, false],
        'an open object beside a query parameter' => [['application/json' => new Schema(types: [SchemaType::Object])], $page, true, false],
        // Beside a query parameter the values are not validated, which is a constraint left unenforced.
        'a map beside a query parameter' => [['application/json' => new Schema(types: [SchemaType::Object], additionalPropertiesSchema: $integers)], $page, true, true],
        'a map beside properties' => [['application/json' => new Schema(types: [SchemaType::Object], properties: ['id' => $integers], additionalPropertiesSchema: $integers)], [], false, true],
    ];
});

// A body closed to every key has none for a client to send, so there is nothing to say about the keys it may send and no
// wildcard to keep them.
it('says nothing about the keys of a body that is closed and declares no property', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => new Schema(types: [SchemaType::Object], additionalProperties: false)],
        query: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));

    expect($set->rules)->toBe(['page' => ['sometimes', 'integer']])
        ->and($set->findings)->toBe([])
        ->and($set->leavesUnenforced())->toBeFalse();
});

// `validationData()` reads a `query` parameter's key from the query string and drops it from the body, so a body key of
// that name is never read from the body: the finding names the key the value arrives under, never the parameter's
// written name (`page`, not `page[size]`).
it('says a map key named like a query parameter is read from the query string', function (string $parameter, string $key): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::Integer]))],
        query: [new QueryParameter($parameter, new Schema(types: [SchemaType::Integer]))],
    ));
    $named = array_values(array_filter($set->findings, static fn (string $finding): bool => str_contains($finding, 'read from the query string')));

    expect($named)->toHaveCount(1)
        ->and($named[0])->toContain('`'.$key.'`')
        ->and($named[0])->not->toContain('page[size]');
})->with([
    'a plain name' => ['page', 'page'],
    'a bracketed name' => ['page[size]', 'page'],
]);

it('says nothing about a query key for an object body that declares its properties', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['name' => new Schema(types: [SchemaType::String])])],
        query: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));

    expect(implode("\n", $set->findings))->not->toContain('read from the query string');
});

// The one place a caller asks whether the values of a root map are judged, so a rule set and a DTO never disagree.
it('judges the values of a root map only where no query parameter shares the data', function (array $query, bool $judged): void {
    assert(array_is_list($query));

    expect(RuleSetBuilder::validatesRootMapValues(operationWithInput(
        content: ['application/json' => new Schema(types: [SchemaType::Object], additionalPropertiesSchema: new Schema(types: [SchemaType::Integer]))],
        query: $query,
    )))->toBe($judged);
})->with([
    'no query parameter' => [[], true],
    'one query parameter' => [[new QueryParameter('page', new Schema(types: [SchemaType::Integer]))], false],
]);

// A cut of a recursion through a map's values is a map that is empty, not a list that is: the finding names which, and a
// list's cut keeps its own sentence.
it('says an empty map passes at the cut of a recursion', function (): void {
    $position = '#/components/schemas/Node';
    $selfMap = new Schema(
        name: 'Node',
        types: [SchemaType::Object],
        maxDepth: 1,
        source: $position,
        additionalPropertiesSchema: Schema::recursion($position, 'Node'),
    );

    $finding = implode("\n", RuleSetBuilder::for(operationWithInput(content: ['application/json' => $selfMap]))->findings);

    expect($finding)->toContain('An empty map of `Node` passes')
        ->and($finding)->not->toContain('An empty list');
});

// A form carries text, so a map of integers keeps the loose `integer` there as any integer field does, and a JSON-only
// body keeps its type.
it('keeps the loose scalar rules for the values of a form map', function (string $mediaType, string $rule): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [$mediaType => new Schema(
        types: [SchemaType::Object],
        additionalPropertiesSchema: new Schema(types: [SchemaType::Integer]),
    )]));

    expect($set->rules)->toBe(['*' => [$rule]]);
})->with([
    'form-encoded' => ['application/x-www-form-urlencoded', 'integer'],
    'multipart' => ['multipart/form-data', 'integer'],
    'JSON only' => ['application/json', 'integer:strict'],
]);

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
 *
 * This body is built by hand, so it holds every version's default. The tests after this one give the document's own
 * version's.
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

/**
 * The `encoding` finding and the `encoding` entry of `unenforced` a part leaves under one version's table.
 *
 * @param  array<string, Schema>  $properties
 * @param  list<string>  $mediaTypes
 * @param  array<string, string>  $encoding  part, then its `contentType`
 * @return array{0: bool, 1: bool} whether the finding was written, and whether `encoding` is left unenforced
 */
function encodingReportedWith(?EncodingDefaults $table, array $properties, array $mediaTypes, array $encoding): array
{
    $set = RuleSetBuilder::for(operationWithInput(
        content: array_fill_keys($mediaTypes, objectSchema($properties)),
        encoding: ['multipart/form-data' => $encoding, 'application/x-www-form-urlencoded' => $encoding],
        encodingDefaults: $table,
    ));

    $part = array_key_first($encoding);

    return [
        str_contains(implode("\n", $set->findings), 'The `encoding` of `'.$part.'` declares'),
        in_array('`'.$part.'`', $set->unenforced['encoding'] ?? [], true),
    ];
}

// A part's default `contentType` is the one its document's own version gives it, so a type is silent only where that
// version names it. Each row runs under 3.0.4's table and 3.1.1's, as the strategies hand them.
it('reports a contentType that is not a default of the document version', function (
    Schema $part,
    string $contentType,
    array $mediaTypes,
    bool $reportedAt30,
    bool $reportedAt31,
): void {
    assert(array_is_list($mediaTypes));

    foreach ([[(new OpenApi30Strategy)->encodingDefaults(), $reportedAt30], [(new OpenApi31Strategy)->encodingDefaults(), $reportedAt31]] as [$table, $reported]) {
        expect(encodingReportedWith($table, ['part' => $part], $mediaTypes, ['part' => $contentType]))
            ->toBe([$reported, $reported]);
    }
})->with(function (): array {
    $form = ['application/x-www-form-urlencoded'];
    $mixed = ['multipart/form-data', 'application/json'];
    $multipart = ['multipart/form-data'];
    $typedFile = new Schema(types: [SchemaType::String], isFilePart: true);
    $text = new Schema(types: [SchemaType::String]);
    $byte = new Schema(types: [SchemaType::String], format: 'byte');
    $integer = new Schema(types: [SchemaType::Integer]);

    return [
        'a typed file part written text/plain, in a form' => [$typedFile, 'text/plain', $form, true, false],
        'a typed file part written text/plain, in a mixed body' => [$typedFile, 'text/plain', $mixed, true, false],
        'a typed file part written application/octet-stream, in a form' => [$typedFile, 'application/octet-stream', $form, false, true],
        'a plain string written application/octet-stream' => [$text, 'application/octet-stream', $multipart, true, false],
        // Until a 3.1 string's `contentEncoding` is read, its row holds both types: 3.1.1 gives such a string
        // `application/octet-stream`, and the part is told from a plain one by nothing the rule set sees.
        'a plain string written text/plain' => [$text, 'text/plain', $multipart, false, false],
        'an integer written both types' => [$integer, 'text/plain, application/octet-stream', $multipart, true, true],
        'an integer written text/plain' => [$integer, 'text/plain', $multipart, false, false],
        'a byte string written application/octet-stream' => [$byte, 'application/octet-stream', $multipart, false, false],
        'a byte string written text/plain' => [$byte, 'text/plain', $multipart, true, false],
        'a file part with no type written text/plain' => [new Schema(isFilePart: true), 'text/plain', $form, true, true],
        'a file part with no type written application/octet-stream' => [new Schema(isFilePart: true), 'application/octet-stream', $form, false, false],
        'an array of integers written application/octet-stream' => [new Schema(types: [SchemaType::Array], items: $integer), 'application/octet-stream', $multipart, true, true],
        'an array of byte strings written application/octet-stream' => [new Schema(types: [SchemaType::Array], items: $byte), 'application/octet-stream', $multipart, false, false],
        'an object written application/json' => [objectSchema([]), 'application/json', $multipart, false, false],
        'a part with no type, no file, written application/octet-stream' => [new Schema, 'application/octet-stream', $multipart, false, false],
    ];
});

// The card's own case: a 3.0 binary part's `text/plain` is not its default, in a form and a mixed body (where it used
// to read as a default) and in a multipart body alone (where it was already reported, since the request reads the part
// as a file and no `mimetypes` rule matches a text type). At 3.1 the three differ on purpose: 3.1.1 gives a
// `type: string` file part `text/plain`, so a form or a mixed body is silent on it and reports `application/octet-stream`,
// while a multipart body alone reads the part as a file, where `application/octet-stream` means any bytes.
it('reports a 3.0 binary part written text/plain in every body', function (
    array $mediaTypes,
    string $contentType,
    bool $reportedAt30,
    bool $reportedAt31,
): void {
    assert(array_is_list($mediaTypes));

    $file = new Schema(types: [SchemaType::String], isFilePart: true);

    foreach ([[(new OpenApi30Strategy)->encodingDefaults(), $reportedAt30], [(new OpenApi31Strategy)->encodingDefaults(), $reportedAt31]] as [$table, $reported]) {
        expect(encodingReportedWith($table, ['avatar' => $file], $mediaTypes, ['avatar' => $contentType]))
            ->toBe([$reported, $reported]);
    }
})->with([
    'a form, text/plain' => [['application/x-www-form-urlencoded'], 'text/plain', true, false],
    'a mixed body, text/plain' => [['multipart/form-data', 'application/json'], 'text/plain', true, false],
    'a multipart body alone, text/plain' => [['multipart/form-data'], 'text/plain', true, true],
    'a form, application/octet-stream' => [['application/x-www-form-urlencoded'], 'application/octet-stream', false, true],
    'a mixed body, application/octet-stream' => [['multipart/form-data', 'application/json'], 'application/octet-stream', false, true],
    'a multipart body alone, application/octet-stream' => [['multipart/form-data'], 'application/octet-stream', false, false],
]);

// A body built by hand names no version, so it holds both defaults of each kind of part and reports nothing a version
// could call one. The exception is a file part with no `type`: every version gives it `application/octet-stream`.
it('keeps the widest defaults for a request body built by hand', function (
    Schema $part,
    string $contentType,
    array $mediaTypes,
    bool $reported,
): void {
    assert(array_is_list($mediaTypes));

    expect(encodingReportedWith(null, ['part' => $part], $mediaTypes, ['part' => $contentType]))
        ->toBe([$reported, $reported]);
})->with(function (): array {
    $form = ['application/x-www-form-urlencoded'];
    $multipart = ['multipart/form-data'];

    return [
        'application/octet-stream on a plain string' => [new Schema(types: [SchemaType::String]), 'application/octet-stream', $multipart, false],
        'application/octet-stream on an integer' => [new Schema(types: [SchemaType::Integer]), 'application/octet-stream', $multipart, false],
        'text/plain on a typed file part' => [new Schema(types: [SchemaType::String], isFilePart: true), 'text/plain', $form, false],
        'application/octet-stream on a typed file part' => [new Schema(types: [SchemaType::String], isFilePart: true), 'application/octet-stream', $form, false],
        'text/plain on a byte string' => [new Schema(types: [SchemaType::String], format: 'byte'), 'text/plain', $multipart, false],
        'application/octet-stream on a byte string' => [new Schema(types: [SchemaType::String], format: 'byte'), 'application/octet-stream', $multipart, false],
        'text/plain on a file part with no type' => [new Schema(isFilePart: true), 'text/plain', $form, true],
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

// PHP rewrites a query name before Laravel sees it: `.` and a space become `_`, and `page[size]` becomes a
// nested array. The value is there, under the key PHP delivers, so the rule is keyed by that key (measured with
// `parse_str()`, which the request's query bag is built from) and the name the contract writes stays the label.
it('keys a query name PHP rewrites by the key PHP delivers', function (string $name, Schema $schema, array $rules, string $head, ?string $finding): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [new QueryParameter($name, $schema, required: true)],
    ));

    // `toEqual`: a boolean's rule holds an `InRule`, which `toBe` compares by identity.
    expect($set->rules)->toEqual($rules)
        ->and(array_keys($set->rules))->toBe(array_keys($rules))
        ->and($set->queryKeys)->toBe([$head])
        ->and($set->unenforced)->toBe([]);

    if ($finding === null) {
        expect($set->findings)->toBe([]);
    } else {
        expect(implode("\n", $set->findings))->toContain($finding);
    }
})->with([
    'a bracketed name' => ['page[size]', new Schema(types: [SchemaType::Integer]), ['page.size' => ['required', 'integer']], 'page', null],
    'three levels' => ['filter[created][gte]', new Schema(types: [SchemaType::Integer]), ['filter.created.gte' => ['required', 'integer']], 'filter', null],
    'a dot' => ['user.name', new Schema(types: [SchemaType::Integer]), ['user_name' => ['required', 'integer']], 'user_name', null],
    'a space' => ['a b', new Schema(types: [SchemaType::Integer]), ['a_b' => ['required', 'integer']], 'a_b', null],
    'a dot inside the brackets, which PHP keeps' => ['page[a.b]', new Schema(types: [SchemaType::Integer]), ['page.a\.b' => ['required', 'integer']], 'page', null],
    'a leading space, which PHP drops' => [' a', new Schema(types: [SchemaType::Integer]), ['a' => ['required', 'integer']], 'a', null],
    'an array, as presence only' => ['filter[ids]', new Schema(types: [SchemaType::Array]), ['filter.ids' => ['present']], 'filter', '`filter[ids]` is a `query` parameter typed `array`'],
    // The finding hands over code to read the value, so it names the key PHP delivers (`filter.active`) and never the
    // contract's name, which finds nothing in the query bag.
    'a boolean, read under the delivered key' => ['filter[active]', new Schema(types: [SchemaType::Boolean]), ['filter.active' => ['required', new InRule(['true', 'false', '1', '0'])]], 'filter', '`\Illuminate\Support\Arr::has($request->query->all(), \'filter.active\') ? filter_var($request->validated(\'filter.active\'), FILTER_VALIDATE_BOOL) : null`'],
    // A step that holds a dot is no dotted key: `validated()` holds the value nested under it, so the read goes by steps.
    'a boolean, with a dot inside the brackets' => ['filter[a.b]', new Schema(types: [SchemaType::Boolean]), ['filter.a\\.b' => ['required', new InRule(['true', 'false', '1', '0'])]], 'filter', "`data_get(\$request->validated(), ['filter', 'a.b'])` holds the string as sent"],
    'an object, as sometimes' => ['filter[range]', new Schema(types: [SchemaType::Object]), ['filter.range' => ['sometimes']], 'filter', '`filter[range]` is a `query` parameter typed `object`'],
]);

// A query bag holds `page => [size => ...]`, so `validationData()` reads the key `page` once, however many names
// are delivered below it.
it('reads each query key once however many rewritten names share it', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('page[number]', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('limit', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect($set->queryKeys)->toBe(['page', 'limit'])
        ->and($set->rules)->toBe([
            'page.size' => ['sometimes', 'integer'],
            'page.number' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer'],
        ]);
});

// Two names on separate paths are two keys, however much of the text they share: `filter[a]` is no prefix of
// `filter[ab][c]`.
it('keeps the rule of rewritten query names that are not on one path', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [
            new QueryParameter('filter[a]', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('filter[ab][c]', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect($set->rules)->toBe(['filter.a' => ['sometimes', 'integer'], 'filter.ab.c' => ['sometimes', 'integer']])
        ->and($set->queryKeys)->toBe(['filter'])
        ->and($set->findings)->toBe([]);
});

// PHP gives a name that appends (`ids[]`, each value at the next free index) or that it delivers nowhere (`[x]`) no
// key a rule can hold, and a `*` is Laravel's wildcard in a rule key. Each keeps a finding and no rule, and the doctor
// still sees what the contract asks of it.
it('keeps reporting a query name PHP appends to or delivers under no name', function (string $name, string $reason): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [
            new QueryParameter($name, new Schema(types: [SchemaType::Integer]), required: true),
            new QueryParameter('limit', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect($set->rules)->toBe(['limit' => ['sometimes', 'integer']])
        ->and($set->queryKeys)->toBe(['limit'])
        ->and($set->unenforced)->toBe(['required' => ['`'.$name.'`']])
        ->and(implode("\n", $set->findings))->toContain('`'.$name.'`')
        ->and(implode("\n", $set->findings))->toContain($reason);
})->with([
    'a list' => ['ids[]', 'whose name PHP appends to'],
    'a list of objects' => ['a[][b]', 'whose name PHP appends to'],
    'a list with a tail' => ['a[]b', 'whose name PHP appends to'],
    'no name' => ['[x]', 'whose name PHP delivers under no name'],
    'a wildcard below a key' => ['page[*]', 'carries a `*`'],
]);

// The `*` is checked first: a name that PHP also rewrites would get the wildcard key `page.*` otherwise.
it('keeps a star in a bracketed query name out of the rule keys', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [new QueryParameter('page[*]', new Schema(types: [SchemaType::Integer]), required: true)],
    ));

    expect($set->rules)->toBe([])
        ->and(implode("\n", $set->findings))->not->toContain('page.*');
});

// One key holds the body's value and the query's, so a rule for the query's would judge the body's. No rule is
// emitted for it, so the build is not choosing which half of the contract to enforce, and the body's own rules are
// what they were: this is a finding, not the refusal a plain name gets.
it('keeps reporting a query name delivered under a key the body arrives under', function (array $content, string $name, string $key): void {
    $query = new QueryParameter($name, new Schema(types: [SchemaType::Integer]), required: true);
    $alone = RuleSetBuilder::for(operationWithInput(content: $content));
    $set = RuleSetBuilder::for(operationWithInput(content: $content, query: [$query]));

    expect($set->rules)->toBe($alone->rules)
        ->and($set->queryKeys)->toBe([])
        ->and($set->unenforced)->toBe(['required' => ['`'.$name.'`']])
        ->and(implode("\n", $set->findings))->toContain('`'.$name.'` is a `query` parameter that PHP delivers under `'.$key.'`, a key the body arrives under too');
})->with([
    'a JSON body property' => [['application/json' => objectSchema(['page' => new Schema(types: [SchemaType::Integer])])], 'page[size]', 'page'],
    'a form body bracketed name' => [['application/x-www-form-urlencoded' => objectSchema(['page[number]' => new Schema(types: [SchemaType::Integer])])], 'page[size]', 'page'],
    'a JSON body name a query dot becomes' => [['application/json' => objectSchema(['user_name' => new Schema(types: [SchemaType::String])])], 'user.name', 'user_name'],
]);

// PHP gives two names one value when they arrive under the same key (`user.name` and `user_name`), or when one is
// an index of a list the other appends to (`ids[1]` and `ids[]`): the value of one is the value of the other, so a rule
// for either would judge both. Neither gets a rule, and each pair is told once.
it('gives no rule to a rewritten query name that overlaps another query name', function (array $names, string $marker, string $finding): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: array_values(array_map(
            static fn (string $name): QueryParameter => new QueryParameter($name, new Schema(types: [SchemaType::Integer]), required: true),
            $names,
        )),
    ));
    $lost = array_map(
        static fn (string $name): string => '`'.$name.'`',
        $names,
    );

    expect($set->rules)->toBe([])
        ->and($set->whenNotArray)->toBe([])
        ->and($set->queryKeys)->toBe([])
        ->and($set->unenforced['required'])->toBe($lost)
        ->and(implode("\n", $set->findings))->toContain($finding)
        ->and(substr_count(implode("\n", $set->findings), $marker))->toBe(1);
})->with([
    'a rewritten name and the plain name it becomes' => [['user_name', 'user.name'], 'under one key', '`user_name` and `user.name` are `query` parameters PHP delivers under one key (`user_name`)'],
    'two rewritten names under one key' => [['a.b', 'a b'], 'under one key', '`a.b` and `a b` are `query` parameters PHP delivers under one key (`a_b`)'],
    'an index and a list PHP appends at any index of' => [['ids[1]', 'ids[]'], 'at any index', '`ids[1]` and `ids[]` are `query` parameters whose keys overlap: PHP appends `ids[]` at any index of `ids`'],
]);

// What the exact-key pairs above leave out: the name beside a pair is untouched, and two names that share a head but
// no path are no pair: `filter[a]` is no prefix of `filter[ab][c]`, so both keep their rules and `filter` is read.
it('gives no rule to either of two query names PHP delivers under one key', function (array $names, array $rules, array $queryKeys, array $lost): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: array_values(array_map(
            static fn (string $name): QueryParameter => new QueryParameter($name, new Schema(types: [SchemaType::Integer]), required: true),
            [...$names, 'limit'],
        )),
    ));

    expect($set->rules)->toBe(array_fill_keys([...$rules, 'limit'], ['required', 'integer']))
        ->and($set->whenNotArray)->toBe([])
        ->and($set->queryKeys)->toBe($queryKeys)
        ->and($set->unenforced['required'] ?? [])->toBe($lost);
})->with([
    'a third name beside a pair' => [['user_name', 'user.name'], [], ['limit'], ['`user_name`', '`user.name`']],
    'two names on different paths of one head' => [['filter[a]', 'filter[ab][c]'], ['filter.a', 'filter.ab.c'], ['filter', 'limit'], []],
]);

// A name beside the names PHP nests under it (`page` beside `page[size]`, `ids` beside `ids[]`) keeps its rules. PHP
// builds `page => [size => 3]` for the nested one, so the rules judge it only when its value is not an array, and the
// plain name stays in `QUERY_KEYS`, so `validated('page')` carries `?page=2` as it did before.
it('keeps a plain query name behind a shape check beside the names PHP nests under it', function (array $names, array $rules, array $shaped, array $lost, array $queryKeys): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: array_values(array_map(
            static fn (string $name): QueryParameter => new QueryParameter($name, new Schema(types: [SchemaType::Integer], minimum: 1.0), required: true),
            $names,
        )),
    ));

    expect(array_keys($set->rules))->toBe($rules)
        ->and($set->whenNotArray)->toBe($shaped)
        ->and($set->queryKeys)->toBe($queryKeys)
        ->and($set->unenforced['required'] ?? [])->toBe($lost)
        ->and(implode("\n", $set->findings))->not->toContain('under one key')
        ->and(implode("\n", $set->findings))->not->toContain('keys overlap');
})->with([
    'a plain name and a bracketed one below it' => [['page', 'page[size]'], ['page', 'page.size'], ['page' => ['page']], [], ['page']],
    'a plain name and a list it is appended to' => [['ids', 'ids[]'], ['ids'], ['ids' => ['ids']], ['`ids[]`'], ['ids']],
    'a plain name and a wildcard below it' => [['page', 'page[*]'], ['page'], ['page' => ['page']], ['`page[*]`'], ['page']],
    'a plain name and a rewritten one below it' => [['user_name', 'user_name[x]'], ['user_name', 'user_name.x'], ['user_name' => ['user_name']], [], ['user_name']],
    'a bracketed name below another bracketed name' => [['filter[a]', 'filter[a][b]'], ['filter.a', 'filter.a.b'], ['filter.a' => ['filter', 'a']], [], ['filter']],
    'a chain of three' => [['filter', 'filter[a]', 'filter[a][b]'], ['filter', 'filter.a', 'filter.a.b'], ['filter' => ['filter'], 'filter.a' => ['filter', 'a']], [], ['filter']],
]);

// What the array branch of the shape check does with the array PHP built. Laravel keeps an `array` key's unchecked
// content out of `validated()` only when a rule sits below it, so a name with a rule below keeps `array`, and one with
// none below (`ids[]` is appended, `page[*]` has a `*`) drops the array with `exclude`.
it('drops the array beside a plain query name only when no name below it has a rule', function (array $names, array $dropped): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: array_values(array_map(
            static fn (string $name): QueryParameter => new QueryParameter($name, new Schema(types: [SchemaType::Integer])),
            $names,
        )),
    ));

    expect($set->dropsArray)->toBe($dropped);
})->with([
    'a rule below' => [['page', 'page[size]'], []],
    'a list it is appended to' => [['ids', 'ids[]'], ['ids']],
    'a wildcard below it' => [['page', 'page[*]'], ['page']],
    // `ids[x]` sits at an index the list can append at, so it has no rule either.
    'a list and a name at one of its indexes' => [['ids', 'ids[]', 'ids[x]'], ['ids']],
    'a rule below and a wildcard' => [['page', 'page[*]', 'page[size]'], []],
    'a chain whose last name has a rule' => [['filter', 'filter[a]', 'filter[a][b]'], []],
    'a name PHP delivers under no name beside it' => [['page', '[x]'], []],
]);

// An array or an object parameter's rules do not judge the shape of the value, so there is nothing to skip.
it('leaves a query name typed array without a shape check', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        method: 'get',
        query: [
            new QueryParameter('filter[ids]', new Schema(types: [SchemaType::Array]), required: true),
            new QueryParameter('filter[ids][0]', new Schema(types: [SchemaType::Integer]), required: true),
        ],
    ));

    expect($set->rules)->toBe(['filter.ids' => ['present'], 'filter.ids.0' => ['required', 'integer']])
        ->and($set->whenNotArray)->toBe([]);
});

// A plain name beside a body key is refused, and that comes before the overlap: a contract that is refused today stays
// refused, whatever else the plain name overlaps.
it('refuses a plain query name beside a body key before it gives the name no rule', function (): void {
    $build = fn () => RuleSetBuilder::for(operationWithInput(
        content: ['application/json' => objectSchema(['page' => new Schema(types: [SchemaType::String])])],
        query: [
            new QueryParameter('page', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect($build)->toThrow(ConflictingInputException::class, 'both as a property of its request body');
});

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

// One rule set cannot read the text a form carries and still refuse a string in JSON, so a body that is both keeps
// the loose rule, and the file says per field what a JSON client gets through. These are prose findings, not
// "stricter than the contract": they are gaps, so they count in the build warning, and they leave nothing for the
// doctor to report.
it('says what a body that is JSON and a form lets through', function (SchemaType $type, string $rule, string $finding, string $mediaType): void {
    $schema = objectSchema(['field' => new Schema(types: [$type])]);
    $set = RuleSetBuilder::for(operationWithInput(content: ['application/json' => $schema, $mediaType => $schema]));

    $named = array_values(array_filter($set->findings, static fn (string $text): bool => str_starts_with($text, '`field` is a')));

    expect($set->rules['field'])->toBe(['sometimes', $rule])
        ->and($named)->toBe([$finding])
        ->and($set->stricter)->toBe([])
        ->and($set->unenforced)->toBe([]);
})->with([
    'an integer' => [
        SchemaType::Integer,
        'integer',
        '`field` is an integer in a body that is JSON and also a form. One rule set cannot read the text a form carries and '
            .'still refuse a string in JSON, so it keeps `integer`: a JSON client\'s `"42"` and `true` get through, though the '
            .'contract refuses them.',
    ],
    'a number' => [
        SchemaType::Number,
        'numeric',
        '`field` is a number in a body that is JSON and also a form. One rule set cannot read the text a form carries and '
            .'still refuse a string in JSON, so it keeps `numeric`: a JSON client\'s `"1.5"` gets through, though the '
            .'contract refuses it.',
    ],
    'a boolean' => [
        SchemaType::Boolean,
        'boolean',
        '`field` is a boolean in a body that is JSON and also a form. One rule set cannot read the text a form carries and '
            .'still refuse a string in JSON, so it keeps `boolean`: a JSON client\'s `"1"` and `1` get through, though the '
            .'contract refuses them, and a form\'s `"true"` is refused, though the contract accepts it.',
    ],
])->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

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

// A name delivered below a top-level key another name uses: `page[size]` is `page => [size => ...]`. The upper name keeps
// its rules behind a shape check, as a `query` name does, and the nested one keeps its own under the key PHP delivers.
it('keeps a bracketed name delivered below a declared top-level name, and the name above it', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['page' => new Schema(types: [SchemaType::String]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'plain' => new Schema(types: [SchemaType::String])],
        ['plain'],
    )]));

    expect($set->rules)->toBe(['page' => ['sometimes', 'string'], 'page.size' => ['sometimes', 'integer'], 'plain' => ['present', 'string']])
        ->and($set->whenNotArray)->toBe(['page' => ['page']])
        ->and($set->uncheckedNames)->toBe([])
        // Nothing is left unenforced, so `spec:build` has nothing to warn about.
        ->and($set->leavesUnenforced())->toBeFalse()
        ->and(implode("\n", $set->findings))->not->toContain('is delivered by a form as');
});

// What a form body does with a name beside the names PHP nests under it (`page` beside `page[size]`): `page` keeps its
// rules behind `Rule::when()`, judged only when its value is not an array, and `page[size]` keeps its own, keyed by the
// path PHP delivers it under and read back by `dto()`. At any depth, and in a chain. `ids[]` is read as `ids.0`, since
// a form has no append notion, and `page[*]` has a `*`, which keeps its finding and no rule, and claims its path only
// to shape the name above it. `filter[a]` is no prefix of `filter[ab][c]`, so that pair has no shape check.
it('keeps a plain form name behind a shape check beside the names a form nests under it', function (string $mediaType, array $names, array $rules, array $shaped, array $formPaths): void {
    $set = RuleSetBuilder::for(operationWithInput(content: [$mediaType => objectSchema(
        array_map(static fn (): Schema => new Schema(types: [SchemaType::Integer], minimum: 1.0), array_flip($names)),
    )]));

    expect(array_keys($set->rules))->toBe($rules)
        ->and($set->whenNotArray)->toBe($shaped)
        ->and($set->formPaths)->toBe($formPaths)
        ->and($set->uncheckedNames)->toBe([])
        ->and(implode("\n", $set->findings))->not->toContain('tell their values')
        ->and(implode("\n", $set->findings))->not->toContain('is delivered by a form as');
})->with(['application/x-www-form-urlencoded', 'multipart/form-data'])->with([
    'a plain name and a bracketed one below it' => [['page', 'page[size]'], ['page', 'page.size'], ['page' => ['page']], ['page[size]' => ['page', 'size']]],
    'a bracketed name below another bracketed name' => [['filter[a]', 'filter[a][b]'], ['filter.a', 'filter.a.b'], ['filter.a' => ['filter', 'a']], ['filter[a]' => ['filter', 'a'], 'filter[a][b]' => ['filter', 'a', 'b']]],
    'a chain of three' => [
        ['filter', 'filter[a]', 'filter[a][b]'],
        ['filter', 'filter.a', 'filter.a.b'],
        ['filter' => ['filter'], 'filter.a' => ['filter', 'a']],
        ['filter[a]' => ['filter', 'a'], 'filter[a][b]' => ['filter', 'a', 'b']],
    ],
    'a plain name and a list it is appended to' => [['ids', 'ids[]'], ['ids', 'ids.0'], ['ids' => ['ids']], ['ids[]' => ['ids', '0']]],
    'a plain name and a rewritten one below it' => [['user_name', 'user_name[x]'], ['user_name', 'user_name.x'], ['user_name' => ['user_name']], ['user_name[x]' => ['user_name', 'x']]],
    'an optional plain name and a wildcard below it' => [['page', 'page[*]'], ['page'], ['page' => ['page']], []],
    'two names on different paths of one head' => [['filter[a]', 'filter[ab][c]'], ['filter.a', 'filter.ab.c'], [], ['filter[a]' => ['filter', 'a'], 'filter[ab][c]' => ['filter', 'ab', 'c']]],
]);

// The array branch, as on the query side: `array` when a name below the plain one has a rule, `exclude` when none has
// (`page[*]` has a `*`, and the pair `a.b[c]` and `a_b[c]` share a key, so `a_b` has no ruled name below it).
it('drops the array beside a plain form name only when no name below it has a rule', function (array $names, array $dropped): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        array_map(static fn (): Schema => new Schema(types: [SchemaType::Integer]), array_flip($names)),
    )]));

    expect($set->dropsArray)->toBe($dropped);
})->with([
    'a rule below' => [['page', 'page[size]'], []],
    'a wildcard below it' => [['page', 'page[*]'], ['page']],
    'a wildcard and a rule below it' => [['page', 'page[*]', 'page[size]'], []],
    'a list it is appended to' => [['ids', 'ids[]'], []],
    'a chain whose last name has a rule' => [['filter', 'filter[a]', 'filter[a][b]'], []],
    'a pair below it, which has no rule' => [['a_b', 'a.b[c]', 'a_b[c]'], ['a_b']],
]);

// A body that is JSON and a form keeps today's rule for a plain name beside a `*` name: a JSON client's `{"page": [1]}`
// is refused by it, as the contract refuses it. And a `*` name of a form body claims its path only against the names
// of that body, so a `query` parameter of the same name still builds.
it('does not let a wildcard name shape a plain name outside a body read only as a form', function (): void {
    $schema = objectSchema([
        'page' => new Schema(types: [SchemaType::Integer]),
        'page[*]' => new Schema(types: [SchemaType::Integer]),
    ]);
    $mixed = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $schema,
        'multipart/form-data' => $schema,
    ]));
    $beside = RuleSetBuilder::for(operationWithInput(
        content: ['multipart/form-data' => objectSchema(['page[*]' => new Schema(types: [SchemaType::Integer])])],
        query: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));

    expect($mixed->rules)->toBe(['page' => ['sometimes', 'integer']])
        ->and($mixed->whenNotArray)->toBe([])
        ->and($mixed->formPaths)->toBe([])
        ->and($beside->rules)->toBe(['page' => ['sometimes', 'integer']])
        ->and($beside->whenNotArray)->toBe([]);
});

// A `page` the contract asks for keeps today's rule beside a `*` name: shaping it would let `page[*]=5` alone satisfy it,
// a 422 turned into a 200. What the rule still refuses, `page=2&page[*]=5` (PHP keeps the later name, so `page` arrives
// as an array), is stricter than the contract, and the file says so.
it('keeps the rule of a plain form name whose presence is asked for beside a wildcard name, and says it is stricter', function (Schema $schema, array $rules): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => $schema]));

    expect($set->rules)->toBe($rules)
        ->and($set->whenNotArray)->toBe([])
        ->and($set->dropsArray)->toBe([])
        ->and($set->stricter)->toHaveCount(1)
        ->and($set->stricter[0])->toContain('`page`')->toContain('`page[*]`')->toContain('page=2&page[*]=5')
        ->and($set->findings)->toContain($set->stricter[0]);
})->with([
    'a required name' => [
        objectSchema(['page' => new Schema(types: [SchemaType::Integer]), 'page[*]' => new Schema(types: [SchemaType::Integer])], ['page']),
        ['page' => ['required', 'integer']],
    ],
    'a dependent name' => [
        new Schema(
            types: [SchemaType::Object],
            properties: ['page' => new Schema(types: [SchemaType::Integer]), 'other' => new Schema(types: [SchemaType::String]), 'page[*]' => new Schema(types: [SchemaType::Integer])],
            dependentRequired: ['other' => ['page']],
        ),
        ['page' => ['present_with:other', 'integer'], 'other' => ['sometimes', 'string']],
    ],
]);

it('keeps the rule of a required plain form name beside a wildcard name in a body that may be absent', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['multipart/form-data' => objectSchema(
            ['page' => new Schema(types: [SchemaType::Integer]), 'other' => new Schema(types: [SchemaType::String]), 'page[*]' => new Schema(types: [SchemaType::Integer])],
            ['page'],
        )],
        bodyRequired: false,
    ));

    expect($set->rules['page'])->toBe(['present_with:other', 'integer'])
        ->and($set->whenNotArray)->toBe([])
        ->and($set->stricter)->toHaveCount(1);
});

// An upper name typed `array` or `object` may be an array itself, so its value cannot be told from the one a form
// builds for the name below it, and a `Rule::when()` on its shape would never run its rules. It keeps the collision.
it('gives no rule to a name typed array or object beside a name below it', function (SchemaType $type): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema([
        'page' => new Schema(types: [$type]),
        'page[size]' => new Schema(types: [SchemaType::Integer]),
    ])]));

    expect($set->rules)->toBe([])
        ->and($set->whenNotArray)->toBe([])
        ->and(array_keys($set->uncheckedNames))->toBe(['page[size]', 'page'])
        ->and(implode("\n", $set->findings))
        ->toContain('`page[size]` is delivered by a form as `page.size`, below the key `page` that `page` uses')
        ->toContain('`page` is typed `'.$type->value.'`, so its own value may be an array, and no rule can tell their values apart');
})->with([SchemaType::Array, SchemaType::Object]);

// Two names under the exact same key keep no rule. A name below them keeps its own, and their key is read by `dto()` only
// when it is not an array.
it('keeps the rule of a name below two names that arrive under one key, and gives the two none', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema([
        'user.name' => new Schema(types: [SchemaType::String]),
        'user_name' => new Schema(types: [SchemaType::String]),
        'user_name[x]' => new Schema(types: [SchemaType::Integer]),
    ])]));

    expect($set->rules)->toBe(['user_name.x' => ['sometimes', 'integer']])
        ->and($set->whenNotArray)->toBe([])
        ->and($set->scalarNames)->toBe(['user_name'])
        ->and(array_keys($set->uncheckedNames))->toBe(['user.name', 'user_name'])
        ->and(implode("\n", $set->findings))->toContain('`user.name` and `user_name` reach Laravel under one name, `user_name`')
        ->and(implode("\n", $set->findings))->not->toContain('is delivered by a form as');
});

// The DTO property of a shaped name is `Optional` only when the contract requires it: the array of the names below it
// stands in for it, so the payload cannot promise it. The rule keeps `required`, and that is not the way an unchecked
// name is kept (no rule, no presence).
it('says a required plain form name is stood in for by the names nested under it', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer])],
        ['page'],
    )]));
    $optional = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer])],
    )]));

    expect($set->rules['page'])->toBe(['required', 'integer'])
        ->and(array_keys($set->uncheckedNames))->toBe(['page'])
        ->and($set->uncheckedNames['page'])->toContain('the array a form builds for the names nested under it')
        ->and($set->scalarNames)->toBe(['page'])
        ->and($optional->uncheckedNames)->toBe([])
        ->and($optional->scalarNames)->toBe(['page']);
});

// An optional body asks for each required name once any key is sent, through the keys of the others, and a shaped name
// is a key like another: `page` names `page.size`, and `page.size` names `page`.
it('names the delivered key of the name below a shaped one in an optional body', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(
        content: ['multipart/form-data' => objectSchema(
            ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'other' => new Schema(types: [SchemaType::String])],
            ['page', 'page[size]'],
        )],
        bodyRequired: false,
    ));

    expect($set->rules['page'])->toBe(['present_with:page.size,other', 'integer'])
        ->and($set->rules['page.size'])->toBe(['present_with:page,other', 'integer']);
});

// `dependentRequired: {page: [other]}` gives `other` `present_with:page`, and a form that sends only `page[size]` delivers
// an array under `page`, which `present_with` counts as `page` sent: `other` is asked for where the contract never sees
// `page`. Stricter than the contract, and said so; dropping the dependency would let `page=2` pass without `other`.
it('keeps the dependency a shaped name triggers and says it is stricter than the contract', function (string $trigger, string $key): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: [
            $trigger => new Schema(types: [SchemaType::String]),
            $trigger.'[b]' => new Schema(types: [SchemaType::String]),
            'other' => new Schema(types: [SchemaType::String]),
        ],
        dependentRequired: [$trigger => ['other']],
    )]));

    expect($set->rules['other'])->toBe(['present_with:'.$key, 'string'])
        ->and($set->stricter)->toHaveCount(1)
        ->and($set->stricter[0])->toContain('`'.$trigger.'`')->toContain('`other`')->toContain('array')
        ->and($set->findings)->toContain($set->stricter[0]);
})->with([
    'a plain name' => ['page', 'page'],
    'a bracketed name' => ['filter[a]', 'filter.a'],
]);

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
]);

// A name above the names PHP nests under it keeps its rules, and a closed body still names the level it is delivered at:
// a value sits there (`page=2`), so `array:` would refuse it, and the key check accepts what the finding names.
it('leaves open the level a shaped name is delivered at in a closed body, and says so', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer])],
        additionalProperties: false,
    )]));

    expect($set->rules)->toBe(['page' => ['sometimes', 'integer'], 'page.size' => ['sometimes', 'integer']])
        ->and($set->closedKeys)->toBe(['page'])
        ->and(implode("\n", $set->findings))->toContain('so the key check accepts `page`');
});

it('leaves the head of a bracketed name open when the body is open', function (): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => objectSchema(
        ['page[size]' => new Schema(types: [SchemaType::Integer])],
    )]));

    expect(array_keys($set->rules))->toBe(['page.size']);
});

// `filter[a]` is `filter => [a => ...]` and `filter[a][b]` is `filter => [a => [b => ...]]`: the second lands below the
// key the first uses, whatever the depth, so the first keeps its rules behind a shape check and the second keeps its
// own. No finding for the pair, and none for names that only share a level or start alike.
it('judges a bracketed name below another bracketed name, whatever the depth', function (bool $closed, array $names, array $kept): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: array_map(static fn (): Schema => new Schema(types: [SchemaType::Integer]), array_flip($names)),
        additionalProperties: $closed ? false : null,
    )]));

    expect(array_keys($set->rules))->toBe($kept)
        ->and($set->uncheckedNames)->toBe([])
        ->and(array_filter($set->findings, static fn (string $finding): bool => str_contains($finding, 'is delivered by a form as')))->toBe([]);
})->with([
    'an open body' => [false, ['filter[a]', 'filter[a][b]'], ['filter.a', 'filter.a.b']],
    // The closing rule of `filter` is written after the rules of the names themselves.
    'a closed body' => [true, ['filter[a]', 'filter[a][b]'], ['filter.a', 'filter.a.b', 'filter']],
    'a chain of three' => [false, ['filter', 'filter[a]', 'filter[a][b]'], ['filter', 'filter.a', 'filter.a.b']],
    'siblings at depth' => [false, ['filter[a][b]', 'filter[a][c]'], ['filter.a.b', 'filter.a.c']],
    'a name that only starts like the other' => [false, ['filter[a]', 'filter[ab][c]'], ['filter.a', 'filter.ab.c']],
]);

// A form and JSON client share one rule set: `filter[a]` stays a literal key for JSON, which Laravel reads `[` in as a
// plain character, so nothing collides there, and the JSON client keeps both type checks.
it('keeps the literal rule keys of names below one another in a body that is JSON and a form', function (bool $closed): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: [
            'filter[a]' => new Schema(types: [SchemaType::String]),
            'filter[a][b]' => new Schema(types: [SchemaType::Integer]),
            ...($closed ? ['filter[c]' => new Schema(types: [SchemaType::Integer])] : []),
        ],
        additionalProperties: $closed ? false : null,
    );
    $set = RuleSetBuilder::for(operationWithInput(content: [
        'application/json' => $schema,
        'multipart/form-data' => $schema,
    ]));

    expect($set->rules)->toBe([
        'filter[a]' => ['sometimes', 'string'],
        'filter[a][b]' => ['sometimes', 'integer'],
        ...($closed ? ['filter[c]' => ['sometimes', 'integer']] : []),
    ])
        ->and($set->rules)->not->toHaveKey('filter')
        ->and(array_keys($set->uncheckedNames))->toBe(['filter[a]', 'filter[a][b]', ...($closed ? ['filter[c]'] : [])])
        ->and(implode("\n", $set->findings))->not->toContain('is delivered by a form as');
})->with(['an open body' => [false], 'a closed body' => [true]]);

// The levels above a collision close like those above any bracketed name, except the level a name is delivered at: a
// value sits there (`filter[a]=x`), so `array:` would refuse it.
it('closes the levels above a collision and leaves open the level a name is delivered at', function (array $properties, array $rules, array $closedKeys, string $finding, bool $says): void {
    $set = RuleSetBuilder::for(operationWithInput(content: ['multipart/form-data' => new Schema(
        types: [SchemaType::Object],
        properties: array_map(static fn (): Schema => new Schema(types: [SchemaType::Integer]), array_flip($properties)),
        additionalProperties: false,
    )]));

    // `toEqual`: the closing rule is written after the rules of the names themselves.
    expect($set->rules)->toEqual($rules)
        ->and($set->closedKeys)->toBe($closedKeys)
        ->and(implode("\n", $set->findings))->toContain($finding)
        ->and(str_contains(implode("\n", $set->findings), 'closed to its declared names'))->toBe($says);
})->with([
    'a name beside the level it opens' => [
        ['filter[a]', 'filter[a][b]', 'filter[c]'],
        ['filter' => ['sometimes', 'array:a,c'], 'filter.a' => ['sometimes', 'integer'], 'filter.a.b' => ['sometimes', 'integer'], 'filter.c' => ['sometimes', 'integer']],
        ['filter'],
        'so the key check accepts `filter.a`',
        true,
    ],
    'two names delivered under one bracketed key' => [
        ['a.b[c]', 'a_b[c]'],
        ['a_b' => ['sometimes', 'array:c']],
        ['a_b'],
        '`a.b[c]` and `a_b[c]` reach Laravel under one name, `a_b.c`',
        false,
    ],
]);

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
    'a query parameter PHP appends to' => [
        [],
        [new QueryParameter('ids[]', new Schema(types: [SchemaType::Integer], minimum: 1.0, maximum: 100.0), required: true)],
        ['required' => ['`ids[]`'], 'minimum' => ['`ids[]`'], 'maximum' => ['`ids[]`']],
    ],
    'a bracketed query parameter, now enforced' => [
        [],
        [new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer], minimum: 1.0, maximum: 100.0), required: true)],
        [],
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
// What the contract asks of the key stays beside it: here the root allows `null` too, so the key is asked through its
// sibling and not with a plain `present`, which would refuse a `null` body.
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
        ->and($required->rules['parent'])->toEqual(['present_with:city', new NullOrAbsent]);
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
