<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\InRule;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Requests\CreateUserRequest;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/*
 * The one proof that Laravel actually runs what the emitter writes.
 *
 * Every other test in this area asserts a string, and a string that reads
 * correctly is not the same claim as a rule the framework honors — a rule name
 * Laravel does not have, or one that means something other than the contract
 * did, fails here and nowhere else. So this boots an application, declares the
 * generated class the way a generated controller declares it, and sends real
 * payloads at it.
 *
 * The class under test is the committed golden file, which makes it the
 * artefact rather than a copy of it: it is what the emitter produces, byte for
 * byte, pinned by `tests/Unit/Generation/FormRequestEmitterTest.php`.
 *
 * @see docs/guide/code-generation/request-validation.md — "The type hint is what runs it"
 */

beforeEach(function (): void {
    // Declared as a typed parameter, which is the whole mechanism: Laravel runs
    // a FormRequest because a method declared one, and resolving it from the
    // container inside the body would validate too while hiding the one thing a
    // reader of the signature needs to see.
    Route::post('/_generated/users', fn (CreateUserRequest $request) => response()->json($request->validated()));
});

/**
 * One JSON request through the real kernel, as the rest of this suite sends
 * one: `$this->postJson()` is the Laravel idiom and Pest's closure binding
 * hides it from static analysis, so the kernel is called directly instead.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: int, 1: array<string, mixed>}
 */
function sendGeneratedRequest(array $payload, string $query = ''): array
{
    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/users'.$query,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        (string) json_encode($payload),
    ));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), $decoded];
}

it('refuses a payload the contract refuses', function (): void {
    [$status, $body] = sendGeneratedRequest(['age' => 'not a number']);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['email', 'age']);
});

it('accepts a payload the contract accepts', function (): void {
    expect(sendGeneratedRequest(['email' => 'someone@example.test', 'age' => 30]))
        ->toBe([200, ['email' => 'someone@example.test', 'age' => 30]]);
});

// `present` rather than `required` for a property that may be null. Laravel's
// `required` refuses null, and a schema requiring a nullable property is asking
// for the key rather than for a value.
it('accepts null for a nullable property', function (): void {
    expect(sendGeneratedRequest(['email' => 'someone@example.test', 'nickname' => null]))
        ->toBe([200, ['email' => 'someone@example.test', 'nickname' => null]]);
});

// A field the client omitted is absent from `validated()` rather than filled
// from a schema default, which is what lets `$model->update($validated)` leave
// a stored value alone.
it('hands back only the keys the client sent', function (): void {
    expect(sendGeneratedRequest(['email' => 'someone@example.test']))
        ->toBe([200, ['email' => 'someone@example.test']]);
});

// The query string shares the rule set, and `validated()` carries it.
it('validates a query parameter beside the body', function (): void {
    expect(sendGeneratedRequest(['email' => 'someone@example.test'], '?notify=1'))
        ->toBe([200, ['email' => 'someone@example.test', 'notify' => '1']]);
});

// Risk 1 of the card #35 plan, measured rather than assumed. Laravel's
// `boolean` accepts `1`, `0`, `"1"`, `"0"`, `true` and `false`, and refuses
// `"true"` — so `?notify=true` answers 422 on a contract that says
// `type: boolean`. Documented in request-validation.md rather than worked
// around: a looser rule would accept what the contract refuses.
it('refuses the string "true" for a boolean query parameter', function (): void {
    [$status, $body] = sendGeneratedRequest(['email' => 'someone@example.test'], '?notify=true');

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['notify']);
});

/**
 * The rules as the validator takes them.
 *
 * The emitter writes an `InRule` as `Rule::in([...])`; the validator needs the
 * framework's object, so the same translation happens here.
 *
 * @param  array<string, list<string|InRule>>  $built
 * @return array<string, list<mixed>>
 */
function frameworkRules(array $built): array
{
    return array_map(
        static fn (array $list): array => array_map(
            static fn (string|InRule $rule): mixed => $rule instanceof InRule ? Rule::in($rule->values) : $rule,
            $list,
        ),
        $built,
    );
}

/**
 * Send one JSON payload at a route validated by exactly what `RuleSetBuilder`
 * emits for a body schema — the builder's output, not a hand-written copy of
 * it, so a regression in the builder fails here.
 *
 * @param  array<string, mixed>  $payload
 */
function statusForBuiltRules(Schema $body, array $payload, bool $bodyRequired = true): int
{
    $built = RuleSetBuilder::for(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/_generated/built'),
        operationId: 'built',
        requestBody: new RequestBody(['application/json' => $body], $bodyRequired),
    ))->rules;

    $rules = frameworkRules($built);

    Route::post('/_generated/built', fn (Request $request) => response()->json($request->validate($rules)));

    return app(HttpKernel::class)->handle(Request::create(
        '/_generated/built',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        (string) json_encode($payload),
    ))->getStatusCode();
}

// JSON Schema's `required` asks for the key; Laravel's refuses `null`, `""`,
// `[]` and `{}`. Each case is a payload the contract accepts, sent through the
// rules the builder actually emits.
it('accepts an empty value the contract allows on a required key', function (Schema $field, mixed $value): void {
    $body = new Schema(types: [SchemaType::Object], properties: ['field' => $field], required: ['field']);

    expect(statusForBuiltRules($body, ['field' => $value]))->toBe(200);
})->with([
    'an empty list for an array' => [new Schema(types: [SchemaType::Array]), []],
    'an empty object for an object' => [new Schema(types: [SchemaType::Object]), new stdClass],
    'null for a field stating no type' => [new Schema, null],
    'null for a nullable string' => [new Schema(types: [SchemaType::String, SchemaType::Null]), null],
]);

// A key `required` names and `properties` does not declare accepts any value,
// `null` included, which is why the rule emitted for it is `present`.
it('accepts any value for a required key the schema does not declare', function (mixed $value): void {
    $body = new Schema(types: [SchemaType::Object], required: ['ghost']);

    expect(statusForBuiltRules($body, ['ghost' => $value]))->toBe(200);
})->with([
    'null' => [null],
    'an empty string' => [''],
    'an empty list' => [[]],
]);

// The other half: the key itself is still required.
it('still refuses a payload missing a required key', function (): void {
    $body = new Schema(types: [SchemaType::Object], required: ['ghost']);

    expect(statusForBuiltRules($body, []))->toBe(422);
});

// Laravel's default `ConvertEmptyStringsToNull` middleware turns `""` into
// `null` before any rule runs, so a non-nullable string refuses `""` over HTTP
// whatever presence rule is emitted: `string` refuses `null`. Pinned rather
// than worked around, and documented in request-validation.md.
it('refuses an empty string for a non-nullable string, because Laravel turns it into null', function (): void {
    $body = new Schema(
        types: [SchemaType::Object],
        properties: ['title' => new Schema(types: [SchemaType::String])],
        required: ['title'],
    );

    expect(statusForBuiltRules($body, ['title' => '']))->toBe(422);
});

/*
 * Part 2's table, against the framework rather than against a string: one
 * case for each rule whose meaning the design checked, because a rule name
 * that reads right and means something else fails here and nowhere else.
 */

/**
 * A body with one field, required unless said otherwise.
 */
function oneField(Schema $field, bool $required = true): Schema
{
    return new Schema(
        types: [SchemaType::Object],
        properties: ['field' => $field],
        required: $required ? ['field'] : [],
    );
}

// RFC 3339 allows a fraction of any length: JavaScript writes 3 digits, Python
// 6, .NET 7, and Go up to 9 with the trailing zeros trimmed. The grammar is a
// regex because `date_format` has no variable-width fraction.
it('accepts every RFC 3339 spelling of a date-time', function (string $value): void {
    expect(statusForBuiltRules(oneField(new Schema(types: [SchemaType::String], format: 'date-time')), ['field' => $value]))
        ->toBe(200);
})->with([
    'whole seconds, Z' => '2026-09-20T14:03:11Z',
    'whole seconds, zero offset' => '2026-09-20T14:03:11+00:00',
    'negative zero offset' => '2026-09-20T14:03:11-00:00',
    'JavaScript, 3 digits' => '2026-10-03T13:52:51.123Z',
    'an offset after 3 digits' => '2026-09-20T14:03:11.123-04:00',
    'Python, 6 digits' => '2026-10-03T13:52:51.123456Z',
    '.NET round trip, 7 digits' => '2026-10-03T13:52:51.1234567Z',
    'Go, 9 digits' => '2026-10-03T13:52:51.123456789Z',
    'Go, a trimmed fraction of 5 digits' => '2026-10-03T13:52:51.12345Z',
    'one digit and an offset' => '2026-10-03T13:52:51.5+02:00',
    'lower case t and z' => '2026-09-20t14:03:11z',
    'a leap second' => '2016-12-31T23:59:60Z',
]);

// Laravel's `date` alone accepts whatever `strtotime` accepts, so the grammar
// comes first and `date` only adds the calendar: it is what refuses a 30th of
// February, which a grammar cannot see.
it('refuses what is not an RFC 3339 date-time', function (string $value): void {
    expect(statusForBuiltRules(oneField(new Schema(types: [SchemaType::String], format: 'date-time')), ['field' => $value]))
        ->toBe(422);
})->with([
    'a phrase' => 'next tuesday',
    'a date alone' => '2026-09-20',
    'a space instead of T' => '2026-09-20 14:03:11',
    'a missing offset' => '2026-09-20T14:03:11',
    'an offset hour of 24' => '2026-09-20T14:03:11+24:00',
    'a 30th of February' => '2026-02-30T00:00:00Z',
    'an hour of 24' => '2026-09-20T24:00:00Z',
    'a minute of 60' => '2026-09-20T14:60:00Z',
    'a second of 61' => '2026-09-20T14:03:61Z',
    'a dot with no digit' => '2026-09-20T14:03:11.Z',
]);

// `D`, as for `pattern`: asked of the validator directly, because over HTTP
// `TrimStrings` removes the newline before any rule sees it.
it('refuses a date-time with a trailing newline', function (): void {
    $rules = RuleSetBuilder::for(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/_generated/date-time'),
        operationId: 'dateTime',
        requestBody: new RequestBody(['application/json' => oneField(
            new Schema(types: [SchemaType::String], format: 'date-time'),
        )], true),
    ))->rules;

    expect(validator(['field' => "2026-09-20T14:03:11Z\n"], $rules)->passes())->toBeFalse()
        ->and(validator(['field' => '2026-09-20T14:03:11Z'], $rules)->passes())->toBeTrue();
});

it('reads a date as Y-m-d and nothing looser', function (string $value, int $status): void {
    expect(statusForBuiltRules(oneField(new Schema(types: [SchemaType::String], format: 'date')), ['field' => $value]))
        ->toBe($status);
})->with([
    'a date' => ['2026-09-20', 200],
    'an unpadded month' => ['2026-9-20', 422],
    'a phrase' => ['tomorrow', 422],
]);

// `list` beside `array`: Laravel's `array` passes for an associative one.
it('refuses an object where the contract says array', function (): void {
    expect(statusForBuiltRules(oneField(new Schema(types: [SchemaType::Array])), ['field' => ['a' => 1]]))->toBe(422)
        ->and(statusForBuiltRules(oneField(new Schema(types: [SchemaType::Array])), ['field' => [1, 2]]))->toBe(200);
});

// `strict`, so `1` and `"1"` are two elements, which they are in JSON.
it('refuses a repeated element and only a repeated one', function (array $value, int $status): void {
    $field = new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String]), uniqueItems: true);

    expect(statusForBuiltRules(oneField($field), ['field' => $value]))->toBe($status);
})->with([
    'a repeat' => [['a', 'a'], 422],
    'two different values' => [['a', 'b'], 200],
]);

// Where `distinct` would not mean `uniqueItems`, the keyword is reported and
// no rule is emitted: the valid payload it used to refuse now passes.
it('accepts a repeat across two lists that are each unique', function (): void {
    $field = new Schema(types: [SchemaType::Array], items: new Schema(
        types: [SchemaType::Object],
        properties: ['tags' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String]), uniqueItems: true)],
    ));

    expect(statusForBuiltRules(oneField($field), ['field' => [['tags' => ['a']], ['tags' => ['a']]]]))->toBe(200);
});

// "All or none", the one mapping the design measured case by case.
it('accepts an optional body whole or absent, and refuses half of one', function (array $payload, int $status): void {
    $body = new Schema(
        types: [SchemaType::Object],
        properties: [
            'street' => new Schema(types: [SchemaType::String]),
            'city' => new Schema(types: [SchemaType::String]),
        ],
        required: ['street', 'city'],
    );

    expect(statusForBuiltRules($body, $payload, bodyRequired: false))->toBe($status);
})->with([
    'nothing' => [[], 200],
    'half' => [['street' => 'Main'], 422],
    'all' => [['street' => 'Main', 'city' => 'Montréal'], 200],
]);

// "A body or none": a body made only of an optional key is still a body, and
// the required ones follow it. Naming only the required siblings accepted it.
it('refuses an optional body made of optional keys alone', function (array $payload, int $status): void {
    $body = new Schema(
        types: [SchemaType::Object],
        properties: [
            'street' => new Schema(types: [SchemaType::String]),
            'note' => new Schema(types: [SchemaType::String]),
        ],
        required: ['street'],
    );

    expect(statusForBuiltRules($body, $payload, bodyRequired: false))->toBe($status);
})->with([
    'nothing' => [[], 200],
    'an optional key alone' => [['note' => 'x'], 422],
    'both' => [['street' => 'Main', 'note' => 'x'], 200],
]);

it('requires a nested object\'s required child only when the object is sent', function (array $payload, int $status): void {
    $field = new Schema(
        types: [SchemaType::Object],
        properties: ['city' => new Schema(types: [SchemaType::String])],
        required: ['city'],
    );

    expect(statusForBuiltRules(oneField($field, required: false), $payload))->toBe($status);
})->with([
    'no object' => [[], 200],
    'an object without it' => [['field' => ['other' => 1]], 422],
    'an object with it' => [['field' => ['city' => 'Québec']], 200],
]);

// A dotted property name inside a nested object: the rule set no longer
// refuses the valid payload it did when the name went into a key list.
it('accepts a nested object whose property name carries a dot', function (): void {
    $field = new Schema(
        types: [SchemaType::Object],
        properties: ['a.b' => new Schema(types: [SchemaType::String])],
        required: ['a.b'],
        additionalProperties: false,
    );

    expect(statusForBuiltRules(oneField($field), ['field' => ['a.b' => 'x']]))->toBe(200);
});

it('lets a nullable object with required keys be null', function (): void {
    $field = new Schema(
        types: [SchemaType::Object, SchemaType::Null],
        properties: ['city' => new Schema(types: [SchemaType::String])],
        required: ['city'],
    );

    expect(statusForBuiltRules(oneField($field, required: false), ['field' => null]))->toBe(200)
        ->and(statusForBuiltRules(oneField($field, required: false), ['field' => ['x' => 1]]))->toBe(422);
});

it('lets a list of nullable objects hold null', function (): void {
    $field = new Schema(types: [SchemaType::Array], items: new Schema(
        types: [SchemaType::Object, SchemaType::Null],
        properties: ['sku' => new Schema(types: [SchemaType::String])],
        required: ['sku'],
    ));

    expect(statusForBuiltRules(oneField($field), ['field' => [null, ['sku' => 'A']]]))->toBe(200)
        ->and(statusForBuiltRules(oneField($field), ['field' => [['x' => 1]]]))->toBe(422);
});

it('allows null only when both the type list and the enumeration do', function (Schema $field, int $status): void {
    expect(statusForBuiltRules(oneField($field), ['field' => null]))->toBe($status);
})->with([
    'both' => [new Schema(types: [SchemaType::String, SchemaType::Null], enum: ['a', null]), 200],
    'the enumeration alone' => [new Schema(types: [SchemaType::String], enum: ['a', null]), 422],
    'the type alone' => [new Schema(types: [SchemaType::String, SchemaType::Null], enum: ['a']), 422],
]);

it('closes a nested object to the keys it declares', function (): void {
    $field = new Schema(types: [SchemaType::Object], properties: ['a' => new Schema], additionalProperties: false);

    expect(statusForBuiltRules(oneField($field), ['field' => ['a' => 1, 'b' => 2]]))->toBe(422)
        ->and(statusForBuiltRules(oneField($field), ['field' => ['a' => 1]]))->toBe(200);
});

it('makes a dependent key follow the one that triggers it', function (array $payload, int $status): void {
    $body = new Schema(
        types: [SchemaType::Object],
        properties: ['card' => new Schema(types: [SchemaType::String]), 'postcode' => new Schema(types: [SchemaType::String])],
        dependentRequired: ['card' => ['postcode']],
    );

    expect(statusForBuiltRules($body, $payload))->toBe($status);
})->with([
    'neither' => [[], 200],
    'the trigger alone' => [['card' => '4242'], 422],
    'both' => [['card' => '4242', 'postcode' => 'H2X 1Y4'], 200],
]);

// An array, never `in:a,b`: the string form would split the first value in two.
it('reads an enumeration whose value carries a comma as one value', function (string $value, int $status): void {
    expect(statusForBuiltRules(oneField(new Schema(types: [SchemaType::String], enum: ['a,b', 'c'])), ['field' => $value]))
        ->toBe($status);
})->with([
    'the value' => ['a,b', 200],
    'half of it' => ['a', 422],
]);

// `D`: without it PCRE's `$` matches before a trailing newline, which
// ECMA-262's does not. Asked of the validator directly, because over HTTP
// Laravel's default `TrimStrings` middleware removes the newline before any
// rule sees it — which hides the difference rather than closing it, since a
// route without that middleware would see the raw value.
it('anchors a pattern the way ECMA-262 does', function (string $value, bool $passes): void {
    $rules = RuleSetBuilder::for(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/_generated/pattern'),
        operationId: 'pattern',
        requestBody: new RequestBody(['application/json' => oneField(
            new Schema(types: [SchemaType::String], pattern: '^[a-z]+$'),
        )], true),
    ))->rules;

    expect(validator(['field' => $value], $rules)->passes())->toBe($passes);
})->with([
    'a match' => ['abc', true],
    'a trailing newline' => ["abc\n", false],
]);

it('reads an exclusive bound as a strict comparison', function (float|int $value, int $status): void {
    expect(statusForBuiltRules(oneField(new Schema(types: [SchemaType::Number], exclusiveMinimum: 0.0, multipleOf: 0.5)), ['field' => $value]))
        ->toBe($status);
})->with([
    'the bound itself' => [0, 422],
    'above it, a multiple' => [1.5, 200],
    'above it, not a multiple' => [1.2, 422],
]);

// The files are a bag of their own, so a closed root that read only the form bag
// would let an undeclared upload through.
it('refuses an undeclared upload on a closed root', function (): void {
    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/users',
        'POST',
        ['email' => 'someone@example.test'],
        [],
        ['extra' => UploadedFile::fake()->create('a.bin', 1)],
        ['HTTP_ACCEPT' => 'application/json'],
    ));

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys((array) (json_decode((string) $response->getContent(), true)['errors'] ?? [])))->toBe(['extra']);
});

// The closed root, through the emitted class itself: an undeclared top-level
// key is refused, and the query string is not the body.
it('refuses a top-level key the body does not declare', function (): void {
    [$status, $body] = sendGeneratedRequest(['email' => 'someone@example.test', 'extra' => 1], '?notify=1');

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['extra']);
});

// The serializations OpenAPI writes for an array query parameter, through a
// real request: none is refused, because no rule about the shape is emitted.
it('accepts an array query parameter in the serializations OpenAPI writes', function (string $query): void {
    $rules = RuleSetBuilder::for(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/_generated/tags'),
        operationId: 'tags',
        queryParameters: [new QueryParameter(
            'tag',
            new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String])),
            required: true,
        )],
    ))->rules;

    Route::get('/_generated/tags', fn (Request $request) => response()->json($request->validate($rules)));

    $status = app(HttpKernel::class)->handle(Request::create(
        '/_generated/tags'.$query,
        'GET',
        server: ['HTTP_ACCEPT' => 'application/json'],
    ))->getStatusCode();

    expect($status)->toBe(200);
})->with([
    'form, exploded' => ['?tag=a&tag=b'],
    'form, not exploded' => ['?tag=a,b'],
    'one value' => ['?tag=a'],
]);

it('accepts a required object query parameter sent the default way', function (): void {
    $rules = RuleSetBuilder::for(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/_generated/filter'),
        operationId: 'filter',
        queryParameters: [new QueryParameter(
            'filter',
            new Schema(types: [SchemaType::Object], properties: ['status' => new Schema(types: [SchemaType::String])]),
            required: true,
        )],
    ))->rules;

    Route::get('/_generated/filter', fn (Request $request) => response()->json($request->validate($rules)));

    $status = app(HttpKernel::class)->handle(Request::create(
        '/_generated/filter?status=a',
        'GET',
        server: ['HTTP_ACCEPT' => 'application/json'],
    ))->getStatusCode();

    expect($status)->toBe(200);
});

// `present_with` rather than `required_with` even for an integer: the latter
// fires only on a non-blank trigger, so a body of blank keys required nothing.
it('requires the rest of an optional body when a key is sent blank', function (): void {
    $body = new Schema(
        types: [SchemaType::Object],
        properties: ['qty' => new Schema(types: [SchemaType::Integer]), 'note' => new Schema(types: [SchemaType::String, SchemaType::Null])],
        required: ['qty'],
    );

    expect(statusForBuiltRules($body, ['note' => null], bodyRequired: false))->toBe(422);
});

/*
 * Part 3: a file part, against the framework.
 *
 * A multipart request carries the uploads as files, and `Request::validate()`
 * reads them through `all()` beside the text fields, which is the path a
 * generated `FormRequest` takes. `UploadedFile::fake()` reports the media type
 * it is given, which is what `mimetypes` reads.
 */

/**
 * One multipart request through rules the builder emits for `$body`.
 *
 * @param  array<string, mixed>  $files  uploads, by part name
 * @param  array<string, mixed>  $fields  text parts, by name
 * @param  list<string>  $mediaTypes  the media types the body declares
 */
function statusForUpload(Schema $body, array $files, array $fields = [], array $mediaTypes = ['multipart/form-data'], HttpMethod $method = HttpMethod::Post): int
{
    $built = RuleSetBuilder::for(new Operation(
        index: 0,
        method: $method,
        path: PathTemplate::fromString('/_generated/upload'),
        operationId: 'upload',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $body), true),
    ))->rules;

    $rules = frameworkRules($built);

    // The keys only: a validated upload is an object and does not encode.
    Route::post('/_generated/upload', fn (Request $request) => response()->json(array_keys($request->validate($rules))));

    return app(HttpKernel::class)->handle(Request::create(
        '/_generated/upload',
        'POST',
        $fields,
        [],
        $files,
        ['HTTP_ACCEPT' => 'application/json'],
    ))->getStatusCode();
}

function filePart(?string $contentMediaType = null, ?int $maxLength = null, bool $nullable = false): Schema
{
    return new Schema(
        types: $nullable ? [SchemaType::String, SchemaType::Null] : [SchemaType::String],
        maxLength: $maxLength,
        isFilePart: true,
        contentMediaType: $contentMediaType,
    );
}

// `string` would refuse the upload, which is worse than no rule at all.
it('accepts an upload for a file part and refuses a string in its place', function (): void {
    $body = oneField(filePart());

    expect(statusForUpload($body, ['field' => UploadedFile::fake()->create('a.bin', 1)]))->toBe(200)
        ->and(statusForUpload($body, [], ['field' => 'not a file']))->toBe(422);
});

it('requires a required file part, and an empty upload slot is not one', function (): void {
    $body = oneField(filePart());

    expect(statusForUpload($body, []))->toBe(422)
        ->and(statusForUpload($body, [], ['field' => '']))->toBe(422);
});

it('lets an optional file part be absent', function (): void {
    expect(statusForUpload(oneField(filePart(), required: false), []))->toBe(200);
});

// A `PATCH` empties the required list for a file part as for any other.
it('lets a PATCH leave a file part out', function (): void {
    expect(statusForUpload(oneField(filePart()), [], method: HttpMethod::Patch))->toBe(200);
});

it('reads the media type from contentMediaType', function (string $declared, string $sent, int $status): void {
    expect(statusForUpload(oneField(filePart($declared)), ['field' => UploadedFile::fake()->create('a', 1, $sent)]))
        ->toBe($status);
})->with([
    'the exact type' => ['image/png', 'image/png', 200],
    'another type' => ['image/png', 'image/jpeg', 422],
    'a wildcard subtype' => ['image/*', 'image/webp', 200],
    'a wildcard that does not match' => ['image/*', 'application/pdf', 422],
    'a parameter is not part of the type' => ['image/png; charset=binary', 'image/png', 200],
    'upper case' => ['Image/PNG', 'image/png', 200],
]);

// `mimetypes` inspects the file's real type, so matching `application/octet-stream`
// literally would refuse every upload that is not one. Measured here rather than
// assumed: the rule alone refuses a PNG.
it('does not read application/octet-stream as a type to match', function (): void {
    $png = UploadedFile::fake()->create('a.png', 1, 'image/png');

    expect(validator(['field' => $png], ['field' => ['file', 'mimetypes:application/octet-stream']])->passes())->toBeFalse()
        ->and(statusForUpload(oneField(filePart('application/octet-stream')), ['field' => $png]))->toBe(200)
        ->and(statusForUpload(oneField(filePart('*/*')), ['field' => $png]))->toBe(200);
});

// Laravel sizes a file in kilobytes of 1024 bytes and takes a decimal ceiling, so
// 2500 bytes is `max:2.44140625` and the contract's number is exact to the byte.
it('enforces a size ceiling to the byte', function (int $bytes, int $maxLength, int $status): void {
    $upload = UploadedFile::fake()->createWithContent('a.bin', str_repeat('x', $bytes));

    expect(statusForUpload(oneField(filePart(maxLength: $maxLength)), ['field' => $upload]))->toBe($status);
})->with([
    'at the ceiling' => [2500, 2500, 200],
    'one byte over' => [2501, 2500, 422],
    'under a kilobyte, at the ceiling' => [500, 500, 200],
    'under a kilobyte, one byte over' => [501, 500, 422],
]);

it('lets a file part be null when the schema says so', function (): void {
    $body = oneField(filePart(nullable: true));

    expect(statusForUpload($body, [], ['field' => null]))->toBe(200)
        ->and(statusForUpload($body, []))->toBe(422);
});

it('reads several files as an array and its elements', function (int $count, int $status): void {
    $body = oneField(new Schema(
        types: [SchemaType::Array],
        items: filePart('image/png'),
        minItems: 1,
        maxItems: 2,
    ));
    $files = array_map(static fn (int $i): UploadedFile => UploadedFile::fake()->create("a{$i}.png", 1, 'image/png'), range(1, max($count, 1)));

    expect(statusForUpload($body, $count === 0 ? [] : ['field' => $files]))->toBe($status);
})->with([
    'none' => [0, 422],
    'one' => [1, 200],
    'two' => [2, 200],
    'too many' => [3, 422],
]);

it('refuses a wrong type among several files', function (): void {
    $body = oneField(new Schema(types: [SchemaType::Array], items: filePart('image/png')));

    expect(statusForUpload($body, ['field' => [
        UploadedFile::fake()->create('a.png', 1, 'image/png'),
        UploadedFile::fake()->create('b.pdf', 1, 'application/pdf'),
    ]]))->toBe(422);
});

// A file in a JSON body is a string, because JSON cannot carry one.
it('reads a file part in a JSON body as a string', function (): void {
    expect(statusForBuiltRules(oneField(filePart()), ['field' => 'aGVsbG8=']))->toBe(200);
});

it('reads a file part as a string when the body is also JSON', function (): void {
    expect(statusForUpload(oneField(filePart()), [], ['field' => 'aGVsbG8='], ['multipart/form-data', 'application/json']))
        ->toBe(200);
});

// `format: byte` is a base64 string, and nothing decodes it.
it('keeps a base64 string a string', function (): void {
    $body = oneField(new Schema(types: [SchemaType::String], format: 'byte'));

    expect(statusForBuiltRules($body, ['field' => 'aGVsbG8=']))->toBe(200);
});
