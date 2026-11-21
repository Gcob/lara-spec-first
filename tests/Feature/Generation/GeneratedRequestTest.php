<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\DeepObjectArrays;
use Gcob\LaraSpecFirst\Generation\FormRequestEmitter;
use Gcob\LaraSpecFirst\Generation\InputDtoEmitter;
use Gcob\LaraSpecFirst\Generation\InRule;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Generation\RuleSetOptions;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Requests\CreateUserRequest;
use Gcob\LaraSpecFirst\Validation\MultipleOf;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;
use Gcob\LaraSpecFirst\Validation\WholeNumber;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
 * One JSON request through the real kernel, as the rest of this suite sends one: `$this->postJson()` is the Laravel
 * idiom and Pest's closure binding hides it from static analysis, so the kernel is called directly instead.
 *
 * @param  array<string, mixed>|object  $payload  an object for a body that is `{}`, since an empty array is `[]`
 * @return array{0: int, 1: array<string, mixed>}
 */
function sendGeneratedRequest(array|object $payload, string $query = ''): array
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

// OpenAPI's default `form` style writes a boolean as `?notify=true`, and Laravel's
// `boolean` refuses the string "true". The maintainer reversed the earlier choice
// (to answer 422, documented as the price of not using a looser rule): a rule that
// refuses what the contract accepts is the worse direction, so a query parameter
// takes the four spellings a form can carry. `validated()` holds the string as sent.
it('accepts every spelling of a boolean query parameter, and refuses the rest', function (string $query, int $status): void {
    expect(sendGeneratedRequest(['email' => 'someone@example.test'], $query)[0])->toBe($status);
})->with([
    'true' => ['?notify=true', 200],
    'false' => ['?notify=false', 200],
    'one' => ['?notify=1', 200],
    'zero' => ['?notify=0', 200],
    'yes' => ['?notify=yes', 422],
    'capitalized' => ['?notify=True', 422],
    'empty' => ['?notify=', 422],
]);

it('hands a boolean query parameter to validated() as the string it was sent', function (): void {
    expect(sendGeneratedRequest(['email' => 'someone@example.test'], '?notify=false'))
        ->toBe([200, ['email' => 'someone@example.test', 'notify' => 'false']]);
});

/**
 * The rules as the validator takes them.
 *
 * The emitter writes an `InRule` as `Rule::in([...])`; the validator needs the framework's object, so the same
 * translation happens here.
 *
 * @param  array<string, list<string|InRule|MultipleOf|NullOrAbsent|WholeNumber>>  $built
 * @return array<string, list<mixed>>
 */
function frameworkRules(array $built): array
{
    return array_map(
        static fn (array $list): array => array_map(
            static fn (string|InRule|MultipleOf|NullOrAbsent|WholeNumber $rule): mixed => $rule instanceof InRule ? Rule::in($rule->values) : $rule,
            $list,
        ),
        $built,
    );
}

/**
 * Send one JSON payload at a route validated by exactly what `RuleSetBuilder` emits for a body schema — the builder's
 * output, not a hand-written copy of it, so a regression in the builder fails here.
 *
 * @param  array<string, mixed>|string  $payload  a string is sent as the raw JSON body, for a number `json_encode()`
 *                                                would write another way (`1e2`)
 */
function statusForBuiltRules(Schema $body, array|string $payload, bool $bodyRequired = true): int
{
    return responseForBuiltRules($body, $payload, $bodyRequired)[0];
}

/**
 * The same request as {@see statusForBuiltRules()}, with the keys the 422 names.
 *
 * @param  array<string, mixed>|string  $payload
 * @param  list<string>  $mediaTypes  what the body declares, over the one schema; the request sent is JSON whatever
 *                                    they are
 * @return array{0: int, 1: list<string>}
 */
function responseForBuiltRules(Schema $body, array|string $payload, bool $bodyRequired = true, array $mediaTypes = ['application/json']): array
{
    [$status, $decoded] = answerForBuiltRules($body, $payload, $bodyRequired, $mediaTypes);

    return [$status, array_map('strval', array_keys((array) ($decoded['errors'] ?? [])))];
}

/**
 * The same request as {@see responseForBuiltRules()}, with what the route answered: the validated data for a 200, and
 * the framework's error bag for a 422.
 *
 * @param  array<string, mixed>|string  $payload
 * @param  list<string>  $mediaTypes
 * @return array{0: int, 1: array<array-key, mixed>}
 */
function answerForBuiltRules(Schema $body, array|string $payload, bool $bodyRequired = true, array $mediaTypes = ['application/json']): array
{
    $built = RuleSetBuilder::for(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/_generated/built'),
        operationId: 'built',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $body), $bodyRequired),
    ), new RuleSetOptions)->rules;

    $rules = frameworkRules($built);

    Route::post('/_generated/built', fn (Request $request) => response()->json($request->validate($rules)));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/built',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        // Without the flag `1.0` is sent as `1`, and the one payload this is for would never be sent.
        is_string($payload) ? $payload : (string) json_encode($payload, JSON_PRESERVE_ZERO_FRACTION),
    ));

    /** @var array<array-key, mixed> $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), $decoded];
}

/**
 * A JSON body of one `field` of the type given, nullable or not.
 */
function scalarBody(SchemaType $type, bool $nullable = false, float|int|null $minimum = null, float|int|null $maximum = null): Schema
{
    return oneField(new Schema(
        types: $nullable ? [$type, SchemaType::Null] : [$type],
        minimum: $minimum === null ? null : (float) $minimum,
        maximum: $maximum === null ? null : (float) $maximum,
    ), required: false);
}

// A JSON body keeps its types: `"42"` is a string, `true` is a boolean, and Laravel's loose `integer`, `numeric`
// and `boolean` accept what a form or a query string writes. A whole number written as a float (`1.0`) is an integer
// to a JSON Schema validator and to this; a fraction is not.
it('refuses a string for an integer, a number or a boolean in a JSON body', function (Schema $body, array|string $payload, string $key): void {
    expect(responseForBuiltRules($body, $payload))->toBe([422, [$key]]);
})->with([
    'an integer as a string' => [scalarBody(SchemaType::Integer), ['field' => '42'], 'field'],
    'an integer as true' => [scalarBody(SchemaType::Integer), ['field' => true], 'field'],
    'an integer as a fraction' => [scalarBody(SchemaType::Integer), '{"field": 1.5}', 'field'],
    'a number as a string' => [scalarBody(SchemaType::Number), ['field' => '1.5'], 'field'],
    'a number as a whole string' => [scalarBody(SchemaType::Number), ['field' => '2'], 'field'],
    'a boolean as the string one' => [scalarBody(SchemaType::Boolean), ['field' => '1'], 'field'],
    'a boolean as the string true' => [scalarBody(SchemaType::Boolean), ['field' => 'true'], 'field'],
    'a boolean as 1' => [scalarBody(SchemaType::Boolean), ['field' => 1], 'field'],
    'a boolean as 0' => [scalarBody(SchemaType::Boolean), ['field' => 0], 'field'],
    'a nested integer' => [
        oneField(new Schema(types: [SchemaType::Object], properties: ['b' => new Schema(types: [SchemaType::Integer])]), required: false),
        ['field' => ['b' => '42']],
        'field.b',
    ],
    'an integer in an array' => [
        oneField(new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer])), required: false),
        ['field' => [1, '2']],
        'field.1',
    ],
]);

it('accepts a JSON integer, number and boolean as sent', function (Schema $body, mixed $value): void {
    expect(statusForBuiltRules($body, ['field' => $value]))->toBe(200);
})->with([
    'an integer' => [scalarBody(SchemaType::Integer), 42],
    'a number' => [scalarBody(SchemaType::Number), 1.5],
    'an integer for a number' => [scalarBody(SchemaType::Number), 2],
    'true' => [scalarBody(SchemaType::Boolean), true],
    'false' => [scalarBody(SchemaType::Boolean), false],
    'null for a nullable integer' => [scalarBody(SchemaType::Integer, nullable: true), null],
]);

// A JSON integer is the package's `WholeNumber` beside `numeric`: it takes a float whose value is exactly whole, and
// compares the float itself, where Laravel's `integer` reads it as text with 14 significant digits. The bodies are raw
// JSON, since `json_encode()` would write `1e2` as `100.0`.
it('accepts a JSON integer written as a whole float', function (string $written): void {
    expect(statusForBuiltRules(scalarBody(SchemaType::Integer), '{"field": '.$written.'}'))->toBe(200);
})->with([
    'a decimal point' => ['1.0'],
    'an exponent' => ['1e2'],
    'a decimal point and an exponent' => ['1.0e1'],
    'negative zero' => ['-0.0'],
    'the last float below 1e14' => ['99999999999999.0'],
    // Laravel's `integer` refuses these, because PHP writes them with an exponent: the rule compares the float itself.
    'one hundred trillion' => ['1e14'],
    'minus one hundred trillion' => ['-1e14'],
    'past 2^53, still exact' => ['9007199254740992.0'],
    'the largest integer' => ['9223372036854775807'],
    'an integer' => ['42'],
]);

// What a JSON integer refuses, with the message of the rule that refused it: a float that is not exactly whole fails
// `WholeNumber` alone, a string fails it and passes `numeric`, and a value that is no number fails both. The near-whole
// floats are what a client computes (`0.3 / 0.1` is `2.9999999999999996` in JavaScript): Laravel's `integer` reads them
// as `3`, and `dto()` would then have made them `2`.
it('refuses what is not a whole number for a JSON integer, with the message of the rule that refused it', function (string $written, array $messages): void {
    $class = emittedRequestClass(postWithFormSchema('application/json', oneField(new Schema(types: [SchemaType::Integer]), required: false)));

    expect(errorsForRawJson($class, '{"field": '.$written.'}'))->toBe([422, ['field' => $messages]]);
})->with([
    'a fraction' => ['1.5', ['The field field must be an integer.']],
    'just under three' => ['2.9999999999999996', ['The field field must be an integer.']],
    'just under five' => ['4.999999999999999', ['The field field must be an integer.']],
    'just over five' => ['5.00000000000001', ['The field field must be an integer.']],
    'a large float with a fraction' => ['12345678901234.6', ['The field field must be an integer.']],
    'a string' => ['"42"', ['The field field must be an integer.']],
    'a string that spells a float' => ['"1.0"', ['The field field must be an integer.']],
    'true' => ['true', ['The field field must be an integer.', 'The field field must be a number.']],
    'false' => ['false', ['The field field must be an integer.', 'The field field must be a number.']],
    'text' => ['"abc"', ['The field field must be an integer.', 'The field field must be a number.']],
    'a list' => ['[]', ['The field field must be an integer.', 'The field field must be a number.']],
    // The range of a 64-bit integer, which `(int)` can hold: past it a cast warns and wraps.
    'past the largest integer' => ['1e20', ['The field field must be an integer.']],
    'past the smallest integer' => ['-1e20', ['The field field must be an integer.']],
]);

it('lets a nullable JSON integer be null or a whole float, and refuses a string', function (string $written, int $status): void {
    expect(statusForBuiltRules(scalarBody(SchemaType::Integer, nullable: true), '{"field": '.$written.'}'))->toBe($status);
})->with([
    'null' => ['null', 200],
    'a whole float' => ['1.0', 200],
    'a string' => ['"42"', 422],
]);

it('accepts a whole float at every level of a JSON body', function (string $content, int $status, array $keys): void {
    $body = oneField(new Schema(
        types: [SchemaType::Object],
        properties: [
            'b' => new Schema(types: [SchemaType::Integer]),
            'xs' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer])),
        ],
    ), required: false);

    expect(responseForBuiltRules($body, $content))->toBe([$status, $keys]);
})->with([
    'a nested integer' => ['{"field": {"b": 1.0}}', 200, []],
    'an element' => ['{"field": {"xs": [1, 2.0]}}', 200, []],
    'a nested fraction' => ['{"field": {"b": 1.5}}', 422, ['field.b']],
    'an element that is a string' => ['{"field": {"xs": [1, "2"]}}', 422, ['field.xs.1']],
]);

// `min`, `max`, `gt` and `lt` find the number by the name of the rule beside them (`numeric`), so they keep comparing
// it as a number when it was written as a float. `multipleOf` reads the value itself.
it('compares the bounds of a JSON integer written as a float', function (Schema $field, string $written, int $status): void {
    expect(statusForBuiltRules(oneField($field, required: false), '{"field": '.$written.'}'))->toBe($status);
})->with([
    'a minimum met' => [new Schema(types: [SchemaType::Integer], minimum: 5.0), '10.0', 200],
    'a minimum missed' => [new Schema(types: [SchemaType::Integer], minimum: 5.0), '3.0', 422],
    'a maximum met' => [new Schema(types: [SchemaType::Integer], maximum: 100.0), '1e2', 200],
    'a maximum missed' => [new Schema(types: [SchemaType::Integer], maximum: 100.0), '1.01e2', 422],
    'a multiple' => [new Schema(types: [SchemaType::Integer], multipleOf: 3.0), '9.0', 200],
    'not a multiple' => [new Schema(types: [SchemaType::Integer], multipleOf: 3.0), '10.0', 422],
    'at an exclusive minimum' => [new Schema(types: [SchemaType::Integer], exclusiveMinimum: 5.0), '5.0', 422],
    'above an exclusive minimum' => [new Schema(types: [SchemaType::Integer], exclusiveMinimum: 5.0), '6.0', 200],
    'an enumeration met' => [new Schema(types: [SchemaType::Integer], enum: [1, 2]), '2.0', 200],
    'an enumeration missed' => [new Schema(types: [SchemaType::Integer], enum: [1, 2]), '3.0', 422],
]);

// The list of integers with `uniqueItems` meets both changes: a whole float is accepted, and compared by value.
it('compares the whole floats of a unique list of JSON integers by value', function (string $content, int $status, array $keys): void {
    $class = emittedRequestClass(postWithUniqueList('application/json', SchemaType::Integer));

    [$answered, $errors] = errorsForRawJson($class, $content);

    expect($answered)->toBe($status)
        ->and(array_keys($errors))->toBe($keys);
})->with([
    'the same value twice' => ['{"xs": [1, 1.0]}', 422, ['xs.0', 'xs.1']],
    'two values' => ['{"xs": [1, 2.0]}', 200, []],
]);

// A body that is JSON and a form judges a JSON client's number by its exact value, as a JSON-only body does, and takes
// the text of an integer for a form client. A JSON client's `"42"` stays accepted there, the limit its finding states
// (the runtime branch that would refuse it is #133).
it('takes a whole float from a JSON client of a body that is JSON and a form, and refuses the rest of what is not whole', function (string $written, int $status): void {
    $class = emittedRequestClass(postWithJsonAndForm('application/x-www-form-urlencoded', oneField(new Schema(types: [SchemaType::Integer]), required: false)));

    expect(statusForRawJson($class, '{"field": '.$written.'}'))->toBe($status);
})->with([
    'a decimal point' => ['1.0', 200],
    'an exponent' => ['1e2', 200],
    'a fraction' => ['1.5', 422],
    'a string' => ['"42"', 200],
    'true' => ['true', 422],
    'a float a hair under three' => ['2.9999999999999996', 422],
    'one hundred trillion' => ['1e14', 200],
]);

// What a JSON client sends is judged by its exact value in a body that is JSON and a form too: `WholeNumber` beside
// `numeric`, as in a JSON-only body. The messages are the same ones. `"42"` is the one value still let through, the gap
// the generated file states (the runtime branch that would refuse it is #133).
it('judges a JSON client\'s integer by its exact value in a body that is JSON and a form', function (string $formType, string $written, int $status, array $messages): void {
    $class = emittedRequestClass(postWithJsonAndForm($formType, oneField(new Schema(types: [SchemaType::Integer], minimum: 2.0), required: false)));

    expect(errorsForRawJson($class, '{"field": '.$written.'}'))->toBe([$status, $messages === [] ? [] : ['field' => $messages]]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data'])->with([
    'a float a hair under three' => ['2.9999999999999996', 422, ['The field field must be an integer.']],
    'a fraction' => ['2.5', 422, ['The field field must be an integer.']],
    // `true` has no number to compare, so `min` compares the length of "1" and a minimum of 2 fails too.
    'true' => ['true', 422, ['The field field must be an integer.', 'The field field must be a number.', 'The field field must be at least 2.']],
    'text' => ['"abc"', 422, ['The field field must be an integer.', 'The field field must be a number.']],
    'one hundred trillion' => ['1e14', 200, []],
    'past the range of a 64-bit integer' => ['1e20', 422, ['The field field must be an integer.']],
    'a whole float' => ['3.0', 200, []],
    'a whole float under the minimum' => ['1.0', 422, ['The field field must be at least 2.']],
    // The stated gap: the text of an integer, which a form client sends.
    'the text of an integer' => ['"42"', 200, []],
]);

// A form client's integer is text, and it is judged as it was: the text Laravel's `integer` takes passes and reaches
// `validated()` as sent, `1.0` is refused, and a value that is no number reads both messages. An empty field arrives as
// `null`, and reads both messages too. The framework trims the text before the rule sees it.
it('passes a form client\'s integer text in a body that is JSON and a form as it did', function (string $formType, string $sent, int $status, array $messages, string $validated = ''): void {
    $class = emittedRequestClass(postWithJsonAndForm($formType, oneField(new Schema(types: [SchemaType::Integer]), required: false)));

    [$answered, $body] = sendThroughEmitted($class, 'POST', parameters: ['field' => $sent]);

    expect($answered)->toBe($status)
        ->and($status === 200 ? $body : $body['errors'])->toBe($status === 200 ? ['field' => $validated] : ['field' => $messages]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data'])->with([
    'digits' => ['42', 200, [], '42'],
    'a plus sign' => ['+5', 200, [], '+5'],
    'negative zero' => ['-0', 200, [], '-0'],
    'padded with spaces' => [' 7 ', 200, [], '7'],
    'text' => ['abc', 422, ['The field field must be an integer.', 'The field field must be a number.']],
    'an empty field' => ['', 422, ['The field field must be an integer.', 'The field field must be a number.']],
    'a fraction' => ['2.5', 422, ['The field field must be an integer.']],
    'a decimal point on a whole number' => ['1.0', 422, ['The field field must be an integer.']],
    'past the range of a 64-bit integer' => ['9223372036854775808', 422, ['The field field must be an integer.']],
]);

// `validated()` keeps what was sent: a JSON client's `31.0` stays a float there, as it did, and `dto()` casts. A float of
// 1e14 or more used to be refused, and now reaches the DTO, which casts it exactly.
it('hands a JSON client of a body that is JSON and a form the same types a JSON-only body does', function (): void {
    $class = emittedRequestClass(postWithJsonAndForm('multipart/form-data', oneField(new Schema(types: [SchemaType::Integer]), required: false)));

    expect(typesForRawJson($class, '{"field": 31.0}'))->toBe([200, ['field' => 'float']])
        ->and(typesForRawJson($class, '{"field": 31.0}', dto: true))->toBe([200, ['field' => 'int']])
        ->and(typesForRawJson($class, '{"field": 1e14}', dto: true))->toBe([200, ['field' => 'int']]);
});

// The rule is the same at every level a body that is JSON and a form reaches: a map's values, a map beside a declared
// property, a unique list, a nested object. Each judges a JSON client's number by value and a form client's text as text.
it('judges the integer of every level of a body that is JSON and a form by its exact value', function (Schema $body, string $near, string $large, array $form): void {
    $class = emittedRequestClass(postWithJsonAndForm('multipart/form-data', $body));

    expect(statusForRawJson($class, $near))->toBe(422)
        ->and(statusForRawJson($class, $large))->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: $form)[0])->toBe(200);
})->with(function (): Generator {
    $integer = new Schema(types: [SchemaType::Integer]);

    yield 'a map' => [mapOf($integer), '{"views": 2.9999999999999996}', '{"views": 1e14}', ['views' => '3']];
    yield 'a map beside declared properties' => [
        mapBeside(['locale' => new Schema(types: [SchemaType::String])], $integer),
        '{"locale": "fr", "views": 2.9999999999999996}',
        '{"locale": "fr", "views": 1e14}',
        ['locale' => 'fr', 'views' => '3'],
    ];
    yield 'a unique list' => [
        oneField(new Schema(types: [SchemaType::Array], items: $integer, uniqueItems: true), required: false),
        '{"field": [2.9999999999999996]}',
        '{"field": [1e14]}',
        ['field' => ['3']],
    ];
    yield 'a nested object' => [
        oneField(new Schema(types: [SchemaType::Object], properties: ['b' => $integer]), required: false),
        '{"field": {"b": 2.9999999999999996}}',
        '{"field": {"b": 1e14}}',
        ['field' => ['b' => '3']],
    ];
});

// A map's values are judged by the guard of a root that declares properties, and the rule compiles inside it.
it('takes a whole float for a map value beside declared properties, and refuses a fraction', function (string $content, int $status, array $keys): void {
    $class = emittedRequestClass(postWithFormSchema('application/json', new Schema(
        types: [SchemaType::Object],
        properties: ['locale' => new Schema(types: [SchemaType::String])],
        additionalPropertiesSchema: new Schema(types: [SchemaType::Integer]),
    )));

    [$answered, $errors] = errorsForRawJson($class, $content);

    expect($answered)->toBe($status)
        ->and(array_keys($errors))->toBe($keys);
})->with([
    'a whole float' => ['{"locale": "fr", "views": 3.0}', 200, []],
    'a fraction' => ['{"locale": "fr", "views": 1.5}', 422, ['views']],
    'a float a hair under three' => ['{"locale": "fr", "views": 2.9999999999999996}', 422, ['views']],
    'a string' => ['{"locale": "fr", "views": "3"}', 422, ['views']],
]);

/**
 * One request through the real kernel carrying `$content` as its JSON body, to a route that answers the PHP type of
 * each top-level value `validated()` or `dto()` holds.
 *
 * @param  class-string<FormRequest>  $class
 * @return array{0: int, 1: array<string, string>}
 */
function typesForRawJson(string $class, string $content, bool $dto = false): array
{
    Route::post('/_generated/raw-json-types', fn () => response()->json(array_map(
        static fn (mixed $value): string => get_debug_type($value),
        $dto ? get_object_vars(app($class)->dto()) : app($class)->validated(),
    )));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/raw-json-types',
        'POST',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        $content,
    ));

    /** @var array<string, string> $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), $decoded];
}

// `validated()` returns what was sent, as it does for a query parameter's text, and `dto()` is the typed path.
it('hands validated() the float as sent and the DTO an int', function (): void {
    $class = emittedRequestClass(postWithFormSchema('application/json', oneField(new Schema(types: [SchemaType::Integer]), required: false)));

    expect(typesForRawJson($class, '{"field": 31.0}'))->toBe([200, ['field' => 'float']])
        ->and(typesForRawJson($class, '{"field": 31.0}', dto: true))->toBe([200, ['field' => 'int']])
        ->and(typesForRawJson($class, '{"field": 31}'))->toBe([200, ['field' => 'int']])
        // Nothing is read as text now, so a float of 1e14 or more is as whole as any other, and the cast loses nothing.
        ->and(typesForRawJson($class, '{"field": 1e14}', dto: true))->toBe([200, ['field' => 'int']]);
});

// The golden request, through the kernel: its `age` is `WholeNumber` beside `numeric` and `min:18`.
it('hands the golden request a whole float for age, and refuses a string', function (): void {
    Route::post('/_generated/users-types', fn (CreateUserRequest $request) => response()->json(array_map(
        static fn (mixed $value): string => get_debug_type($value),
        $request->validated(),
    )));

    $send = static function (string $content): array {
        $response = app(HttpKernel::class)->handle(Request::create(
            '/_generated/users-types',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $content,
        ));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    };

    expect($send('{"email": "someone@example.test", "age": 31.0}'))->toBe([200, ['email' => 'string', 'age' => 'float']])
        ->and($send('{"email": "someone@example.test", "age": 17.0}')[0])->toBe(422)
        ->and($send('{"email": "someone@example.test", "age": "31"}')[0])->toBe(422);
});

// One rule set cannot read a form's text and refuse a JSON string, so in a body that is JSON and a form a JSON client's
// text passes: `"42"` for an integer (which accepts the text a form carries), `"1.5"` for a number and `"1"` or `1` for
// a boolean (which keep the loose rules). The generated file says so. This pins the stated limit, and the JSON-only
// operation beside it refuses the same value (the runtime branch is #133).
it('lets a JSON client\'s text through a body that is JSON and a form, which a JSON-only body refuses', function (Schema $body, mixed $value): void {
    $mixed = responseForBuiltRules($body, ['field' => $value], mediaTypes: ['application/json', 'application/x-www-form-urlencoded']);
    $jsonOnly = responseForBuiltRules($body, ['field' => $value]);

    expect($mixed)->toBe([200, []])
        ->and($jsonOnly)->toBe([422, ['field']]);
})->with([
    'an integer as a string' => [scalarBody(SchemaType::Integer), '42'],
    'a number as a string' => [scalarBody(SchemaType::Number), '1.5'],
    'a boolean as the string one' => [scalarBody(SchemaType::Boolean), '1'],
    'a boolean as 1' => [scalarBody(SchemaType::Boolean), 1],
]);

// Laravel finds the size rules' kind by the name of the rule, `integer` or `numeric`, whatever follows the colon.
it('compares min and max as numbers under a strict rule', function (Schema $body, int $value, int $status): void {
    expect(statusForBuiltRules($body, ['field' => $value]))->toBe($status);
})->with([
    'an integer at or above minimum' => [scalarBody(SchemaType::Integer, minimum: 5), 10, 200],
    'an integer below minimum' => [scalarBody(SchemaType::Integer, minimum: 5), 3, 422],
    'a number at or below maximum' => [scalarBody(SchemaType::Number, maximum: 2.5), 2, 200],
    'a number above maximum' => [scalarBody(SchemaType::Number, maximum: 2.5), 3, 422],
]);

// The same for the other rules a number schema writes: `gt`, `lt` and `multipleOf` sit beside the strict rule.
it('compares gt, lt and multipleOf as numbers under a strict rule', function (Schema $field, float|int $value, int $status): void {
    expect(statusForBuiltRules(oneField($field, required: false), ['field' => $value]))->toBe($status);
})->with([
    'an integer above exclusiveMinimum' => [new Schema(types: [SchemaType::Integer], exclusiveMinimum: 5.0), 6, 200],
    'an integer at exclusiveMinimum' => [new Schema(types: [SchemaType::Integer], exclusiveMinimum: 5.0), 5, 422],
    'a number below exclusiveMaximum' => [new Schema(types: [SchemaType::Number], exclusiveMaximum: 2.5), 2, 200],
    'a number at exclusiveMaximum' => [new Schema(types: [SchemaType::Number], exclusiveMaximum: 2.5), 2.5, 422],
    'an integer that is a multiple' => [new Schema(types: [SchemaType::Integer], multipleOf: 3.0), 9, 200],
    'an integer that is not a multiple' => [new Schema(types: [SchemaType::Integer], multipleOf: 3.0), 10, 422],
]);

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

/**
 * A schema that holds one of itself as `next`, nullable or not, required or not, validated one level deep.
 */
function chain(bool $nullable, bool $required): Schema
{
    return new Schema(
        name: 'Chain',
        types: $nullable ? [SchemaType::Object, SchemaType::Null] : [SchemaType::Object],
        properties: ['next' => Schema::recursion('#/components/schemas/Chain', 'Chain')],
        required: $required ? ['next'] : [],
        maxDepth: 1,
        source: '#/components/schemas/Chain',
    );
}

// Below the depth a field is what the contract allows and the request still can't validate: absent, or `null` when
// the schema says so. Sent through the rules the builder emits, level 1 is `next`, and `next.next` is the cut.
it('accepts at the depth a field that is absent, or null when the schema is nullable', function (array $payload): void {
    expect(statusForBuiltRules(chain(nullable: true, required: false), $payload))->toBe(200);
})->with([
    'absent' => [['next' => []]],
    'null' => [['next' => ['next' => null]]],
    'nothing at all' => [[]],
]);

// The case `missing` broke: the last validated level writes `"next": null`, which the contract allows when it
// asks for the key and allows `null`.
it('lets the last validated level of a required nullable recursion end in null', function (): void {
    $chain = chain(nullable: true, required: true);

    expect(statusForBuiltRules($chain, ['next' => ['next' => null]]))->toBe(200)
        ->and(statusForBuiltRules($chain, ['next' => null]))->toBe(200)
        ->and(statusForBuiltRules($chain, ['next' => []]))->toBe(422);
});

it('refuses anything else at the depth, whether or not the schema is nullable', function (bool $nullable, mixed $value): void {
    $payload = ['next' => ['next' => $value]];

    expect(statusForBuiltRules(chain($nullable, required: false), $payload))->toBe(422);
})->with([
    'a nullable one, an empty list' => [true, []],
    'a nullable one, an empty object' => [true, new stdClass],
    'a nullable one, a string' => [true, 'x'],
    'a nullable one, a full object' => [true, ['next' => null]],
    'a non-nullable one, an empty list' => [false, []],
    'a non-nullable one, an empty object' => [false, new stdClass],
    'a non-nullable one, a string' => [false, 'x'],
    'a non-nullable one, a full object' => [false, ['next' => null]],
    'a non-nullable one, null' => [false, null],
]);

it('lets a non-nullable recursive field be absent at the depth', function (): void {
    expect(statusForBuiltRules(chain(nullable: false, required: false), ['next' => []]))->toBe(200);
});

// Laravel's `ConvertEmptyStringsToNull` runs before any rule, so `""` reaches the rules as `null`: a nullable field
// takes it for the `null` it allows, and a non-nullable one refuses it like any other value.
it('reads an empty string at the depth as the null Laravel turns it into', function (): void {
    expect(statusForBuiltRules(chain(nullable: true, required: false), ['next' => ['next' => '']]))->toBe(200)
        ->and(statusForBuiltRules(chain(nullable: false, required: false), ['next' => ['next' => '']]))->toBe(422);
});

/**
 * The JSON body schema of one operation of a fixture, read the way a build reads it, so a test drives the rules a real
 * contract produces rather than a schema built to look like one.
 */
function bodyOfFixture(string $fixture, string $operationId): Schema
{
    foreach (extractFixture($fixture) as $operation) {
        if ($operation->operationId === $operationId) {
            $body = $operation->requestBody;
            assert($body instanceof RequestBody);

            return $body->content['application/json'];
        }
    }

    throw new RuntimeException(sprintf('`%s` has no operation `%s`.', $fixture, $operationId));
}

// An `allOf` around a recursion is an intersection: the constraints a wrapper writes hold for the wrapper's own level,
// and the ones written beside a marker hold at every level that marker is crossed at. Each payload below is judged by
// the contract in `recursion-allof-*.yaml` (a child of a `Category` is a plain `Category`, so it needs neither
// `name` nor `slug`), at both versions, through the rules the builder emits.
it('answers a payload the way the contract reads an allOf around a recursion', function (
    string $version,
    string $operationId,
    array $payload,
    int $status,
): void {
    expect(statusForBuiltRules(bodyOfFixture('recursion-allof-'.$version.'.yaml', $operationId), $payload))->toBe($status);
})->with(function (): array {
    $cases = [
        'a body wrapping the component: a bare child is valid' => ['createCategory', ['name' => 'Root', 'slug' => 'root', 'children' => [['name' => 'Child']]], 200],
        'a body wrapping the component: the root still needs its slug' => ['createCategory', ['name' => 'Root', 'children' => []], 422],
        'a body wrapping the component: a wrong value in a child' => ['createCategory', ['name' => 'Root', 'slug' => 'root', 'children' => [['children' => 'oops']]], 422],
        'a named allOf over the component: a bare child is valid' => ['createNamedCategory', ['name' => 'Root', 'slug' => 'root', 'children' => [['name' => 'Child']]], 200],
        'a named allOf over the component: a wrong value in a child' => ['createNamedCategory', ['name' => 'Root', 'slug' => 'root', 'children' => [['children' => 'oops']]], 422],
        'a named allOf over the component: the root still needs its slug' => ['createNamedCategory', ['name' => 'Root', 'children' => []], 422],
        'a marker with a required beside it: every level named' => ['createNode', ['label' => 'a', 'next' => ['label' => 'b']], 200],
        'a marker with a required beside it: the first level lacks it' => ['createNode', ['next' => []], 422],
        'a marker with a required beside it: the second level lacks it' => ['createNode', ['label' => 'a', 'next' => ['label' => 'b', 'next' => []]], 422],
        'a marker with a required beside it: two levels, both named' => ['createNode', ['label' => 'a', 'next' => ['label' => 'b', 'next' => ['label' => 'c']]], 200],
        'a marker with a required beside it: a third level is below the depth' => ['createNode', ['label' => 'a', 'next' => ['label' => 'b', 'next' => ['label' => 'c', 'next' => ['label' => 'd']]]], 422],
        'a field wrapping the component: a bare next is valid' => ['createTree', ['root' => ['extra' => 'x', 'next' => []]], 200],
        'a field wrapping the component: the wrapped level still needs its extra' => ['createTree', ['root' => ['next' => []]], 422],
        'a field wrapping the component: a wrong value below' => ['createTree', ['root' => ['extra' => 'x', 'next' => ['next' => 'oops']]], 422],
    ];
    $datasets = [];

    foreach (['3.0', '3.1'] as $version) {
        foreach ($cases as $name => [$operationId, $payload, $status]) {
            $datasets[$name.' (OpenAPI '.$version.')'] = [$version, $operationId, $payload, $status];
        }
    }

    return $datasets;
});

// The depth is lowered evenly when the stated one does not fit the ceiling, so a binary tree stated at 20 is cut at the
// same level on `left` and on `right`: 7 crossings by hand (see `RuleSetBuilderTest`). A chain of 7 passes on both
// sides, a chain of 8 is below the depth on both, and a wrong value within the depth is refused on both.
it('cuts a tree whose depth was lowered at the same level on every branch', function (string $side): void {
    $tree = bodyOfFixture('recursion-binary-tree-deep.yaml', 'createTree');
    $chain = static function (int $levels, mixed $end = []) use ($side): array {
        $payload = $end;

        for ($level = 0; $level < $levels; $level++) {
            $payload = [$side => $payload];
        }

        return $payload;
    };

    expect(statusForBuiltRules($tree, $chain(7)))->toBe(200)
        ->and(statusForBuiltRules($tree, $chain(8)))->toBe(422)
        ->and(statusForBuiltRules($tree, [$side => 'oops']))->toBe(422)
        ->and(statusForBuiltRules($tree, $chain(2, 'oops')))->toBe(422);
})->with(['left', 'right']);

// A list is cut on its elements, so a list that is empty carries nothing and passes at the last level, while one
// element below it is refused.
it('lets an empty recursive list through at the cut and refuses an element there', function (): void {
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

    expect(statusForBuiltRules($node, ['children' => [['children' => []]]]))->toBe(200)
        ->and(statusForBuiltRules($node, ['children' => [['children' => [[]]]]]))->toBe(422);
});

/**
 * An object with no property whose every value is judged by `$value`: what `additionalProperties: <schema>` says.
 *
 * @param  list<string>  $required
 */
function mapOf(Schema $value, array $required = [], bool $nullable = false): Schema
{
    return new Schema(
        types: [SchemaType::Object, ...($nullable ? [SchemaType::Null] : [])],
        required: $required,
        additionalPropertiesSchema: $value,
    );
}

// Each value of a map answers to the value schema's own rules, under a wildcard key. A key the contract never declared
// is judged like any other, and one with a dot in it is the key as sent, never a path.
it('validates each value of a map against its additionalProperties schema', function (Schema $body, array $payload, array $errors): void {
    [$status, $answer] = answerForBuiltRules($body, $payload);

    if ($errors === []) {
        expect($status)->toBe(200)->and($answer)->toBe($payload);

        return;
    }

    expect($status)->toBe(422)->and(array_keys((array) $answer['errors']))->toBe($errors);
})->with(function (): Generator {
    $integer = new Schema(types: [SchemaType::Integer]);
    $labelled = new Schema(types: [SchemaType::Object], properties: ['n' => new Schema(types: [SchemaType::String])], required: ['n']);
    $scores = new Schema(types: [SchemaType::Array], items: $integer);

    yield 'integers, one sent' => [mapOf($integer), ['a' => 1], []];
    yield 'integers, none sent' => [mapOf($integer), [], []];
    yield 'integers, a string for one' => [mapOf($integer), ['a' => '1'], ['a']];
    yield 'integers, the bad one among good ones' => [mapOf($integer), ['a' => 1, 'b' => 'x', 'c' => 3], ['b']];
    yield 'objects, a required member missing' => [mapOf($labelled), ['x' => []], ['x']];
    yield 'objects, a member of the wrong type' => [mapOf($labelled), ['x' => ['n' => 4]], ['x.n']];
    yield 'objects, whole' => [mapOf($labelled), ['x' => ['n' => 'ok']], []];
    yield 'lists, the second element is a string' => [mapOf($scores), ['x' => [1, '2']], ['x.1']];
    yield 'a nested map, a string for one value' => [
        new Schema(types: [SchemaType::Object], properties: ['meta' => mapOf($integer)]),
        ['meta' => ['k' => 'v']],
        ['meta.k'],
    ];
    yield 'a nested map, whole' => [
        new Schema(types: [SchemaType::Object], properties: ['meta' => mapOf($integer)]),
        ['meta' => ['k' => 4]],
        [],
    ];
    yield 'a nullable value, null' => [mapOf(new Schema(types: [SchemaType::Integer, SchemaType::Null])), ['a' => null], []];
    yield 'a value that is not nullable, null' => [mapOf($integer), ['a' => null], ['a']];
    yield 'a key with a dot' => [mapOf($integer), ['a.b' => 'x'], ['a.b']];
});

// The key the map requires without declaring it is one of the keys the wildcard reaches, so its value is judged too,
// and the request asks for it as well. Written after the explicit key, the wildcard's rules join it rather than
// replace it.
it('applies the value rule to a key the map requires without declaring it', function (array $payload, int $status, array $errors): void {
    $body = mapOf(new Schema(types: [SchemaType::Integer]), required: ['a']);
    [$answered, $answer] = answerForBuiltRules($body, $payload);

    expect($answered)->toBe($status)
        ->and($status === 200 ? $answer : array_keys((array) $answer['errors']))->toBe($status === 200 ? $payload : $errors)
        ->and(implode("\n", RuleSetBuilder::for(new Operation(
            index: 0,
            method: HttpMethod::Post,
            path: PathTemplate::fromString('/_generated/built'),
            operationId: 'built',
            requestBody: new RequestBody(['application/json' => $body], true),
        ), new RuleSetOptions)->findings))->not->toContain('without declaring');
})->with([
    'the key absent' => [[], 422, ['a']],
    'the key of the wrong type' => [['a' => 'x'], 422, ['a']],
    'the key whole' => [['a' => 1], 200, []],
    'the key whole beside another' => [['a' => 1, 'b' => 2], 200, []],
    'another key of the wrong type' => [['a' => 1, 'b' => 'x'], 422, ['b']],
]);

// A nullable required body has no rule key for a name it requires without declaring: the request asks for the key itself
// and the wildcard judges the value alone, so a `null` body still passes and a body that sends the key is judged.
it('applies the value rule to a key the map requires without declaring it, on a nullable required body', function (string $content, int $status, array $errors): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/labels'),
        operationId: 'replaceLabels',
        requestBody: new RequestBody(['application/json' => mapOf(new Schema(types: [SchemaType::Integer]), required: ['a'], nullable: true)], true),
    ));

    [$answered, $keys] = errorsForRawJson($class, $content);

    expect($answered)->toBe($status)
        ->and(array_keys($keys))->toBe($errors);
})->with([
    'null' => ['null', 200, []],
    'the key of the wrong type' => ['{"a":"x"}', 422, ['a']],
    'the key whole' => ['{"a":1}', 200, []],
    'an empty object' => ['{}', 422, ['a']],
]);

// A recursion through a map is cut on its values, as one through a list is cut on its elements: down to the stated
// depth the values are judged, and the map at the cut is empty or it is a 422.
it('validates a map whose values recurse down to the stated depth', function (Schema $body, array $payload, int $status, array $errors): void {
    [$answered, $answer] = answerForBuiltRules($body, $payload);

    expect($answered)->toBe($status)
        ->and($status === 200 ? true : array_keys((array) $answer['errors']))->toBe($status === 200 ? true : $errors);
})->with(function (): Generator {
    $position = '#/components/schemas/Node';
    $selfMap = new Schema(
        name: 'Node',
        types: [SchemaType::Object],
        maxDepth: 1,
        source: $position,
        additionalPropertiesSchema: Schema::recursion($position, 'Node'),
    );
    $tree = new Schema(
        name: 'Node',
        types: [SchemaType::Object],
        properties: [
            'name' => new Schema(types: [SchemaType::String]),
            'children' => new Schema(
                types: [SchemaType::Object],
                additionalPropertiesSchema: Schema::recursion($position, 'Node'),
            ),
        ],
        maxDepth: 1,
        source: $position,
    );

    yield 'the self-map, empty' => [$selfMap, [], 200, []];
    yield 'the self-map, an empty map at the cut' => [$selfMap, ['x' => []], 200, []];
    yield 'the self-map, one key at the cut' => [$selfMap, ['x' => ['y' => []]], 422, ['x.y']];
    yield 'the self-map, a wrong value above the cut' => [$selfMap, ['x' => 'oops'], 422, ['x']];
    yield 'the tree, a child judged' => [$tree, ['children' => ['a' => ['name' => 'x', 'children' => []]]], 200, []];
    yield 'the tree, a child of the wrong type' => [$tree, ['children' => ['a' => ['name' => 1]]], 422, ['children.a.name']];
    yield 'the tree, one key at the cut' => [$tree, ['children' => ['a' => ['children' => ['b' => []]]]], 422, ['children.a.children.b']];
});

// A required field the schema cannot be null on is a way back the contract insists on. A payload that reaches the cut
// through a `Book` with no `author` is refused, as `required` says, and a payload that ends earlier, on an `Author`
// with no books, is untouched: the presence is asked of the parent, which a shorter tree does not have.
it('refuses a tree that reaches the cut without its required way back, and not one that ends early', function (array $payload, int $status): void {
    expect(statusForBuiltRules(bodyOfFixture('recursion-required-pair.yaml', 'createAuthor'), $payload))->toBe($status);
})->with([
    'nothing' => [[], 200],
    'no books' => [['books' => []], 200],
    'a book with no author, at the root' => [['books' => [['title' => 'x']]], 422],
    'two levels, ending on an author with no books' => [['books' => [['author' => ['books' => [['author' => []]]]]]], 200],
    'the last book has no author' => [['books' => [['author' => ['books' => [['title' => 'x']]]]]], 422],
    'a book reaching the cut' => [['books' => [['author' => ['books' => [['author' => ['books' => [['author' => []]]]]]]]]], 422],
]);

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
    ), new RuleSetOptions)->rules;

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

/**
 * A `POST` whose body is one list of a scalar type that may not repeat an element, under `xs`.
 */
function postWithUniqueList(string $mediaType, SchemaType $type): Operation
{
    return postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: ['xs' => new Schema(types: [SchemaType::Array], items: new Schema(types: [$type]), uniqueItems: true)],
    ));
}

// JSON Schema compares two numbers by value: `1` and `1.0` are one element. `distinct:strict` called them two, so
// the list passed. The bodies are raw JSON, since `json_encode()` would write `1.0` as `1`.
it('compares the numbers of a unique list by value in a JSON body', function (SchemaType $type, string $content, int $status, array $keys): void {
    [$answered, $errors] = errorsForRawJson(emittedRequestClass(postWithUniqueList('application/json', $type)), $content);

    expect($answered)->toBe($status)
        ->and(array_keys($errors))->toBe($keys);
})->with([
    'a whole float and the integer it equals' => [SchemaType::Number, '{"xs":[1,1.0]}', 422, ['xs.0', 'xs.1']],
    'an exponent and the integer it equals' => [SchemaType::Number, '{"xs":[10,1e1]}', 422, ['xs.0', 'xs.1']],
    'zero and negative zero' => [SchemaType::Number, '{"xs":[0,-0.0]}', 422, ['xs.0', 'xs.1']],
    'two different fractions' => [SchemaType::Number, '{"xs":[1.5,2.5]}', 200, []],
    'two different integers' => [SchemaType::Integer, '{"xs":[1,2]}', 200, []],
    'the same integer twice' => [SchemaType::Integer, '{"xs":[1,1]}', 422, ['xs.0', 'xs.1']],
    // A string has no second spelling, so `"1"` and `"01"` stay two elements.
    'two strings that read as one number' => [SchemaType::String, '{"xs":["1","01"]}', 200, []],
]);

// A form delivers text, and PHP compares two numeric strings as numbers: `1` and `+1` are one element.
it('compares the numbers of a unique list by value in a form body', function (SchemaType $type, array $sent, int $status, array $keys): void {
    $class = emittedRequestClass(postWithUniqueList('application/x-www-form-urlencoded', $type));
    [$answered, $body] = sendThroughEmitted($class, 'POST', parameters: ['xs' => $sent]);

    /** @var array<string, mixed> $errors */
    $errors = $body['errors'] ?? [];

    expect($answered)->toBe($status)
        ->and(array_keys($errors))->toBe($keys);
})->with([
    'an integer and its plus-signed spelling' => [SchemaType::Integer, ['1', '+1'], 422, ['xs.0', 'xs.1']],
    'zero and minus zero' => [SchemaType::Integer, ['0', '-0'], 422, ['xs.0', 'xs.1']],
    'two different integers' => [SchemaType::Integer, ['1', '2'], 200, []],
    'a number and its decimal spelling' => [SchemaType::Number, ['1', '1.0'], 422, ['xs.0', 'xs.1']],
    'a number and its exponent spelling' => [SchemaType::Number, ['10', '1e1'], 422, ['xs.0', 'xs.1']],
    'two different fractions' => [SchemaType::Number, ['1.5', '2.5'], 200, []],
]);

// A stated limit, not a wanted behavior: PHP compares an integer with a float as two floats, and these two round to
// the same one. The guide's `uniqueItems` row says so (2^53).
it('counts two numbers PHP cannot tell apart as one in a unique list', function (): void {
    $class = emittedRequestClass(postWithUniqueList('application/json', SchemaType::Number));

    expect(errorsForRawJson($class, '{"xs":[9007199254740993,9007199254740992.0]}'))
        ->toBe([422, ['xs.0' => ['The xs.0 field has a duplicate value.'], 'xs.1' => ['The xs.1 field has a duplicate value.']]]);
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
    ), new RuleSetOptions)->rules;

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

// The message comes from `lang/en/validation.php` like every other, so a project's
// own wording and translations apply to the closed root too.
it('words the refusal of an undeclared key from the validation language lines', function (): void {
    [$status, $body] = sendGeneratedRequest(['email' => 'someone@example.test', 'extra' => 1]);

    expect($status)->toBe(422)
        ->and($body['errors'])->toBe(['extra' => ['The extra field is prohibited.']]);

    app('translator')->addLines(['validation.prohibited' => 'Le champ :attribute est interdit.'], 'en');

    expect(sendGeneratedRequest(['email' => 'someone@example.test', 'extra' => 1])[1]['errors'])
        ->toBe(['extra' => ['Le champ extra est interdit.']]);
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
    ), new RuleSetOptions)->rules;

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
    ), new RuleSetOptions)->rules;

    Route::get('/_generated/filter', fn (Request $request) => response()->json($request->validate($rules)));

    $status = app(HttpKernel::class)->handle(Request::create(
        '/_generated/filter?status=a',
        'GET',
        server: ['HTTP_ACCEPT' => 'application/json'],
    ))->getStatusCode();

    expect($status)->toBe(200);
});

// `form` with `explode: false` sends an object as one string under its own name (`?color=R,100,G,200`, and `?color=` for
// an empty one), so the request can ask for it: it is present, whatever it holds. The default `form` sends one key per
// property and never the name, and a delimited style sends none this package can match, so neither is asked for.
it('asks for a required object query parameter sent as one string', function (
    string $style,
    bool $explode,
    string $query,
    int $status,
): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listColored',
        queryParameters: [new QueryParameter(
            'color',
            new Schema(types: [SchemaType::Object], properties: ['R' => new Schema(types: [SchemaType::Integer])]),
            required: true,
            style: $style,
            explode: $explode,
        )],
    ));

    expect(sendThroughEmitted($class, 'GET', $query)[0])->toBe($status);
})->with([
    'one string, no key' => ['form', false, '', 422],
    'one string, a value' => ['form', false, '?color=R,100', 200],
    'one string, empty' => ['form', false, '?color=', 200],
    'exploded, no key' => ['form', true, '', 200],
    'exploded, a key per property' => ['form', true, '?R=100', 200],
    'space delimited, no key' => ['spaceDelimited', false, '', 200],
]);

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
 * @param  array<string, array<string, string>>  $encoding  the body's `encoding`: media type, part, `contentType`
 */
function statusForUpload(Schema $body, array $files, array $fields = [], array $mediaTypes = ['multipart/form-data'], HttpMethod $method = HttpMethod::Post, array $encoding = [], bool $bodyRequired = true): int
{
    return responseForUpload($body, $files, $fields, $mediaTypes, $method, $encoding, $bodyRequired)->getStatusCode();
}

/**
 * The response behind `statusForUpload()`, for a test that reads which part the 422 names.
 *
 * @param  array<string, mixed>  $files  uploads, by part name
 * @param  array<string, mixed>  $fields  text parts, by name
 * @param  list<string>  $mediaTypes  the media types the body declares
 * @param  array<string, array<string, string>>  $encoding  the body's `encoding`: media type, part, `contentType`
 */
function responseForUpload(Schema $body, array $files, array $fields = [], array $mediaTypes = ['multipart/form-data'], HttpMethod $method = HttpMethod::Post, array $encoding = [], bool $bodyRequired = true): SymfonyResponse
{
    $built = RuleSetBuilder::for(new Operation(
        index: 0,
        method: $method,
        path: PathTemplate::fromString('/_generated/upload'),
        operationId: 'upload',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $body), $bodyRequired, $encoding),
    ), new RuleSetOptions)->rules;

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
    ));
}

/**
 * An object schema of named parts.
 *
 * @param  array<string, Schema>  $parts
 * @param  list<string>  $required
 */
function objectOfParts(array $parts, array $required = []): Schema
{
    return new Schema(types: [SchemaType::Object], properties: $parts, required: $required);
}

/**
 * The `$_FILES` entry PHP writes for a file input left empty: no name, no temporary file, `UPLOAD_ERR_NO_FILE`.
 *
 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
 */
function noFileEntry(): array
{
    return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
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

// `present_with` makes the file part's key mandatory once another part is sent, and
// leaves the type to `file`, which is not implicit: an empty slot is a key holding
// `null`, so the key is there and only `file` can say it is not an upload.
it('refuses an empty upload slot for a required file part of an optional body once another part is sent', function (
    array $files,
    array $fields,
): void {
    $body = objectOfParts(['avatar' => filePart(), 'caption' => new Schema(types: [SchemaType::String])], ['avatar', 'caption']);

    $response = responseForUpload($body, $files, $fields, bodyRequired: false);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors']))->toContain('avatar');
})->with([
    // The browser sends `avatar=` as text when the file input is left empty and the form is not posted as a file.
    'an empty text part' => [[], ['avatar' => '', 'caption' => 'a caption']],
    // The raw `$_FILES` entry, never an `UploadedFile`: Laravel's `uploaded` guard would refuse that one for
    // another reason, and the row would pass without `file`.
    'a no-file upload' => [['avatar' => noFileEntry()], ['caption' => 'a caption']],
    'the empty slot alone' => [['avatar' => noFileEntry()], []],
]);

it('accepts an optional multipart body sent with no part at all', function (): void {
    $body = objectOfParts(['avatar' => filePart(), 'caption' => new Schema(types: [SchemaType::String])], ['avatar', 'caption']);

    expect(statusForUpload($body, [], bodyRequired: false))->toBe(200);
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

/*
 * Where each field is read from. A generated request validates the body for the
 * keys the body declares and the query string for the keys a `query` parameter
 * declares, so a value supplied in the wrong place is absent, not misread.
 *
 * The class under test is the golden `CreateUserRequest` where it is enough, and
 * a class the emitter writes during the test where the case needs a shape the
 * golden does not have (a required query parameter, a file part, a bodyless GET).
 */

/**
 * Write the request the emitter produces for `$operation`, load it, and name it.
 *
 * Emitted rather than copied, so the class under test is what a build writes. Each call gets a namespace of its own,
 * since a class cannot be declared twice.
 *
 * @param  RuleSetOptions  $options  what the configuration says; the defaults when a test does not care
 * @return class-string<FormRequest>
 */
function emittedRequestClass(Operation $operation, RuleSetOptions $options = new RuleSetOptions): string
{
    static $count = 0;

    $namespace = 'Gcob\\LaraSpecFirst\\Tests\\Emitted\\Run'.++$count;
    $planned = plannedRequests([$operation], options: $options)['requests'][0];
    $path = (string) tempnam(sys_get_temp_dir(), 'lsf-request-');

    file_put_contents($path, (new FormRequestEmitter($namespace, 'tests/emitted.yaml'))->emit($planned)->contents);
    require $path;
    unlink($path);

    // `dto()` names a class the build writes beside the request, so it has to exist.
    foreach (plannedRequests([$operation], options: $options)['plan']->dtos as $dto) {
        file_put_contents($path, (new InputDtoEmitter($namespace, 'tests/emitted.yaml'))->emit($dto)->contents);
        require $path;
        unlink($path);
    }

    /** @var class-string<FormRequest> $class */
    $class = $namespace.'\\Requests\\'.$planned->name->shortName;

    return $class;
}

/**
 * One request through the real kernel to a route that answers with what the generated request validated, files reduced
 * to their class name.
 *
 * @param  class-string<FormRequest>  $class
 * @param  array<array-key, mixed>  $parameters  form fields, for a request that is not JSON (PHP keys an ID as an
 *                                               `int`)
 * @param  array<string, mixed>  $files
 * @param  array<array-key, mixed>|null  $json  the body, for a JSON request
 * @return array{0: int, 1: array<string, mixed>}
 */
function sendThroughEmitted(string $class, string $method, string $query = '', ?array $json = null, array $parameters = [], array $files = []): array
{
    Route::match([$method], '/_generated/emitted', fn () => response()->json(array_map(
        static fn (mixed $value): mixed => $value instanceof UploadedFile ? UploadedFile::class : $value,
        app($class)->validated(),
    )));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/emitted'.$query,
        $method,
        $parameters,
        [],
        $files,
        ['HTTP_ACCEPT' => 'application/json'] + ($json === null ? [] : ['CONTENT_TYPE' => 'application/json']),
        $json === null ? null : (string) json_encode($json),
    ));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), $decoded];
}

/**
 * One request through the real kernel to a route that answers with what `dto()` built, so a body that passes validation
 * and then breaks the DTO shows as a 500.
 *
 * @param  class-string<FormRequest>  $class
 * @param  array<array-key, mixed>  $json  the body, for a JSON request
 * @param  array<string, mixed>  $parameters  form fields, for a request that is not JSON
 * @param  string  $query  the query string, `?page=2`
 * @return array{0: int, 1: array<string, mixed>}
 */
function dtoThroughEmitted(string $class, string $method, array $json = [], array $parameters = [], string $query = ''): array
{
    Route::match([$method], '/_generated/emitted-dto', fn () => response()->json(app($class)->dto()->toArray()));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/emitted-dto'.$query,
        $method,
        $parameters,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'] + ($parameters === [] ? ['CONTENT_TYPE' => 'application/json'] : []),
        $parameters === [] ? (string) json_encode($json) : null,
    ));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), $decoded];
}

/**
 * A `POST` whose body names `name` and whose query string names a required `limit`.
 */
function postWithRequiredQuery(): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: ['name' => new Schema(types: [SchemaType::String])],
            required: ['name'],
        )], true),
        queryParameters: [new QueryParameter('limit', new Schema(types: [SchemaType::Integer]), required: true)],
    );
}

// The probe: the body is `{}`, and the URL carries what the body must. Laravel's
// `all()` merged the two and the contract's required field was satisfied by the
// query string.
it('refuses a required body field that was supplied in the query string', function (): void {
    [$status, $body] = sendGeneratedRequest(new stdClass, '?email=x@example.test&age=31');

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['email']);
});

it('does not read an optional body field from the query string', function (): void {
    expect(sendGeneratedRequest(['email' => 'someone@example.test'], '?age=31'))
        ->toBe([200, ['email' => 'someone@example.test']]);
});

it('hands an integer body field to validated() as an integer', function (): void {
    [$status, $body] = sendGeneratedRequest(['email' => 'someone@example.test', 'age' => 31]);

    expect($status)->toBe(200)
        ->and($body['age'])->toBe(31);
});

/**
 * One form-encoded request, as an HTML form sends it, to the golden's operation: it declares JSON and nothing else.
 *
 * @param  array<string, string>  $fields
 * @return array{0: int, 1: array<string, mixed>}
 */
function sendGeneratedForm(array $fields, string $query = ''): array
{
    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/users'.$query,
        'POST',
        $fields,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'],
    ));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), $decoded];
}

it('reads a form-encoded body', function (): void {
    expect(sendGeneratedForm(['email' => 'someone@example.test', 'nickname' => 'ada']))
        ->toBe([200, ['email' => 'someone@example.test', 'nickname' => 'ada']]);
});

// D40: the contract declares no form, so that client is off-contract, and `rules()` is one array. The rules a JSON
// body gets answer it too, and a form writes every number as text: the same `age=30` that was a 200 is now a 422.
it('refuses a form-encoded number sent to an operation that declares only JSON', function (): void {
    [$status, $body] = sendGeneratedForm(['email' => 'someone@example.test', 'age' => '30']);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['age']);
});

// `nickname` rather than `age`: a string passes whatever the rules are, so the 422 below can only be the query
// string leaking into the body, which `age=30` (a 422 of its own since D40) would hide.
it('refuses a form-encoded body that leaves a required field to the query string', function (): void {
    [$status, $body] = sendGeneratedForm(['nickname' => 'ada'], '?email=x@example.test');

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['email']);
});

// The reverse: a declared `query` parameter is the query string's, and a body key
// of the same name does not satisfy it.
it('refuses a required query parameter that was supplied in the body', function (): void {
    $class = emittedRequestClass(postWithRequiredQuery());

    [$status, $body] = sendThroughEmitted($class, 'POST', json: ['name' => 'Ada', 'limit' => 3]);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['limit']);
});

it('reads a required query parameter from the query string beside a JSON body', function (): void {
    $class = emittedRequestClass(postWithRequiredQuery());

    expect(sendThroughEmitted($class, 'POST', '?limit=3', json: ['name' => 'Ada']))
        ->toBe([200, ['name' => 'Ada', 'limit' => '3']]);
});

// The body wins nothing on a name the query declares: it is not read at all.
it('ignores a body key carrying the name of a declared query parameter', function (): void {
    $class = emittedRequestClass(postWithRequiredQuery());

    expect(sendThroughEmitted($class, 'POST', '?limit=3', json: ['name' => 'Ada', 'limit' => 'not a number']))
        ->toBe([200, ['name' => 'Ada', 'limit' => '3']]);
});

// A property named "2024" is an integer key in PHP, and a spread would renumber
// it. Asked of `validationData()` itself, since only a key a rule names comes back
// from `validated()`.
it('keeps an integer-like body key beside a query parameter', function (): void {
    $class = emittedRequestClass(postWithRequiredQuery());

    $request = $class::create(
        '/_generated/emitted?limit=3',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: (string) json_encode(['2024' => 'a', 'name' => 'Ada']),
    );

    expect($request->validationData())->toBe([2024 => 'a', 'name' => 'Ada', 'limit' => '3']);
});

it('validates a GET from its query parameters alone', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listThings',
        queryParameters: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]), required: true)],
    ));

    expect(sendThroughEmitted($class, 'GET', '?page=2'))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'GET')[0])->toBe(422);
});

// What reaches the validator is the body's uploads, not a copy of them: a file
// rule and `dto()` still see `Illuminate\Http\UploadedFile`.
it('hands an upload to the file rules and to validated() as an uploaded file', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/avatars'),
        operationId: 'uploadAvatar',
        requestBody: new RequestBody(['multipart/form-data' => new Schema(
            types: [SchemaType::Object],
            properties: [
                'title' => new Schema(types: [SchemaType::String]),
                'avatar' => filePart('image/png'),
            ],
            required: ['title', 'avatar'],
        )], true),
        queryParameters: [new QueryParameter('limit', new Schema(types: [SchemaType::Integer]))],
    ));

    $png = UploadedFile::fake()->create('a.png', 1, 'image/png');

    expect(sendThroughEmitted($class, 'POST', '?limit=3', parameters: ['title' => 'Me'], files: ['avatar' => $png]))
        ->toBe([200, ['title' => 'Me', 'avatar' => UploadedFile::class, 'limit' => '3']])
        ->and(sendThroughEmitted($class, 'POST', '?title=Me', files: ['avatar' => $png])[0])->toBe(422);
});

// OpenAPI: `required` on a `readOnly` property applies to responses only, so a
// create that leaves `id` out is valid. Sent, `id` is still judged by its type.
it('accepts a request without a required readOnly property, and still judges it when sent', function (): void {
    $body = new Schema(
        types: [SchemaType::Object],
        properties: [
            'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
            'name' => new Schema(types: [SchemaType::String]),
        ],
        required: ['id', 'name'],
    );

    expect(statusForBuiltRules($body, ['name' => 'Ada']))->toBe(200)
        ->and(statusForBuiltRules($body, ['id' => 7, 'name' => 'Ada']))->toBe(200)
        ->and(statusForBuiltRules($body, ['id' => 'x', 'name' => 'Ada']))->toBe(422)
        ->and(statusForBuiltRules($body, ['id' => 7]))->toBe(422);
});

// The request drops the presence rule of a required `readOnly` property, so a body without
// it reaches `dto()`: the DTO has to read it as absent rather than fail on a missing key.
it('builds the DTO of a body that left out a required readOnly property, at the root and nested', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/posts'),
        operationId: 'createPost',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: [
                'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
                'title' => new Schema(types: [SchemaType::String]),
                'address' => new Schema(
                    types: [SchemaType::Object],
                    properties: [
                        'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
                        'city' => new Schema(types: [SchemaType::String]),
                    ],
                    required: ['id', 'city'],
                ),
            ],
            required: ['id', 'title', 'address'],
        )], true),
    ));

    expect(dtoThroughEmitted($class, 'POST', ['title' => 'A', 'address' => ['city' => 'Paris']]))
        ->toBe([200, ['title' => 'A', 'address' => ['city' => 'Paris']]])
        // `toEqual`: a DTO writes its optional properties after the required ones.
        ->and(dtoThroughEmitted($class, 'POST', ['id' => 7, 'title' => 'A', 'address' => ['id' => 3, 'city' => 'Paris']]))
        ->toEqual([200, ['id' => 7, 'title' => 'A', 'address' => ['id' => 3, 'city' => 'Paris']]])
        ->and(dtoThroughEmitted($class, 'POST', ['title' => 'A', 'address' => []])[0])->toBe(422);
});

// PHP turns `.` and a space in a query name into `_` and `page[size]` into a nested array before Laravel sees the
// request. The rules are keyed by what PHP delivers, so each value is judged, answers on `page.size`, and reaches
// `validated()` nested and as the string a query string sends.
it('validates the query names PHP rewrites under the key PHP delivers', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listRewritten',
        queryParameters: [
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer], minimum: 1.0), required: true),
            new QueryParameter('user.name', new Schema(types: [SchemaType::String]), required: true),
            new QueryParameter('limit', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect(sendThroughEmitted($class, 'GET', '?page[size]=3&user.name=ada&limit=2'))
        ->toEqual([200, ['page' => ['size' => '3'], 'user_name' => 'ada', 'limit' => '2']])
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=abc&user.name=ada')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=0&user.name=ada')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?user.name=ada')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=3')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=3&page[evil]=1&user.name=ada'))
        ->toEqual([200, ['page' => ['size' => '3'], 'user_name' => 'ada']]);
});

/**
 * A `GET` whose only query parameters are a required deepObject `page` that requires `size`, and a required deepObject
 * `filter` that requires nothing.
 */
function listWithDeepObjects(bool $explode): Operation
{
    $integer = new Schema(types: [SchemaType::Integer]);

    return new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listDeepObjects',
        queryParameters: [
            new QueryParameter('page', new Schema(
                types: [SchemaType::Object],
                properties: [
                    'size' => new Schema(types: [SchemaType::Integer], minimum: 1.0, multipleOf: 5.0),
                    'number' => $integer,
                ],
                required: ['size'],
                additionalProperties: false,
            ), required: true, style: 'deepObject', explode: $explode),
            new QueryParameter('filter', new Schema(types: [SchemaType::Object], properties: [
                'created' => new Schema(types: [SchemaType::Object], properties: [
                    'gte' => new Schema(types: [SchemaType::String], format: 'date'),
                ]),
                'active' => new Schema(types: [SchemaType::Boolean]),
                'ids' => new Schema(types: [SchemaType::Array], items: $integer, minItems: 1, maxItems: 3, uniqueItems: true),
            ]), required: true, style: 'deepObject', explode: $explode),
        ],
    );
}

// A deepObject is validated the way PHP parses its brackets, with `explode` saying nothing: each value is judged under the
// key PHP delivers it as, answers on `page.size`, and reaches `validated()` nested and as the string a query string sends.
it('validates a deepObject query parameter under the keys PHP delivers', function (bool $explode, string $query, int $status): void {
    $class = emittedRequestClass(listWithDeepObjects($explode));

    expect(sendThroughEmitted($class, 'GET', $query)[0])->toBe($status);
})->with(function (): Generator {
    $rows = [
        'one value of each' => ['?page[size]=10&page[number]=2', 200],
        'a size under its minimum' => ['?page[size]=0', 422],
        'a size that is not a multiple' => ['?page[size]=7', 422],
        'a size that is no number' => ['?page[size]=abc', 422],
        // `integer` refuses it, and `MultipleOf` never sees it: never a 500, whichever of the two the value reaches.
        'a size of 10^20 written as an exponent' => ['?page[size]=1e99999999999999999999', 422],
        'a size with an exponent no float holds' => ['?page[size]=1e80000000', 422],
        'a required size left out' => ['?page[number]=2', 422],
        'a key the closed object does not declare' => ['?page[size]=10&page[evil]=1', 422],
        'a string where the object belongs' => ['?page=5', 422],
        'no page at all' => ['?filter[active]=true', 422],
        // An empty object can't be written in a query string, so a client with nothing to filter leaves `filter` out.
        'a required filter that requires no property, left out' => ['?page[size]=10', 200],
        'a nested object' => ['?page[size]=10&filter[created][gte]=2020-01-01', 200],
        'a nested property of the wrong format' => ['?page[size]=10&filter[created][gte]=yesterday', 422],
        'a string where a nested object belongs' => ['?page[size]=10&filter[created]=x', 422],
        'a boolean written true' => ['?page[size]=10&filter[active]=true', 200],
        'a boolean written yes' => ['?page[size]=10&filter[active]=yes', 422],
    ];

    foreach ([true, false] as $explode) {
        foreach ($rows as $row => [$query, $status]) {
            yield $row.($explode ? ', exploded' : ', not exploded') => [$explode, $query, $status];
        }
    }
});

it('answers a deepObject query parameter with the values nested and as text', function (): void {
    $class = emittedRequestClass(listWithDeepObjects(false));

    expect(sendThroughEmitted($class, 'GET', '?page[size]=10&page[number]=2&filter[created][gte]=2020-01-01&filter[active]=true'))
        ->toEqual([200, [
            'page' => ['size' => '10', 'number' => '2'],
            'filter' => ['created' => ['gte' => '2020-01-01'], 'active' => 'true'],
        ]]);
});

// Under `any`, a required name the array is held under is still asked for: the parent's `required_array_keys` does it, so
// holding the array's shape does not hold its presence.
it('asks for a required property whose value is a held array', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listRequiredIds',
        queryParameters: [new QueryParameter('filter', new Schema(
            types: [SchemaType::Object],
            properties: [
                'ids' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer])),
                'a' => new Schema(types: [SchemaType::String]),
            ],
            required: ['ids'],
        ), style: 'deepObject', explode: false)],
    ), new RuleSetOptions(DeepObjectArrays::Any));

    expect(sendThroughEmitted($class, 'GET', '?filter[a]=1')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?filter[ids]=1,2')[0])->toBe(200);
});

// A required name the client cannot send (`readOnly`, or one with brackets) gives it nothing to put in the query string, so
// `filter` may be left out. A required name it can send still asks for the parameter.
it('accepts a required deepObject left out when no required property is one a client sends', function (string $query, int $status): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listReadOnlyFilter',
        queryParameters: [
            new QueryParameter('filter', new Schema(
                types: [SchemaType::Object],
                properties: [
                    'id' => new Schema(types: [SchemaType::Integer], readOnly: true),
                    'a[b]' => new Schema(types: [SchemaType::String]),
                    'name' => new Schema(types: [SchemaType::String]),
                ],
                required: ['id', 'a[b]'],
            ), required: true, style: 'deepObject', explode: false),
            new QueryParameter('page', new Schema(
                types: [SchemaType::Object],
                properties: ['size' => new Schema(types: [SchemaType::Integer])],
                required: ['size'],
            ), required: true, style: 'deepObject', explode: false),
        ],
    ));

    expect(sendThroughEmitted($class, 'GET', $query)[0])->toBe($status);
})->with([
    'filter left out' => ['?page[size]=10', 200],
    'filter sent' => ['?page[size]=10&filter[name]=x', 200],
    'page left out, which requires a property a client sends' => ['?filter[name]=x', 422],
]);

// A map of arrays keeps its `meta.*` key, written with no rule: Laravel drops the values of an undeclared key from
// `validated()` otherwise, and the request would answer 200 with the array lost. Declared properties and the map's values
// reach `validated()` together, through the `UndeclaredKeys` guard that spares the declared names.
it('keeps the values of a deepObject map of arrays in the validated data', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listMeta',
        queryParameters: [new QueryParameter('meta', new Schema(
            types: [SchemaType::Object],
            properties: ['id' => new Schema(types: [SchemaType::String])],
            additionalPropertiesSchema: new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String])),
        ), style: 'deepObject', explode: false)],
    ), new RuleSetOptions(DeepObjectArrays::Any));

    expect(sendThroughEmitted($class, 'GET', '?meta[id]=a&meta[n][]=x'))
        ->toEqual([200, ['meta' => ['id' => 'a', 'n' => ['x']]]])
        ->and(sendThroughEmitted($class, 'GET', '?meta[id]=a&meta[n]=x,y'))
        ->toEqual([200, ['meta' => ['id' => 'a', 'n' => 'x,y']]]);
});

/**
 * A `GET` with two deepObject query parameters that hold an array: `filter` (a list of integers, a list of objects and
 * a unique list of booleans) and `meta` (a map of integer lists beside a declared string `id`).
 */
function listWithDeepObjectArrays(bool $explode): Operation
{
    $integer = new Schema(types: [SchemaType::Integer]);

    return new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listDeepObjectArrays',
        queryParameters: [
            new QueryParameter('filter', new Schema(types: [SchemaType::Object], properties: [
                'ids' => new Schema(types: [SchemaType::Array], items: $integer, minItems: 1, maxItems: 3, uniqueItems: true),
                'rows' => new Schema(types: [SchemaType::Array], items: new Schema(
                    types: [SchemaType::Object],
                    properties: ['a' => $integer],
                    required: ['a'],
                )),
                'flags' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Boolean]), uniqueItems: true),
            ]), style: 'deepObject', explode: $explode),
            new QueryParameter('meta', new Schema(
                types: [SchemaType::Object],
                properties: ['id' => new Schema(types: [SchemaType::String])],
                additionalPropertiesSchema: new Schema(types: [SchemaType::Array], items: $integer),
            ), style: 'deepObject', explode: $explode),
        ],
    );
}

// The default reading, `brackets`: an array inside a deepObject is a list a client writes with brackets, so each item is
// judged under its own key and a plain string where the list belongs is refused. `filter[ids][0]=1` is how PHP's own
// `http_build_query()` writes a list.
it('validates an array inside a deepObject written with brackets', function (string $query, int $status, array $errors): void {
    $class = emittedRequestClass(listWithDeepObjectArrays(false));

    [$got, $body] = sendThroughEmitted($class, 'GET', $query);

    expect($got)->toBe($status)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe($errors);
})->with([
    'a list written with empty brackets' => ['?filter[ids][]=1&filter[ids][]=2', 200, []],
    'a list written with indexed brackets, as PHP writes it' => ['?filter[ids][0]=1&filter[ids][1]=2', 200, []],
    'an item that is no integer' => ['?filter[ids][]=1&filter[ids][]=x', 422, ['filter.ids.1']],
    'a plain string' => ['?filter[ids]=1', 422, ['filter.ids']],
    'a comma list' => ['?filter[ids]=1,2', 422, ['filter.ids']],
    'a gap in the indexes' => ['?filter[ids][0]=1&filter[ids][2]=3', 422, ['filter.ids']],
    'a key that is no index' => ['?filter[ids][a]=1', 422, ['filter.ids']],
    'more items than maxItems' => ['?filter[ids][]=1&filter[ids][]=2&filter[ids][]=3&filter[ids][]=4', 422, ['filter.ids']],
    'the same item twice' => ['?filter[ids][]=1&filter[ids][]=1', 422, ['filter.ids.0', 'filter.ids.1']],
    'an empty item' => ['?filter[ids][]=', 422, ['filter.ids.0']],
    'an object of a list of objects that is wrong' => ['?filter[rows][0][a]=1&filter[rows][1][a]=x', 422, ['filter.rows.1.a']],
    'an object of a list of objects that is right' => ['?filter[rows][][a]=1&filter[rows][][a]=2', 200, []],
    // The two spellings of `true` pass `distinct:strict`, which compares the text: a known gap of a unique list of
    // booleans, a form's too, pinned so that its fix shows here.
    'a unique list of booleans written twice, in two spellings' => ['?filter[flags][]=true&filter[flags][]=1', 200, []],
    'a map of lists beside a declared name' => ['?meta[id]=a&meta[n][]=1', 200, []],
    'an item of a map of lists that is no integer' => ['?meta[id]=a&meta[n][]=x', 422, ['meta.n.0']],
    'a map value that is a comma list' => ['?meta[id]=a&meta[n]=1,2', 422, ['meta.n']],
]);

it('answers an array inside a deepObject written with brackets, nested and as text', function (): void {
    $class = emittedRequestClass(listWithDeepObjectArrays(true));

    expect(sendThroughEmitted($class, 'GET', '?filter[ids][]=1&filter[ids][]=2&meta[id]=a&meta[n][]=3'))
        ->toEqual([200, ['filter' => ['ids' => ['1', '2']], 'meta' => ['id' => 'a', 'n' => ['3']]]]);
});

// An empty list has no form with brackets, so a required array that may be empty is accepted left out, as a required
// object that requires nothing a client sends is. `minItems` of 1 keeps it required (next test).
it('accepts a required array that may be empty left out under brackets', function (string $query, int $status, ?array $validated): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listRequiredEmptyable',
        queryParameters: [new QueryParameter('filter', new Schema(
            types: [SchemaType::Object],
            properties: [
                'ids' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer])),
                'a' => new Schema(types: [SchemaType::String]),
            ],
            required: ['ids'],
        ), required: true, style: 'deepObject', explode: false)],
    ));

    [$got, $body] = sendThroughEmitted($class, 'GET', $query);

    expect($got)->toBe($status);

    if ($validated !== null) {
        expect($body)->toEqual($validated);
    }
})->with([
    'the array left out' => ['?filter[a]=1', 200, ['filter' => ['a' => '1']]],
    'the array sent' => ['?filter[ids][]=1', 200, ['filter' => ['ids' => ['1']]]],
    'the whole parameter left out' => ['', 200, []],
    'the array sent as a comma list' => ['?filter[ids]=1,2', 422, null],
]);

// `dependentRequired` asks for a name once its trigger is sent, and a dependent array that may be empty has no bracketed
// form either: `{a: 1, ids: []}` is a request the schema accepts, and `?filter[a]=1` is the only way to write it.
it('accepts a dependent array that may be empty left out under brackets', function (?int $minItems, string $query, int $status): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listDependentEmptyable',
        queryParameters: [new QueryParameter('filter', new Schema(
            types: [SchemaType::Object],
            properties: [
                'a' => new Schema(types: [SchemaType::Integer]),
                'ids' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer]), minItems: $minItems),
            ],
            dependentRequired: ['a' => ['ids']],
        ), style: 'deepObject', explode: false)],
    ));

    expect(sendThroughEmitted($class, 'GET', $query)[0])->toBe($status);
})->with([
    'no minItems, the array left out' => [null, '?filter[a]=1', 200],
    'no minItems, the array sent' => [null, '?filter[a]=1&filter[ids][]=2', 200],
    'no minItems, the trigger left out' => [null, '?filter[ids][]=2', 200],
    'minItems 1, the array left out' => [1, '?filter[a]=1', 422],
    'minItems 1, the array sent' => [1, '?filter[a]=1&filter[ids][]=2', 200],
]);

it('keeps a dependent array that may be empty required in a JSON body', function (): void {
    $class = emittedRequestClass(postWithFormSchema('application/json', new Schema(
        types: [SchemaType::Object],
        properties: ['filter' => new Schema(
            types: [SchemaType::Object],
            properties: [
                'a' => new Schema(types: [SchemaType::Integer]),
                'ids' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer])),
            ],
            dependentRequired: ['a' => ['ids']],
        )],
    )));

    expect(sendThroughEmitted($class, 'POST', json: ['filter' => ['a' => 1]])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', json: ['filter' => ['a' => 1, 'ids' => []]])[0])->toBe(200);
});

it('keeps a required array that cannot be empty required under brackets', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listRequiredIds',
        queryParameters: [new QueryParameter('filter', new Schema(
            types: [SchemaType::Object],
            properties: ['ids' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer]), minItems: 1)],
            required: ['ids'],
        ), required: true, style: 'deepObject', explode: false)],
    ));

    expect(sendThroughEmitted($class, 'GET', '')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?filter[x]=1')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?filter[ids][]=1')[0])->toBe(200);
});

/**
 * A request whose query holds every shape of array inside a deepObject, and whose body, when it has one, is read from
 * the media type given: `filter` (a bounded unique list of integers, a list of objects with a list of strings, an array
 * of arrays, a list of strings of at least two characters, a list of nullable integers) and `meta` (a map of integer
 * lists beside a declared string `id`).
 */
function commaListOperation(string $method = 'GET', ?string $mediaType = null): Operation
{
    $integer = new Schema(types: [SchemaType::Integer]);

    return new Operation(
        index: 0,
        method: HttpMethod::from(strtolower($method)),
        path: PathTemplate::fromString('/things'),
        operationId: 'listCommaLists',
        requestBody: $mediaType === null ? null : new RequestBody([$mediaType => new Schema(
            types: [SchemaType::Object],
            properties: ['title' => new Schema(types: [SchemaType::String])],
        )], false),
        queryParameters: [
            new QueryParameter('filter', new Schema(types: [SchemaType::Object], properties: [
                'ids' => new Schema(types: [SchemaType::Array], items: $integer, minItems: 1, maxItems: 3, uniqueItems: true),
                'rows' => new Schema(types: [SchemaType::Array], items: new Schema(
                    types: [SchemaType::Object],
                    properties: [
                        'a' => $integer,
                        'tags' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String])),
                    ],
                    required: ['a'],
                )),
                'm' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Array], items: $integer)),
                'tags' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::String], minLength: 2)),
                'nids' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer, SchemaType::Null])),
            ]), style: 'deepObject', explode: true),
            new QueryParameter('meta', new Schema(
                types: [SchemaType::Object],
                properties: ['id' => new Schema(types: [SchemaType::String])],
                additionalPropertiesSchema: new Schema(types: [SchemaType::Array], items: $integer),
            ), style: 'deepObject', explode: true),
        ],
    );
}

// `comma`: a string where an array belongs is the list a client wrote with commas, split by the request before it
// validates, and then judged by the rules `brackets` has. A list written with brackets still passes. An empty item is
// judged as the empty item of `?filter[ids][]=` is, so `1,,2` is refused for the item and not accepted as `""`.
it('validates an array inside a deepObject written as a comma list', function (string $query, int $status, array $errors): void {
    $class = emittedRequestClass(commaListOperation(), new RuleSetOptions(DeepObjectArrays::Comma));

    [$got, $body] = sendThroughEmitted($class, 'GET', $query);

    expect($got)->toBe($status)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe($errors);
})->with([
    'two items' => ['?filter[ids]=1,2', 200, []],
    'one item' => ['?filter[ids]=1', 200, []],
    'an item that is no integer' => ['?filter[ids]=1,x', 422, ['filter.ids.1']],
    'more items than maxItems' => ['?filter[ids]=1,2,3,4', 422, ['filter.ids']],
    'the same item twice' => ['?filter[ids]=1,01', 422, ['filter.ids.0', 'filter.ids.1']],
    'an empty value, below minItems' => ['?filter[ids]=', 422, ['filter.ids']],
    'a list written with brackets' => ['?filter[ids][]=1&filter[ids][]=2', 200, []],
    'a list written with brackets, with an item that is wrong' => ['?filter[ids][]=1&filter[ids][]=x', 422, ['filter.ids.1']],
    'a list written with brackets, holding a comma' => ['?filter[ids][0]=1,2', 422, ['filter.ids.0']],
    'the list of an object of a list' => ['?filter[rows][0][a]=1&filter[rows][0][tags]=p,q', 200, []],
    'a list of objects written as a string' => ['?filter[rows]=1,2', 422, ['filter.rows.0', 'filter.rows.1']],
    'a map beside a declared string' => ['?meta[id]=a,b&meta[n]=1,2', 200, []],
    'a value of a map that is wrong' => ['?meta[id]=a&meta[n]=1,x', 422, ['meta.n.1']],
    'an array of arrays, each written as a string' => ['?filter[m][0]=1,2&filter[m][1]=3', 200, []],
    'an array of arrays, written as one string' => ['?filter[m]=1,2', 422, ['filter.m.0', 'filter.m.1']],
    'an empty item in the middle' => ['?filter[ids]=1,,2', 422, ['filter.ids.1']],
    'an empty item at the end' => ['?filter[ids]=1,', 422, ['filter.ids.1']],
    'a comma alone' => ['?filter[ids]=,', 422, ['filter.ids.0', 'filter.ids.1']],
    'an empty item that is a string below minLength' => ['?filter[tags]=ab,,cd', 422, ['filter.tags.1']],
    'an item of a space below minLength' => ['?filter[tags]=ab,%20', 422, ['filter.tags.1']],
    'an empty item where the item may be null' => ['?filter[nids]=1,,2', 200, []],
]);

it('answers an array inside a deepObject written as a comma list, split and as text', function (): void {
    $class = emittedRequestClass(commaListOperation(), new RuleSetOptions(DeepObjectArrays::Comma));

    expect(sendThroughEmitted($class, 'GET', '?filter[ids]=1,2&filter[m][0]=3,4&filter[m][1]=5&filter[nids]=6,,7&meta[id]=a,b&meta[n]=8,9'))
        ->toEqual([200, [
            'filter' => ['ids' => ['1', '2'], 'm' => [['3', '4'], ['5']], 'nids' => ['6', null, '7']],
            'meta' => ['id' => 'a,b', 'n' => ['8', '9']],
        ]]);
});

// An empty list is how a comma list writes it (`?filter[ids]=`), and the rules judge `[]` as they judge it from a body.
it('reads an empty comma list as an empty list', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listCommaEmpty',
        queryParameters: [new QueryParameter('filter', new Schema(
            types: [SchemaType::Object],
            properties: ['ids' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer]))],
        ), style: 'deepObject', explode: true)],
    ), new RuleSetOptions(DeepObjectArrays::Comma));

    expect(sendThroughEmitted($class, 'GET', '?filter[ids]='))->toEqual([200, ['filter' => ['ids' => []]]]);
});

// `prepareForValidation()` writes the query string's own bag, so the request the controller type-hints holds the list,
// and the application's `request()` keeps the string as sent.
it('hands the controller the split list and keeps the string as sent in the application request', function (): void {
    $class = emittedRequestClass(commaListOperation(), new RuleSetOptions(DeepObjectArrays::Comma));

    Route::get('/_generated/emitted-query', fn () => response()->json([
        'typed' => app($class)->query('filter'),
        'sent' => request()->query('filter'),
    ]));

    $response = app(HttpKernel::class)->handle(Request::create('/_generated/emitted-query?filter[ids]=1,2', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));

    expect(json_decode((string) $response->getContent(), true))
        ->toBe(['typed' => ['ids' => ['1', '2']], 'sent' => ['ids' => '1,2']]);
});

// The split is written into the query bag whatever the verb and the body: `merge()` would write the body's on a `POST`,
// and the string the rules read would stay a string.
it('validates a comma list in the query string of a request that has a body', function (?string $mediaType, ?array $json, array $parameters): void {
    $class = emittedRequestClass(commaListOperation('POST', $mediaType), new RuleSetOptions(DeepObjectArrays::Comma));

    expect(sendThroughEmitted($class, 'POST', '?filter[ids]=1,2', $json, $parameters))
        ->toEqual([200, ['filter' => ['ids' => ['1', '2']]] + ($json ?? $parameters)])
        ->and(sendThroughEmitted($class, 'POST', '?filter[ids]=1,x', $json, $parameters)[0])->toBe(422);
})->with([
    'no body' => [null, null, []],
    'a JSON body' => ['application/json', ['title' => 'a'], []],
    'a form body' => ['application/x-www-form-urlencoded', null, ['title' => 'a']],
]);

// The values of a map written beside declared properties go through the guard that spares the declared names, so a value is
// judged by the map's schema and a declared name keeps its own.
it('judges the values of a deepObject map beside a declared property', function (string $query, int $status, ?string $field): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listMetaNumbers',
        queryParameters: [new QueryParameter('meta', new Schema(
            types: [SchemaType::Object],
            properties: ['id' => new Schema(types: [SchemaType::String])],
            additionalPropertiesSchema: new Schema(types: [SchemaType::Integer]),
        ), style: 'deepObject', explode: false)],
    ));

    [$got, $body] = sendThroughEmitted($class, 'GET', $query);

    expect($got)->toBe($status)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe($field === null ? [] : [$field]);
})->with([
    'a declared name that is no integer' => ['?meta[id]=a&meta[n]=5', 200, null],
    'a declared name alone' => ['?meta[id]=abc', 200, null],
    'a value that is not' => ['?meta[id]=a&meta[n]=x', 422, 'meta.n'],
]);

// `?page[size]=3` reaches Laravel as `page => [size => 3]` and `?ids[]=1` as `ids => [1]`, so a rule for the plain name
// meets an array it was never written for. It judges the value only when it is not one, so each nested name is
// accepted sent alone, and the plain name's own rules still hold (next test).
it('accepts a rewritten query name sent alone beside the plain name it overlaps', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listOverlapping',
        queryParameters: [
            new QueryParameter('page', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('ids', new Schema(types: [SchemaType::Integer], minimum: 1.0)),
            new QueryParameter('ids[]', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('limit', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect(sendThroughEmitted($class, 'GET', '?page[size]=3'))->toBe([200, ['page' => ['size' => '3']]])
        ->and(sendThroughEmitted($class, 'GET', '?page=2'))->toBe([200, ['page' => '2']])
        // Nothing below `ids` has a rule (`ids[]` has none), so Laravel would keep the array it never checked: it is
        // dropped from `validated()`, and the plain name's own rules still hold for a scalar.
        ->and(sendThroughEmitted($class, 'GET', '?ids[]=-5&ids[]=abc'))->toBe([200, []])
        ->and(sendThroughEmitted($class, 'GET', '?ids=3'))->toBe([200, ['ids' => '3']])
        ->and(sendThroughEmitted($class, 'GET', '?ids=0')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=abc')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?limit=abc')[0])->toBe(422);
});

// A plain name beside the names PHP nests under it keeps its rules, and `validated()` keeps its value: `?page=2` is
// `validated('page') === '2'`, as it was before a nested name was declared beside it.
it('keeps a plain query name enforced beside the names PHP nests under it', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listNested',
        queryParameters: [
            new QueryParameter('page', new Schema(types: [SchemaType::Integer], minimum: 1.0)),
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer], minimum: 1.0)),
            new QueryParameter('filter', new Schema(types: [SchemaType::String])),
            new QueryParameter('filter[a]', new Schema(types: [SchemaType::String])),
            new QueryParameter('filter[a][b]', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect(sendThroughEmitted($class, 'GET', '?page=2'))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'GET', '?page=abc')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page=0')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=3'))->toBe([200, ['page' => ['size' => '3']]])
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=0')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=3&page[evil]=1'))->toBe([200, ['page' => ['size' => '3']]])
        ->and(sendThroughEmitted($class, 'GET', '?filter=x'))->toBe([200, ['filter' => 'x']])
        ->and(sendThroughEmitted($class, 'GET', '?filter[a]=x'))->toBe([200, ['filter' => ['a' => 'x']]])
        ->and(sendThroughEmitted($class, 'GET', '?filter[a][b]=3'))->toBe([200, ['filter' => ['a' => ['b' => '3']]]])
        ->and(sendThroughEmitted($class, 'GET', '?filter[a][b]=x')[0])->toBe(422);
});

// The array PHP builds for the nested names stands in for the plain name, so a required `page` is not asked for when
// only `page[size]` is sent: the blind spot the guide states. A required `page` is still asked for when nothing is.
it('asks for a required plain query name only when no nested name is sent either', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listRequiredPage',
        queryParameters: [
            new QueryParameter('page', new Schema(types: [SchemaType::Integer]), required: true),
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect(sendThroughEmitted($class, 'GET', '')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'GET', '?page=2'))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'GET', '?page[size]=3'))->toBe([200, ['page' => ['size' => '3']]]);
});

/**
 * A `POST` carrying a body of the given media types, whose properties are names PHP rewrites in a form.
 *
 * @param  list<string>  $mediaTypes
 */
function postWithRewrittenNames(array $mediaTypes, bool $closed = false): Operation
{
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: [
            'user.name' => new Schema(types: [SchemaType::String]),
            'a b' => new Schema(types: [SchemaType::Integer]),
            'page[size]' => new Schema(types: [SchemaType::Integer]),
            'plain' => new Schema(types: [SchemaType::String]),
        ],
        required: ['user.name', 'a b', 'plain'],
        additionalProperties: $closed ? false : null,
    );

    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/rewritten'),
        operationId: 'sendRewritten',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $schema), true),
    );
}

// What a real PHP SAPI hands Laravel for `user.name=ada&a b=1&page[size]=3&plain=x`, form-encoded or
// multipart (measured with `curl -d` and `curl -F` to `php -S`): `user_name`, `a_b` and a nested `page`.
// The rules are keyed by what arrives, so presence and type are judged as for any other field.
it('judges a form body by the names PHP delivers', function (string $mediaType): void {
    $class = emittedRequestClass(postWithRewrittenNames([$mediaType]));
    $sent = ['user_name' => 'ada', 'a_b' => '1', 'page' => ['size' => '3'], 'plain' => 'x'];

    expect(sendThroughEmitted($class, 'POST', parameters: $sent)[0])->toBe(200)
        // A required name that never arrived.
        ->and(sendThroughEmitted($class, 'POST', parameters: array_diff_key($sent, ['user_name' => 1]))[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: array_diff_key($sent, ['a_b' => 1]))[0])->toBe(422)
        // The wrong type, for a name PHP rewrote and for one it did not.
        ->and(sendThroughEmitted($class, 'POST', parameters: ['user_name' => ['ada']] + $sent)[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['a_b' => 'x'] + $sent)[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['plain' => ['x']] + $sent)[0])->toBe(422)
        // `page[size]` is an integer, reached through the nested array PHP built.
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => 'big']] + $sent)[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: array_diff_key($sent, ['page' => 1]))[0])->toBe(200);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

it('keeps a dotted name of a JSON body as the contract writes it', function (): void {
    $class = emittedRequestClass(postWithRewrittenNames(['application/json']));

    expect(sendThroughEmitted($class, 'POST', json: ['user.name' => 'ada', 'a b' => 1, 'plain' => 'x'])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', json: ['a b' => 1, 'plain' => 'x'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', json: ['user.name' => 5, 'a b' => 1, 'plain' => 'x'])[0])->toBe(422);
});

it('closes a form body to the names PHP delivers', function (): void {
    $class = emittedRequestClass(postWithRewrittenNames(['multipart/form-data'], closed: true));
    $sent = ['user_name' => 'ada', 'a_b' => '1', 'plain' => 'x'];

    expect(sendThroughEmitted($class, 'POST', parameters: $sent)[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['extra' => 1] + $sent)[0])->toBe(422);
});

// The closed root accepts the key PHP gives a name, which for `page[size]` is `page`: the head is
// closed on its own rule to the keys the contract declares below it.
it('refuses what lands under a bracketed name\'s head in a closed form body', function (string $mediaType): void {
    $class = emittedRequestClass(postWithRewrittenNames([$mediaType], closed: true));
    $sent = ['user_name' => 'ada', 'a_b' => '1', 'plain' => 'x'];

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3']] + $sent)[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3', 'evil' => '1']] + $sent)[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['evil' => '1']] + $sent)[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => 'abc'] + $sent)[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// A valid value reaches the DTO under the name the contract writes, which the validated payload
// holds under the name PHP delivered.
it('builds the DTO of a form body from the names PHP delivered', function (string $mediaType): void {
    $class = emittedRequestClass(postWithRewrittenNames([$mediaType]));

    expect(dtoThroughEmitted($class, 'POST', parameters: ['user_name' => 'ada', 'a_b' => '1', 'page' => ['size' => '3'], 'plain' => 'x']))
        // `toEqual`: a DTO writes its optional properties after the required ones.
        ->toEqual([200, ['user.name' => 'ada', 'a b' => 1, 'plain' => 'x', 'page[size]' => 3]])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['user_name' => 'ada', 'a_b' => '1', 'plain' => 'x']))
        ->toEqual([200, ['user.name' => 'ada', 'a b' => 1, 'plain' => 'x']])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['a_b' => '1', 'plain' => 'x'])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

it('builds the DTO of a JSON body under the names the contract writes', function (): void {
    $class = emittedRequestClass(postWithRewrittenNames(['application/json']));

    expect(dtoThroughEmitted($class, 'POST', ['user.name' => 'ada', 'a b' => 1, 'plain' => 'x']))
        ->toEqual([200, ['user.name' => 'ada', 'a b' => 1, 'plain' => 'x']]);
});

// A bracketed name is delivered below the top-level key another name uses (`page[size]` is `page => [size => ...]`).
// `page` keeps its rules and judges its value only when it is not an array, and `page[size]` keeps its own. The array
// stands in for a required `page`, so `dto()` has it `Optional` and reads `page[size]` out of the array. Two names that
// differ only by a rewritten character (`a b`, `a.b`) never get this far: the DTO refuses their shared property name.
it('judges a name and the name nested under it, and builds the DTO from the nested one', function (string $mediaType): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'plain' => new Schema(types: [SchemaType::String])],
        required: ['page', 'page[size]', 'plain'],
    );
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/collide'),
        operationId: 'sendCollision',
        requestBody: new RequestBody([$mediaType => $schema], true),
    ));

    expect(sendThroughEmitted($class, 'POST', parameters: ['plain' => 'x'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => 'abc'], 'plain' => 'x'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => 'abc', 'plain' => 'x'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3'], 'plain' => 'x']))
        ->toBe([200, ['page' => ['size' => '3'], 'plain' => 'x']])
        // `page[size]` is the one thing the DTO reads, and `page` stays `Optional`: `(int)` of the array would be `1`.
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3'], 'plain' => 'x']))
        ->toBe([200, ['page[size]' => 3, 'plain' => 'x']]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

/**
 * A `POST` with a form body made of the given schema, which is the whole of its input.
 */
function postWithFormSchema(string $mediaType, Schema $schema): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/shaped'),
        operationId: 'sendShaped',
        requestBody: new RequestBody([$mediaType => $schema], true),
    );
}

// The shape check of a form body, through the real kernel. `page=2` is judged as `page`, `page[size]=3` arrives as
// `page => [size => 3]` and is judged as `page.size`, and neither refuses the other's valid value. Payloads are what PHP
// delivers: `Request::create()` does not parse a wire string.
it('judges a form name by its type and its nested names by theirs', function (string $mediaType): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: [
            'page' => new Schema(types: [SchemaType::Integer], minimum: 1.0),
            'page[size]' => new Schema(types: [SchemaType::Integer]),
            'page[*]' => new Schema(types: [SchemaType::Integer]),
            'ids' => new Schema(types: [SchemaType::Integer]),
            'ids[]' => new Schema(types: [SchemaType::Integer]),
        ],
    )));

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => 'abc'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => '0'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3']]))->toBe([200, ['page' => ['size' => '3']]])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => 'abc']])[0])->toBe(422)
        // A `*` name has no rule, so its value is left out of `validated()` with the array that holds it.
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['*' => '5']]))->toBe([200, []])
        // `ids[]` is read as `ids.0`: a form has no append notion.
        ->and(sendThroughEmitted($class, 'POST', parameters: ['ids' => '4']))->toBe([200, ['ids' => '4']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['ids' => ['4']]))->toBe([200, ['ids' => ['4']]])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['ids' => ['abc']])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// A required `page` is asked for when nothing is sent. `page[size]=3` alone satisfies it, since the array stands in for
// it: the blind spot the guide and the ADR state, the one the query side has.
it('asks for a required form name only when no name nested under it is sent either', function (string $mediaType): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer])],
        required: ['page'],
    )));

    expect(sendThroughEmitted($class, 'POST')[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3']]))->toBe([200, ['page' => ['size' => '3']]]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// `dto()` reads the contract's names back, and the array of the names nested under a name must not reach a property cast
// from it: `(int)` of `['size' => '3']` is `1`, so `page` would be a silent wrong `1`. The nested name is read out of the
// array first, and the array is dropped after.
it('builds the DTO of a form name from its own value and its nested names from theirs', function (string $mediaType): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: [
            'page' => new Schema(types: [SchemaType::Integer]),
            'page[size]' => new Schema(types: [SchemaType::Integer]),
            'filter[a]' => new Schema(types: [SchemaType::String]),
            'filter[a][b]' => new Schema(types: [SchemaType::Integer]),
        ],
    )));

    expect(dtoThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => 2]])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3']]))->toBe([200, ['page[size]' => 3]])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => 'x']]))->toBe([200, ['filter[a]' => 'x']])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['b' => '1']]]))->toBe([200, ['filter[a][b]' => 1]]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// Two names under one key keep no rule, and a name below them keeps its own. `validated()` holds `user_name => [x => 5]`,
// and a property typed `string` would be handed the array: a `TypeError` in `dto()` on a valid request. `required` takes
// `user.name` so the DTO does not refuse the two names for one property name.
it('builds the DTO when the key of two names holds only the array of a name below it', function (string $mediaType): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: ['user_name' => new Schema(types: [SchemaType::String]), 'user_name[x]' => new Schema(types: [SchemaType::Integer])],
        required: ['user.name'],
    )));

    expect(sendThroughEmitted($class, 'POST', parameters: ['user_name' => ['x' => 'abc']])[0])->toBe(422)
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['user_name' => ['x' => '5']]))->toBe([200, ['user_name[x]' => 5]]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// An optional `page` beside `page[*]` refused `page[*]=5` with no finding (`page` arrived as an array). A `*` name has no
// rule, so the array is the shape check's to skip. A `page` the contract asks for keeps its rule instead: shaping it
// would let `page[*]=5` alone satisfy it, a 422 turned into a 200. What the rule still refuses, `page=2&page[*]=5` (PHP
// keeps the later name, so `page` arrives as `['*' => '5']`), is stricter than the contract, and the file says so.
it('keeps the rule of a form name whose presence the contract asks for beside a wildcard name', function (string $mediaType, Schema $schema, array $sent): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, $schema));

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => ['*' => '5']] + $sent)[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => '2'] + $sent)[0])->toBe(200);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data'])->with([
    'required' => [
        new Schema(
            types: [SchemaType::Object],
            properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[*]' => new Schema(types: [SchemaType::Integer])],
            required: ['page'],
        ),
        [],
    ],
    'a dependent' => [
        new Schema(
            types: [SchemaType::Object],
            properties: ['page' => new Schema(types: [SchemaType::Integer]), 'other' => new Schema(types: [SchemaType::String]), 'page[*]' => new Schema(types: [SchemaType::Integer])],
            dependentRequired: ['other' => ['page']],
        ),
        ['other' => 'x'],
    ],
]);

// An optional body asks for what it requires once any key is sent, and a shaped name is a key like another: `page=2`
// alone asks for `page[size]` and `other`, which it did not (neither `page` nor `page[size]` had a rule). A required
// `page` is asked for through the others too, and the array of `page[size]` stands in for it.
it('asks for the required names of an optional body once a shaped name or the name below it is sent', function (string $mediaType): void {
    $both = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/optional-shaped'),
        operationId: 'sendOptionalShaped',
        requestBody: new RequestBody([$mediaType => new Schema(
            types: [SchemaType::Object],
            properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'other' => new Schema(types: [SchemaType::String])],
            required: ['page[size]', 'other'],
        )], false),
    ));
    $upper = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/optional-upper'),
        operationId: 'sendOptionalUpper',
        requestBody: new RequestBody([$mediaType => new Schema(
            types: [SchemaType::Object],
            properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'other' => new Schema(types: [SchemaType::String])],
            required: ['page', 'other'],
        )], false),
    ));

    expect(sendThroughEmitted($both, 'POST')[0])->toBe(200)
        ->and(array_keys(sendThroughEmitted($both, 'POST', parameters: ['page' => '2'])[1]['errors'] ?? []))->toBe(['page.size', 'other'])
        ->and(sendThroughEmitted($both, 'POST', parameters: ['page' => ['size' => '3'], 'other' => 'x'])[0])->toBe(200)
        ->and(array_keys(sendThroughEmitted($upper, 'POST', parameters: ['other' => 'x'])[1]['errors'] ?? []))->toBe(['page'])
        ->and(sendThroughEmitted($upper, 'POST', parameters: ['other' => 'x', 'page' => ['size' => '3']])[0])->toBe(200);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// A body that is JSON and a form keeps today's rule for `page` beside `page[*]`, since a `*` name claims its path only in
// a body read only as a form: a JSON client's `{"page": [1]}` is still refused by it, as the contract refuses it.
it('still refuses a JSON array for a name beside a wildcard name in a body that is JSON and a form', function (): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[*]' => new Schema(types: [SchemaType::Integer])],
    );
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/mixed-wildcard'),
        operationId: 'sendMixedWildcard',
        requestBody: new RequestBody(['application/json' => $schema, 'multipart/form-data' => $schema], true),
    ));

    expect(array_keys(sendThroughEmitted($class, 'POST', json: ['page' => [1]])[1]['errors'] ?? []))->toBe(['page'])
        ->and(sendThroughEmitted($class, 'POST', json: ['page' => 1])[0])->toBe(200);
});

// `dependentRequired: {page: [other]}` gives `other` `present_with:page`, and a form that sends only `page[size]` delivers
// an array under `page`, which counts as `page` sent. Stricter than the contract, and the file says so. The other
// direction is the blind spot: `page` as the dependent is satisfied by the array.
it('keeps what a shaped name triggers, and what makes it required', function (string $mediaType): void {
    $properties = [
        'page' => new Schema(types: [SchemaType::Integer]),
        'page[size]' => new Schema(types: [SchemaType::Integer]),
        'other' => new Schema(types: [SchemaType::String]),
    ];
    $triggers = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: $properties,
        dependentRequired: ['page' => ['other']],
    )));
    $depends = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: $properties,
        dependentRequired: ['other' => ['page']],
    )));

    expect(array_keys(sendThroughEmitted($triggers, 'POST', parameters: ['page' => ['size' => '3']])[1]['errors'] ?? []))->toBe(['other'])
        ->and(sendThroughEmitted($triggers, 'POST', parameters: ['page' => ['size' => '3'], 'other' => 'x'])[0])->toBe(200)
        ->and(array_keys(sendThroughEmitted($triggers, 'POST', parameters: ['page' => '2'])[1]['errors'] ?? []))->toBe(['other'])
        ->and(sendThroughEmitted($depends, 'POST', parameters: ['other' => 'x', 'page' => ['size' => '3']])[0])->toBe(200)
        ->and(array_keys(sendThroughEmitted($depends, 'POST', parameters: ['other' => 'x'])[1]['errors'] ?? []))->toBe(['page']);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

/**
 * A `POST` with a form body made of the given properties, every one an integer but the ones named in `$strings`
 * (strings) and `$objects` (objects with no property of their own).
 *
 * @param  list<string>  $names
 * @param  list<string>  $strings
 * @param  list<string>  $objects
 */
function postWithBracketedNames(string $mediaType, array $names, bool $closed, array $strings = [], array $objects = []): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/bracketed'),
        operationId: 'sendBracketed',
        requestBody: new RequestBody([$mediaType => new Schema(
            types: [SchemaType::Object],
            properties: array_map(
                static fn (string $name): Schema => new Schema(types: [match (true) {
                    in_array($name, $strings, true) => SchemaType::String,
                    in_array($name, $objects, true) => SchemaType::Object,
                    default => SchemaType::Integer,
                }]),
                array_combine($names, $names),
            ),
            additionalProperties: $closed ? false : null,
        )], true),
    );
}

// `filter[a]` and `filter[a][b]` are one key to a rule, so `filter[a]` judges its value only when it is not an array and
// the other keeps its own: either side passes, and each is judged. The closed body still refuses what lands beside them,
// at the level `filter[a]` is delivered at too: the array branch of its `Rule::when()` holds the keys below it.
// Payloads are what PHP delivers: `Request::create()` does not parse a wire string.
it('accepts either side of a name below a bracketed name, judges it, and refuses an undeclared key beside it', function (string $mediaType): void {
    $class = emittedRequestClass(postWithBracketedNames($mediaType, ['filter[a]', 'filter[a][b]', 'filter[c]'], closed: true, strings: ['filter[a]']));

    expect(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => 'x']])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['b' => '1']]])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['b' => 'x']]])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['evil' => '1']])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['c' => 'x']])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['evil' => '1']]])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['b' => '1', 'evil' => '1']]])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// A plain `page` above `page[size]`: with `['array']` as the array branch, `page[size]=3&page[evil]=1` passed and only
// `size` stayed in `validated()`. The branch holds the declared key (`array:size`), so the contract's refusal of the
// undeclared one is the request's too, and `page=2` and `page[size]=3` still pass.
it('refuses an undeclared key beside the names below a plain name in a closed form body', function (string $mediaType): void {
    $class = emittedRequestClass(postWithBracketedNames($mediaType, ['page', 'page[size]'], closed: true));

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3']]))->toBe([200, ['page' => ['size' => '3']]])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => 'abc'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => 'x']])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['evil' => '1']])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3', 'evil' => '1']])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// The declared key is written into the generated file, so an apostrophe in it has to be escaped: the class loads, and
// the key is accepted while another one is refused.
it('loads the class of a closed form body that declares a key with an apostrophe below a plain name', function (string $mediaType): void {
    $class = emittedRequestClass(postWithBracketedNames($mediaType, ['page', "page[it's]"], closed: true));

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => ["it's" => '3']])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['evil' => '1']])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// PHP drops what follows a closing bracket, so `a[c]` and `a[c]x` both arrive as `a.c`: the pair builds (the DTO names
// differ) and a form delivers it under one key. A closed body closes the level above (`a` takes only `c`), but the shared
// key has no rule, so what arrives under it is not checked, and `validated()` leaves it out. An open body checks nothing.
// Payloads are what PHP delivers: `Request::create()` does not parse a wire string.
it('closes the level above two names a form delivers under one key, and leaves their key out of validated()', function (
    bool $closed,
    array $parameters,
    int $status,
    array $answer,
): void {
    $class = emittedRequestClass(postWithBracketedNames('application/x-www-form-urlencoded', ['a[c]', 'a[c]x'], $closed));

    [$answered, $body] = sendThroughEmitted($class, 'POST', parameters: $parameters);

    expect($answered)->toBe($status)
        ->and($status === 422 ? array_keys($body['errors']) : $body)->toBe($answer);
})->with([
    'an undeclared key beside them' => [true, ['a' => ['evil' => '1']], 422, ['a']],
    'the shared key, not a number' => [true, ['a' => ['c' => 'abc']], 200, ['a' => []]],
    'an array under the shared key' => [true, ['a' => ['c' => ['evil' => '1']]], 200, ['a' => []]],
    'the shared key, a number' => [true, ['a' => ['c' => '5']], 200, ['a' => []]],
    'open: an undeclared key' => [false, ['a' => ['evil' => '1']], 200, []],
    'open: the shared key' => [false, ['a' => ['c' => 'abc']], 200, []],
]);

// What the payloads above stand for: PHP reads both wire names as `a.c`, and keeps the last one sent.
it('reads a[c] and a[c]x from a form body as the same key', function (): void {
    parse_str('a[c]=1&a[c]x=2', $both);
    parse_str('a[c]x=2', $suffixed);

    expect($both)->toBe(['a' => ['c' => '2']])
        ->and($suffixed)->toBe(['a' => ['c' => '2']]);
});

// Laravel's `validated()` keeps a whole array under a rule that is not a bare `array`, so a closed level (`array:c,d`)
// hands back everything under it. A key of that level no rule judges (the shared key of a pair, a name typed `object`)
// is left out with `exclude`, and a key a ruled name sits below is judged by shape: a value that is not an array is left
// out, and the array holds only the keys declared below it. Names are space-separated, and the second column holds the
// ones typed `object`. Payloads are what PHP delivers.
it('leaves out of validated() a key of a closed form level that no rule judges', function (
    string $names,
    string $objects,
    array $parameters,
    int $status,
    array $answer,
): void {
    $class = emittedRequestClass(postWithBracketedNames(
        'application/x-www-form-urlencoded',
        explode(' ', $names),
        true,
        objects: $objects === '' ? [] : explode(' ', $objects),
    ));

    [$answered, $body] = sendThroughEmitted($class, 'POST', parameters: $parameters);

    expect($answered)->toBe($status)
        ->and($status === 422 ? array_keys($body['errors']) : $body)->toBe($answer);
})->with([
    'the pair beside a ruled name' => ['a[c] a[c]x a[d]', '', ['a' => ['c' => 'abc', 'd' => '5']], 200, ['a' => ['d' => '5']]],
    'the pair beside a ruled name, a bad ruled value' => ['a[c] a[c]x a[d]', '', ['a' => ['d' => 'x']], 422, ['a.d']],
    'a deeper pair' => ['f[a][c] f[a][c]x', '', ['f' => ['a' => ['c' => 'abc']]], 200, ['f' => ['a' => []]]],
    'a deeper pair, an undeclared key beside it' => ['f[a][c] f[a][c]x', '', ['f' => ['a' => ['evil' => '1']]], 422, ['f.a']],
    'a ruled name below the shared key, a scalar' => ['a[c] a[c]x a[c][d]', '', ['a' => ['c' => 'abc']], 200, ['a' => []]],
    'a ruled name below the shared key, a number' => ['a[c] a[c]x a[c][d]', '', ['a' => ['c' => '5']], 200, ['a' => []]],
    'a ruled name below the shared key, the ruled name' => ['a[c] a[c]x a[c][d]', '', ['a' => ['c' => ['d' => '5']]], 200, ['a' => ['c' => ['d' => '5']]]],
    'a ruled name below the shared key, a bad ruled value' => ['a[c] a[c]x a[c][d]', '', ['a' => ['c' => ['d' => 'x']]], 422, ['a.c.d']],
    'a ruled name below the shared key, an undeclared key below it' => ['a[c] a[c]x a[c][d]', '', ['a' => ['c' => ['evil' => '1']]], 422, ['a.c']],
    'a ruled name below the shared key, an undeclared key beside the ruled name' => ['a[c] a[c]x a[c][d]', '', ['a' => ['c' => ['d' => '5', 'evil' => '1']]], 422, ['a.c']],
    'a ruled name and a * name below the shared key' => ['a[c] a[c]x a[c][d] a[c][*]', '', ['a' => ['c' => ['*' => '5']]], 200, ['a' => ['c' => ['*' => '5']]]],
    // TODO (#221): a name typed `object` loses its rules below it, so these two rows answer 200 for what 0.2.0 refused.
    // They pin only what `validated()` holds.
    'a name typed object with a name below it' => ['x[b] x[b][c]', 'x[b]', ['x' => ['b' => ['c' => 'abc', 'evil' => '1']]], 200, ['x' => []]],
    'a name typed object with a name below it, beside a ruled name' => ['x[b] x[b][c] x[d]', 'x[b]', ['x' => ['b' => ['evil' => '1'], 'd' => '5']], 200, ['x' => ['d' => '5']]],
]);

// A declared `a` above names that collide below it (`a[c]` and `a[c]x`, both `a.c`): the level `a` is the one the key check
// has to close, so `a[evil]=1` is refused instead of passing. `a`'s own value is judged as before (`a=5`), and what is sent
// at the shared key is left out of `validated()`. Beside a ruled name (`a[d]`), the contract's `a[c]=abc&a[d]=5` passes and
// keeps only `d`. A level two or three below `a` is refused too, since its key check is written before the rule that drops `a`.
// The `object` rows pin only `validated()` and the level above: the rule loss below the object is #221's. Payloads are
// what PHP delivers: `Request::create()` does not parse a wire string.
it('refuses an undeclared key beside a declared name and the names that collide below it', function (
    string $mediaType,
    string $names,
    string $objects,
    array $parameters,
    int $status,
    array $answer,
): void {
    $class = emittedRequestClass(postWithBracketedNames(
        $mediaType,
        explode(' ', $names),
        true,
        objects: $objects === '' ? [] : explode(' ', $objects),
    ));

    [$answered, $body] = sendThroughEmitted($class, 'POST', parameters: $parameters);

    expect($answered)->toBe($status)
        ->and($status === 422 ? array_keys($body['errors']) : $body)->toBe($answer);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data'])->with([
    'an undeclared key beside a declared name' => ['a a[c] a[c]x', '', ['a' => ['evil' => '1']], 422, ['a']],
    'the declared name' => ['a a[c] a[c]x', '', ['a' => '5'], 200, ['a' => '5']],
    'the declared name, not a number' => ['a a[c] a[c]x', '', ['a' => 'abc'], 422, ['a']],
    'the shared key' => ['a a[c] a[c]x', '', ['a' => ['c' => 'abc']], 200, []],
    'the shared key and an undeclared one' => ['a a[c] a[c]x', '', ['a' => ['c' => '1', 'evil' => '1']], 422, ['a']],
    'beside a ruled name, both sent' => ['a a[c] a[c]x a[d]', '', ['a' => ['c' => 'abc', 'd' => '5']], 200, ['a' => ['d' => '5']]],
    'beside a ruled name, an undeclared key' => ['a a[c] a[c]x a[d]', '', ['a' => ['evil' => '1']], 422, ['a']],
    'above a name typed object, an undeclared key' => ['x x[b] x[b][c]', 'x[b]', ['x' => ['evil' => '1']], 422, ['x']],
    // TODO (#221): the key below `x[b]` is not judged yet, so this row answers 200 for what 0.2.0 refused.
    'above a name typed object, below it' => ['x x[b] x[b][c]', 'x[b]', ['x' => ['b' => ['evil' => '1']]], 200, []],
    'two levels below a declared name, an undeclared key at the first' => ['a a[b][c] a[b][c]x', '', ['a' => ['evil' => '1']], 422, ['a']],
    'two levels below a declared name, an undeclared key at the second' => ['a a[b][c] a[b][c]x', '', ['a' => ['b' => ['evil' => '1']]], 422, ['a.b']],
    'two levels below a declared name, the shared key' => ['a a[b][c] a[b][c]x', '', ['a' => ['b' => ['c' => 'abc']]], 200, []],
    'three levels below a declared name, an undeclared key at the third' => ['a a[b][c][d] a[b][c][d]x', '', ['a' => ['b' => ['c' => ['evil' => '1']]]], 422, ['a.b.c']],
]);

// A required `page` beside `page[x]` (an object) and `page[x][y]`: every name below `page` is withheld, so nothing has a
// rule there, but the array a form builds for them still stands in for `page`. The contract wants a `page` the form
// never sent (a stated gap, as for every required name a form nests other names under), so `validated()` has no `page`
// and `dto()` must not read one: it answers 200 where it threw an `ErrorException` (a 500) before. `main` answered 200
// everywhere, since `page` had no rule at all.
it('lets dto read a required name whose nested names are all withheld, when a form sends only those', function (string $mediaType, bool $closed): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: [
            'page' => new Schema(types: [SchemaType::Integer]),
            'page[x]' => new Schema(types: [SchemaType::Object]),
            'page[x][y]' => new Schema(types: [SchemaType::Integer]),
        ],
        required: ['page'],
        additionalProperties: $closed ? false : null,
    )));

    expect(dtoThroughEmitted($class, 'POST', parameters: ['page' => ['x' => ['y' => '1']]]))->toBe([200, []])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['x' => ['y' => '1']]]))->toBe([200, []])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['page' => ['x' => 'z']]))->toBe([200, []])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => 2]])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['page' => 'abc'])[0])->toBe(422)
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['other' => '1'])[0])->toBe(422);
})->with([
    'form' => 'application/x-www-form-urlencoded',
    'multipart' => 'multipart/form-data',
])->with([
    'open' => false,
    'closed' => true,
]);

// A `*` name below a shaped name: a form delivers `page[*]=5` as `page => ['*' => '5']`, a key the contract declares as
// the property `page[*]`. The key check of the level cannot name it (Laravel hides a `*` in a data key behind a private
// placeholder, so `array:size,*` refuses it), so the level stays open and the file says the key check accepts it: the
// contract's `page[evil]` refusal is not the request's there, as on `main`. Before, the branch refused the contract's
// `page[*]=5` and `page[size]=1&page[*]=5` (a 422 where `main` answered 200).
it('keeps the level a star name delivers below a shaped name open in a closed form body', function (string $mediaType): void {
    $operation = postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: [
            'page' => new Schema(types: [SchemaType::Integer]),
            'page[size]' => new Schema(types: [SchemaType::Integer]),
            'page[*]' => new Schema(types: [SchemaType::Integer]),
        ],
        additionalProperties: false,
    ));
    $class = emittedRequestClass($operation);

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => ['*' => '5']])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3', '*' => '5']])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3']]))->toBe([200, ['page' => ['size' => '3']]])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => 'x']])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => 'abc'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['evil' => '5']])[0])->toBe(200);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// `page` beside only `page[*]` in a closed body: judging `page` by shape would leave its level open (the key check cannot
// name a `*` key), and the contract refuses `page[evil]`, so `page` keeps its plain rule, which refuses every array under
// it, as on `main`. That refuses the contract's `page[*]=5`, which the file says (stricter than the contract). An open
// body shapes it, since it accepts `page[evil]` too.
it('keeps the plain rule of a name beside only a star name in a closed form body, and shapes it in an open one', function (string $mediaType): void {
    $properties = [
        'page' => new Schema(types: [SchemaType::Integer]),
        'page[*]' => new Schema(types: [SchemaType::Integer]),
    ];
    $closed = emittedRequestClass(postWithFormSchema($mediaType, new Schema(types: [SchemaType::Object], properties: $properties, additionalProperties: false)));
    $open = emittedRequestClass(postWithFormSchema($mediaType, new Schema(types: [SchemaType::Object], properties: $properties)));

    expect(sendThroughEmitted($closed, 'POST', parameters: ['page' => ['evil' => '5']])[0])->toBe(422)
        ->and(sendThroughEmitted($closed, 'POST', parameters: ['page' => ['*' => '5']])[0])->toBe(422)
        ->and(sendThroughEmitted($closed, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($closed, 'POST', parameters: ['page' => 'abc'])[0])->toBe(422)
        ->and(sendThroughEmitted($open, 'POST', parameters: ['page' => ['evil' => '5']])[0])->toBe(200)
        ->and(sendThroughEmitted($open, 'POST', parameters: ['page' => ['*' => '5']])[0])->toBe(200)
        ->and(sendThroughEmitted($open, 'POST', parameters: ['page' => 'abc'])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// A shaped name whose nested names are all withheld drops the array too (`exclude`), and its level closes the same way
// in front of the `exclude`: `filter[a]` has no rule below it (`filter[a][b]` is an object, so it and `filter[a][b][c]`
// are withheld), and the keys the contract declares below `filter[a]` are the one (`b`). `filter[a][evil]=1` was a 200
// on this branch before, and on `main`, where nothing in the level had a rule.
it('refuses an undeclared key beside the withheld names below a shaped name in a closed form body', function (string $mediaType): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: [
            'filter[a]' => new Schema(types: [SchemaType::Integer]),
            'filter[a][b]' => new Schema(types: [SchemaType::Object]),
            'filter[a][b][c]' => new Schema(types: [SchemaType::Integer]),
        ],
        additionalProperties: false,
    )));

    expect(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => '1']]))->toBe([200, ['filter' => ['a' => '1']]])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['b' => ['c' => '1']]]])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => 'x']])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['evil' => '1']]])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['evil' => '1']])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// Both names keep a rule, and `formPaths` hands each one's value to `dto()`: `filter[a]=x` reaches it again (0.2.0 did
// too), and `filter[a][b]=1` reaches it as `filter[a][b]`, without the array under `filter[a]`.
it('hands both names below a bracketed name to dto', function (): void {
    $class = emittedRequestClass(postWithBracketedNames('multipart/form-data', ['filter[a]', 'filter[a][b]', 'plain'], closed: false, strings: ['filter[a]', 'plain']));

    expect(dtoThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => 'x'], 'plain' => 'x']))->toBe([200, ['filter[a]' => 'x', 'plain' => 'x']])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['filter' => ['a' => ['b' => '1']], 'plain' => 'x']))->toBe([200, ['filter[a][b]' => 1, 'plain' => 'x']]);
});

// Green before this change: the kernel already refused these, and only the unit test of the rule shape said so.
it('refuses what lands beside a name bracketed more than once in a closed form body', function (string $mediaType): void {
    $class = emittedRequestClass(postWithBracketedNames($mediaType, ['filter[created][gte]', 'filter[x]'], closed: true));

    expect(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['created' => ['gte' => '1'], 'x' => '2']])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['evil' => '1']])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => 'abc'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['filter' => ['created' => ['lt' => '5']]])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// PHP renames a part of `$_FILES` the way it does a field (`my.file` arrives as `my_file`), so a
// required upload under a dotted name is judged by the key it arrives as, and read back by `dto()`.
it('judges and builds an upload whose part name PHP rewrites', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/scans'),
        operationId: 'sendScan',
        requestBody: new RequestBody(['multipart/form-data' => new Schema(
            types: [SchemaType::Object],
            properties: ['my.file' => new Schema(types: [SchemaType::String], isFilePart: true, contentMediaType: 'image/png')],
            required: ['my.file'],
        )], true),
    ));

    Route::post('/_generated/scan', fn () => response()->json(['file' => app($class)->dto()->toArray()['my.file']::class]));

    $send = static fn (array $files): int => app(HttpKernel::class)->handle(Request::create(
        '/_generated/scan',
        'POST',
        [],
        [],
        $files,
        ['HTTP_ACCEPT' => 'application/json'],
    ))->getStatusCode();

    expect($send(['my_file' => UploadedFile::fake()->create('a.png', 1, 'image/png')]))->toBe(200)
        ->and($send([]))->toBe(422);
});

// JSON and a form at once: the JSON name keeps its type rule, a form client's value is not checked
// and not built into the DTO, and a required name is not asked for since that would refuse a form.
it('checks a JSON client on the name the contract writes and lets a form client through', function (): void {
    $class = emittedRequestClass(postWithRewrittenNames(['application/json', 'multipart/form-data']));

    expect(sendThroughEmitted($class, 'POST', json: ['user.name' => 'ada', 'a b' => 1, 'plain' => 'x'])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', json: ['user.name' => 5, 'plain' => 'x'])[0])->toBe(422)
        // The one rule set does not ask for a rewritten required name from a JSON client either, and the file says so.
        ->and(sendThroughEmitted($class, 'POST', json: ['plain' => 'x'])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['user_name' => 'ada', 'a_b' => '1', 'plain' => 'x'])[0])->toBe(200)
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['user_name' => 'ada', 'a_b' => '1', 'plain' => 'x']))
        ->toBe([200, ['plain' => 'x']]);
});

/**
 * A `POST` or `PATCH` whose body is read as JSON and as a form, from one schema.
 */
function postWithJsonAndForm(string $formType, Schema $schema, bool $bodyRequired = true, HttpMethod $method = HttpMethod::Post): Operation
{
    return new Operation(
        index: 0,
        method: $method,
        path: PathTemplate::fromString('/mixed-shaped'),
        operationId: 'sendMixedShaped',
        requestBody: new RequestBody(['application/json' => $schema, $formType => $schema], $bodyRequired),
    );
}

// `page` and `page[size]` in a body that is JSON and a form: `page`'s rules judge every scalar from either client, and
// `page[size]` keeps its JSON rule under the literal key. The array a form builds for `page[size]=3` is `page`'s array
// branch, which is `exclude`. What a JSON client's array under `page` does is the one gap: it passes and is left out of
// `validated()`, since `rules()` does not branch on the media type.
it('judges a plain name by shape in a body that is JSON and a form', function (string $formType): void {
    $class = emittedRequestClass(postWithJsonAndForm($formType, new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'plain' => new Schema(types: [SchemaType::String])],
    )));

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3'], 'plain' => 'x']))->toBe([200, ['plain' => 'x']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => '2']])
        ->and(array_keys(sendThroughEmitted($class, 'POST', parameters: ['page' => 'abc'])[1]['errors'] ?? []))->toBe(['page'])
        ->and(sendThroughEmitted($class, 'POST', json: ['page' => 1, 'page[size]' => 3, 'plain' => 'x']))
        ->toBe([200, ['page' => 1, 'page[size]' => 3, 'plain' => 'x']])
        ->and(array_keys(sendThroughEmitted($class, 'POST', json: ['page' => 'abc'])[1]['errors'] ?? []))->toBe(['page'])
        ->and(array_keys(sendThroughEmitted($class, 'POST', json: ['page[size]' => 'x'])[1]['errors'] ?? []))->toBe(['page[size]'])
        // The stated gap.
        ->and(sendThroughEmitted($class, 'POST', json: ['page' => [1]]))->toBe([200, []]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// A required `page` is asked for from either client when nothing is sent, and by the required-keys check for a JSON `{}`
// when the body may be absent. `page[size]=3` alone satisfies it, since the array stands in for it: the blind spot the
// guide states, which the DTO shows as an `Optional` property.
it('asks for a required shaped name in a body that is JSON and a form, and lets the array of a form client stand in', function (string $formType): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'plain' => new Schema(types: [SchemaType::String])],
        required: ['page'],
    );
    $required = emittedRequestClass(postWithJsonAndForm($formType, $schema));
    $optional = emittedRequestClass(postWithJsonAndForm($formType, $schema, bodyRequired: false));

    expect(sendThroughEmitted($required, 'POST')[0])->toBe(422)
        ->and(array_keys(sendThroughEmitted($required, 'POST', json: ['plain' => 'x'])[1]['errors'] ?? []))->toBe(['page'])
        ->and(sendThroughEmitted($required, 'POST', json: ['page' => 2]))->toBe([200, ['page' => 2]])
        ->and(sendThroughEmitted($required, 'POST', parameters: ['page' => ['size' => '3']]))->toBe([200, []])
        ->and(statusForRawJson($optional, '{}'))->toBe(422)
        ->and(statusForRawJson($optional, '{"page":2}'))->toBe(200);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// `dependentRequired: {page: [other]}` gives `other` `present_with:page`, and the array of a form client's `page[size]`
// counts as `page` sent, so `page[size]=3` alone asks for `other` where the contract never sees `page`: stricter, and the
// file says so. `page=2` alone is refused as the contract refuses it, from either client.
it('keeps what a shaped name triggers in a body that is JSON and a form', function (string $formType): void {
    $class = emittedRequestClass(postWithJsonAndForm($formType, new Schema(
        types: [SchemaType::Object],
        properties: [
            'page' => new Schema(types: [SchemaType::Integer]),
            'page[size]' => new Schema(types: [SchemaType::Integer]),
            'other' => new Schema(types: [SchemaType::String]),
        ],
        dependentRequired: ['page' => ['other']],
    )));

    expect(array_keys(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3']])[1]['errors'] ?? []))->toBe(['other'])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3'], 'other' => 'x'])[0])->toBe(200)
        ->and(array_keys(sendThroughEmitted($class, 'POST', parameters: ['page' => '2'])[1]['errors'] ?? []))->toBe(['other'])
        ->and(array_keys(sendThroughEmitted($class, 'POST', json: ['page' => 2])[1]['errors'] ?? []))->toBe(['other'])
        ->and(sendThroughEmitted($class, 'POST', json: ['page' => 2, 'other' => 'x'])[0])->toBe(200);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// `dto()` of a JSON client's array under `page`: `exclude` leaves it out of the data, so the property is `Optional` and the
// request is a 200, not a `TypeError` or a silent `1`.
it('builds the DTO of a body that is JSON and a form without the array under a shaped name', function (): void {
    $class = emittedRequestClass(postWithJsonAndForm('multipart/form-data', new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer])],
        required: ['page'],
    )));

    expect(dtoForRawJson($class, 'POST', '{"page":[1]}'))->toBe([200, []])
        ->and(dtoForRawJson($class, 'POST', '{"page":2}'))->toBe([200, ['page' => 2]]);
});

// A `PATCH` asks for no presence, so a `required` `page` beside `page[*]` is shaped like an optional one: `page[*]=5` alone
// passes with `page` left out, and so does `page=2&page[*]=5`, which PHP delivers as `page => ['*' => '5']`. `page=2` is
// judged, and `page=abc` refused. The class is built for the `PATCH` operation and driven by a `POST` request, since
// `Request::create()` gives a `PATCH` no form `Content-Type`, and an empty body would be read as absent.
it('shapes a plain form name a PATCH requires beside a wildcard name', function (string $mediaType): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Patch,
        path: PathTemplate::fromString('/patched'),
        operationId: 'patchShaped',
        requestBody: new RequestBody([$mediaType => new Schema(
            types: [SchemaType::Object],
            properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[*]' => new Schema(types: [SchemaType::Integer])],
            required: ['page'],
        )], true),
    ));

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => ['*' => '5']]))->toBe([200, []])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => '2']))->toBe([200, ['page' => '2']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => 'abc'])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// `ids[]` is read as `ids.0`, so `array:0` on the array branch of `ids` would refuse a second value that the body accepted
// before this check and that an open body still accepts. The closed body leaves that level open, as the open one does.
it('accepts the values of a name that is appended to beside its plain name in a closed body', function (string $mediaType, bool $closed): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, new Schema(
        types: [SchemaType::Object],
        properties: ['ids' => new Schema(types: [SchemaType::Integer]), 'ids[]' => new Schema(types: [SchemaType::Integer])],
        additionalProperties: $closed ? false : null,
    )));

    expect(sendThroughEmitted($class, 'POST', parameters: ['ids' => '4']))->toBe([200, ['ids' => '4']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['ids' => ['4', '5']]))->toBe([200, ['ids' => ['4']]])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['ids' => ['abc']])[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data'])->with([false, true]);

// An optional body asks for what it requires once any key is sent, and a form sends `user.name`
// as `user_name`.
it('waits on the key a form delivers when the body is optional', function (): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['plain' => new Schema(types: [SchemaType::String]), 'user.name' => new Schema(types: [SchemaType::String])],
        required: ['plain'],
    );
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/optional'),
        operationId: 'sendOptional',
        requestBody: new RequestBody(['multipart/form-data' => $schema], false),
    ));

    expect(sendThroughEmitted($class, 'POST')[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['user_name' => 'ada'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['user_name' => 'ada', 'plain' => 'x'])[0])->toBe(200);
});

/**
 * A real file on disk, whose type `mimetypes` has to sniff from its content.
 *
 * `UploadedFile::fake()` reports the type it is given and hides exactly this: a fake `.csv` is `text/csv` whatever it
 * holds, and a real one is `text/plain`.
 */
function realUpload(string $content, string $name = 'upload'): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'lsf-upload-');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'application/octet-stream', null, true);
}

// Measured on libmagic 5.41: a CSV, a Markdown file and a YAML file are
// `text/plain`, an XML file is `text/xml`, and `UploadedFile::fake()` says
// otherwise. A `mimetypes:text/csv` rule refused every one of them.
it('accepts a real file whose declared text type its content sniffs as another', function (string $declared, string $content): void {
    expect(statusForUpload(oneField(filePart($declared)), ['field' => realUpload($content)]))->toBe(200);
})->with([
    'a CSV' => ['text/csv', "name,age\nada,36\n"],
    'Markdown' => ['text/markdown', "# Title\n\nSome text.\n"],
    'YAML' => ['application/yaml', "a: 1\nb: 2\n"],
    'XML' => ['application/xml', '<?xml version="1.0"?><a><b>1</b></a>'],
    'JSON' => ['application/json', '{"a":1,"b":[1,2]}'],
]);

// `mimetypes:text/*` matches on the first half of the sniffed type, so it holds for every real text file, unlike a
// named text type.
it('accepts a real text file under a text wildcard and refuses a PNG', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $encoding = ['multipart/form-data' => ['field' => 'text/*']];

    expect(statusForUpload(oneField(filePart()), ['field' => realUpload("name,age\nada,36\n")], encoding: $encoding))->toBe(200)
        ->and(statusForUpload(oneField(filePart()), ['field' => realUpload('<?xml version="1.0"?><a><b>1</b></a>')], encoding: $encoding))->toBe(200)
        ->and(statusForUpload(oneField(filePart()), ['field' => realUpload($png)], encoding: $encoding))->toBe(422);
});

it('still refuses a real file that is not the binary type declared', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    expect(statusForUpload(oneField(filePart('image/png')), ['field' => realUpload($png)]))->toBe(200)
        ->and(statusForUpload(oneField(filePart('image/png')), ['field' => realUpload("name,age\nada,36\n")]))->toBe(422);
});

// `encoding` is what the contract writes beside the part, so it narrows the part the way `contentMediaType` does,
// through the framework and on the real bytes: a GIF is not one of the two types listed.
it('accepts the media types encoding lists', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q==');
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $body = oneField(filePart());
    $encoding = ['multipart/form-data' => ['field' => 'image/png, image/jpeg']];

    expect(statusForUpload($body, ['field' => realUpload($png)], encoding: $encoding))->toBe(200)
        ->and(statusForUpload($body, ['field' => realUpload($jpeg)], encoding: $encoding))->toBe(200)
        ->and(statusForUpload($body, ['field' => realUpload($gif)], encoding: $encoding))->toBe(422);
});

it('validates a body that names properties without saying type: object', function (): void {
    $body = new Schema(properties: ['name' => new Schema(types: [SchemaType::String])], required: ['name']);

    expect(statusForBuiltRules($body, ['name' => 'Ada']))->toBe(200)
        ->and(statusForBuiltRules($body, []))->toBe(422)
        ->and(statusForBuiltRules($body, ['name' => 5]))->toBe(422);
});

// PHP turns the array key "2024" into an integer, and the emitter wrote every rule
// key through a `string` parameter: the build crashed on a legal property name.
it('builds and runs a request for a property named like an integer', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/years'),
        operationId: 'createYear',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            // PHP makes the key "2024" an integer, as it does for the parser's own map.
            properties: ['2024' => new Schema(types: [SchemaType::String]), 'name' => new Schema(types: [SchemaType::String])], // @phpstan-ignore argument.type
            required: ['2024'],
            additionalProperties: false,
        )], true),
    ));

    expect(sendThroughEmitted($class, 'POST', json: ['2024' => 'a', 'name' => 'Ada']))
        ->toBe([200, [2024 => 'a', 'name' => 'Ada']])
        ->and(sendThroughEmitted($class, 'POST', json: ['name' => 'Ada'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', json: ['2024' => 5])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', json: ['2024' => 'a', 'extra' => 1])[0])->toBe(422);
});

// What common generators still emit for an upload in a 3.1 document: `type: string`
// and `format: binary`. It was read as a string, and every upload answered 422.
it('accepts an upload for a part written format: binary in a 3.1 document', function (): void {
    $class = emittedRequestClass(extractFixture('file-part-3.1-binary.yaml')[0]);
    $upload = realUpload("name,age\nada,36\n");

    expect(sendThroughEmitted($class, 'POST', files: ['avatar' => $upload]))->toBe([200, ['avatar' => UploadedFile::class]])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['avatar' => 'not a file'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST')[0])->toBe(422);
});

/**
 * A `POST` carrying one boolean field in a body of the given media types.
 *
 * @param  list<string>  $mediaTypes
 */
function postWithBooleanField(array $mediaTypes): Operation
{
    $schema = new Schema(types: [SchemaType::Object], properties: ['active' => new Schema(types: [SchemaType::Boolean])], required: ['active']);

    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/flags'),
        operationId: 'setFlag',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $schema), true),
    );
}

// A form-encoded and a multipart body carry every value as a string, so `"false"` is
// the contract's `false` there.
it('accepts "true" and "false" for a boolean field of a form or multipart body', function (string $mediaType): void {
    $class = emittedRequestClass(postWithBooleanField([$mediaType]));

    expect(sendThroughEmitted($class, 'POST', parameters: ['active' => 'false']))->toBe([200, ['active' => 'false']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['active' => 'true'])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['active' => '0'])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', parameters: ['active' => 'maybe'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST')[0])->toBe(422);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data']);

// Not Laravel's `boolean`, which takes `1` and `"0"` too: a JSON body has `true` and `false`.
it('refuses everything but true and false for a boolean of a JSON body', function (): void {
    $class = emittedRequestClass(postWithBooleanField(['application/json']));

    expect(sendThroughEmitted($class, 'POST', json: ['active' => false]))->toBe([200, ['active' => false]])
        ->and(sendThroughEmitted($class, 'POST', json: ['active' => 'true'])[0])->toBe(422)
        ->and(sendThroughEmitted($class, 'POST', json: ['active' => 1])[0])->toBe(422);
});

// The case the finding on a `mimetypes` rule names: a real PNG whose name ends in a PHP
// extension is refused, which the contract (a PNG is a PNG) does not ask for.
it('refuses a file named like PHP even when its type is the declared one', function (): void {
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    expect(statusForUpload(oneField(filePart('image/png')), ['field' => realUpload($png, 'avatar.png')]))->toBe(200)
        ->and(statusForUpload(oneField(filePart('image/png')), ['field' => realUpload($png, 'avatar.php')]))->toBe(422);
});

/**
 * A `POST` whose JSON body is an object that asks for nothing, so no rule has a key to fail on.
 */
function postWithOptionalJsonObject(bool $typed = true, bool $closed = false, bool $nullable = false, bool $bodyRequired = true, HttpMethod $method = HttpMethod::Post): Operation
{
    return new Operation(
        index: 0,
        method: $method,
        path: PathTemplate::fromString('/search'),
        operationId: 'searchThings',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: $typed ? [SchemaType::Object, ...($nullable ? [SchemaType::Null] : [])] : [],
            properties: ['q' => new Schema(types: [SchemaType::String])],
            additionalProperties: $closed ? false : null,
        )], $bodyRequired),
    );
}

/**
 * One request through the real kernel carrying `$content` as its JSON body, as sent.
 *
 * @param  class-string<FormRequest>  $class
 */
function statusForRawJson(string $class, string $content): int
{
    Route::post('/_generated/raw-json', fn () => response()->json(app($class)->validated()));

    return app(HttpKernel::class)->handle(Request::create(
        '/_generated/raw-json',
        'POST',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        $content,
    ))->getStatusCode();
}

// Laravel reads a JSON body as `(array) json_decode(...)`, so `"Ada"`, `42` and `[1,2]` become a
// list, and with every property optional and the root open no rule looked at it: 200 where the
// contract says the body is not an object.
it('refuses a JSON body that is not an object', function (bool $typed, bool $closed, string $content): void {
    $class = emittedRequestClass(postWithOptionalJsonObject($typed, $closed));

    expect(statusForRawJson($class, $content))->toBe(422);
})->with([
    'typed, open' => [true, false],
    'untyped, open' => [false, false],
    'typed, closed' => [true, true],
])->with([
    'a string' => ['"Ada"'],
    'a number' => ['42'],
    'a list' => ['[1,2]'],
    'null' => ['null'],
    'malformed' => ['{'],
]);

it('still accepts an object, an empty one included, and an absent optional body', function (bool $typed, bool $closed): void {
    $class = emittedRequestClass(postWithOptionalJsonObject($typed, $closed, bodyRequired: false));

    expect(statusForRawJson($class, '{}'))->toBe(200)
        ->and(statusForRawJson($class, '{"q":"x"}'))->toBe(200)
        ->and(statusForRawJson($class, ''))->toBe(200)
        ->and(statusForRawJson($class, "  \n"))->toBe(200);
})->with([
    'typed, open' => [true, false],
    'untyped, open' => [false, false],
    'typed, closed' => [true, true],
]);

// A required body with no required property: every rule is `sometimes`, so nothing refused an empty
// content, and the contract says the body is required. `{}` is a body, an empty one.
it('refuses an empty content when the body is required', function (bool $typed, bool $closed, string $content): void {
    $class = emittedRequestClass(postWithOptionalJsonObject($typed, $closed));

    expect(statusForRawJson($class, $content))->toBe(422)
        ->and(statusForRawJson($class, '{}'))->toBe(200);
})->with([
    'typed, open' => [true, false],
    'untyped, open' => [false, false],
    'typed, closed' => [true, true],
])->with([
    'nothing' => [''],
    'blanks' => ["  \n"],
]);

/**
 * One request through the real kernel carrying `$content` as its JSON body, as sent, to a route that answers with what
 * `dto()` returned: `null` is `{"dto": null}`.
 *
 * @param  class-string<FormRequest>  $class
 * @return array{0: int, 1: array<array-key, mixed>|null} the status, and the DTO as an array (null for no DTO)
 */
function dtoForRawJson(string $class, string $method, string $content): array
{
    Route::match([$method], '/_generated/raw-json-dto', fn () => response()->json(['dto' => app($class)->dto()?->toArray()]));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/raw-json-dto',
        $method,
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        $content,
    ));

    /** @var array{dto?: array<array-key, mixed>|null} $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), $decoded['dto'] ?? null];
}

/**
 * The operation of one shape of nullable JSON body, for `dto()`.
 */
function nullableJsonBody(string $shape): Operation
{
    if ($shape === 'optional, requires q') {
        return postWithOptionalJsonRequiring(['q'], ['q'], nullable: true);
    }

    return postWithOptionalJsonObject(
        nullable: true,
        bodyRequired: $shape !== 'optional, requires nothing',
        method: $shape === 'PATCH, requires nothing' ? HttpMethod::Patch : HttpMethod::Post,
    );
}

// A `null` body is the payload `null`: the request lets it through, and a DTO of `Optional`s would say "an object with
// nothing in it". An absent optional body is not `null`, it has no content, so it keeps the DTO.
it('returns null from dto() for a null body', function (string $shape, string $method, string $content, int $status, ?array $dto): void {
    $class = emittedRequestClass(nullableJsonBody($shape));

    expect(dtoForRawJson($class, $method, $content))->toBe([$status, $dto]);
})->with([
    'optional, requires nothing: null' => ['optional, requires nothing', 'POST', 'null', 200, null],
    'optional, requires nothing: an object' => ['optional, requires nothing', 'POST', '{"q":"x"}', 200, ['q' => 'x']],
    'optional, requires nothing: {}' => ['optional, requires nothing', 'POST', '{}', 200, []],
    'optional, requires nothing: absent' => ['optional, requires nothing', 'POST', '', 200, []],
    'PATCH, requires nothing: null' => ['PATCH, requires nothing', 'PATCH', 'null', 200, null],
    'PATCH, requires nothing: an object' => ['PATCH, requires nothing', 'PATCH', '{"q":"x"}', 200, ['q' => 'x']],
    'PATCH, requires nothing: {}' => ['PATCH, requires nothing', 'PATCH', '{}', 200, []],
    'required, requires nothing: null' => ['required, requires nothing', 'POST', 'null', 200, null],
    'required, requires nothing: an object' => ['required, requires nothing', 'POST', '{"q":"x"}', 200, ['q' => 'x']],
    'required, requires nothing: {}' => ['required, requires nothing', 'POST', '{}', 200, []],
    'optional, requires q: null' => ['optional, requires q', 'POST', 'null', 200, null],
    'optional, requires q: an object' => ['optional, requires q', 'POST', '{"q":"x"}', 200, ['q' => 'x']],
    'optional, requires q: {}' => ['optional, requires q', 'POST', '{}', 422, null],
    'optional, requires q: absent' => ['optional, requires q', 'POST', '', 200, []],
]);

/**
 * A `POST` whose JSON body is an object of strings that requires some of them, and may be absent.
 *
 * @param  list<string>  $names  the declared properties
 * @param  list<string>  $required
 */
function postWithOptionalJsonRequiring(array $names, array $required, bool $nullable = false): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/reports'),
        operationId: 'reportThing',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object, ...($nullable ? [SchemaType::Null] : [])],
            properties: array_fill_keys($names, new Schema(types: [SchemaType::String])),
            required: $required,
        )], false),
    );
}

/**
 * One request through the real kernel carrying `$content` as its JSON body, as sent, and what it answered.
 *
 * @param  class-string<FormRequest>  $class
 * @return array{0: int, 1: array<string, list<string>>} the status, and the messages by the key they were answered on
 */
function errorsForRawJson(string $class, string $content): array
{
    Route::post('/_generated/raw-json-errors-of', fn () => response()->json(app($class)->validated()));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/raw-json-errors-of',
        'POST',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        $content,
    ));

    /** @var array<string, list<string>> $errors */
    $errors = json_decode((string) $response->getContent(), true)['errors'] ?? [];

    return [$response->getStatusCode(), $errors];
}

// A rule cannot tell `{}` from an absent body, since both leave every key out of the data, but the raw content can:
// `{}` is a body, and the contract refuses a body that lacks a key it requires. An absent body still passes.
it('refuses an empty JSON object on an optional body that requires properties', function (
    array $names,
    array $required,
    bool $nullable,
    string $content,
    int $status,
    array $keys,
): void {
    $class = emittedRequestClass(postWithOptionalJsonRequiring(array_values($names), array_values($required), $nullable));

    [$answered, $errors] = errorsForRawJson($class, $content);

    expect($answered)->toBe($status)
        ->and(array_keys($errors))->toBe($keys)
        ->and(array_map('count', $errors))->each->toBe(1);
})->with([
    'two properties, an empty object' => [['a', 'b'], ['a'], false, '{}', 422, ['a']],
    'two properties, only a key it does not declare' => [['a', 'b'], ['a'], false, '{"zzz":1}', 422, ['a']],
    'two properties, the required one' => [['a', 'b'], ['a'], false, '{"a":"x"}', 200, []],
    'two properties, an empty content' => [['a', 'b'], ['a'], false, '', 200, []],
    'two properties, blanks' => [['a', 'b'], ['a'], false, "  \n", 200, []],
    'one property, an empty object' => [['a'], ['a'], false, '{}', 422, ['a']],
    'one property, an empty content' => [['a'], ['a'], false, '', 200, []],
    'a nullable root, null' => [['a', 'b'], ['a'], true, 'null', 200, []],
    'every required key, an empty object' => [['a', 'b', 'c'], ['a', 'c'], false, '{}', 422, ['a', 'c']],
    'a dotted name, an empty object' => [['user.name', 'b'], ['user.name'], false, '{}', 422, ['user.name']],
]);

// `present_with` already refuses a key when a sibling was sent, so the loop adds its failure only to a key with none:
// one message per key, never two.
it('names a missing required key once when a sibling was sent', function (array $names, array $required, string $content, array $keys): void {
    $class = emittedRequestClass(postWithOptionalJsonRequiring(array_values($names), array_values($required)));

    [$status, $errors] = errorsForRawJson($class, $content);

    expect($status)->toBe(422)
        ->and(array_keys($errors))->toBe($keys)
        ->and(array_map('count', $errors))->each->toBe(1);
})->with([
    'a sibling sent' => [['a', 'b', 'c'], ['a', 'c'], '{"b":"x"}', ['a', 'c']],
    'a dotted name, a sibling sent' => [['user.name', 'b'], ['user.name'], '{"b":"x"}', ['user.name']],
]);

// The `{}` is refused only when Laravel reads the request as JSON, which is a `Content-Type` holding `/json` or `+json`.
// Any other type, or none, reads an absent body and passes: no declared key arrives. A form type is no exception to
// that, although PHP would decode a form `{}` as a field named `{}`: `Request::create()` does not parse the content, so
// that row passes for the same reason and the field is never built.
it('reads an empty object sent as another type as an absent optional body', function (?string $contentType, int $status): void {
    $class = emittedRequestClass(postWithOptionalJsonRequiring(['a', 'b'], ['a']));

    expect(statusForContentType($class, $contentType, '{}'))->toBe($status);
})->with([
    'text/plain' => ['text/plain', 200],
    'no type' => [null, 200],
    'a form type' => ['application/x-www-form-urlencoded', 200],
    'a +json type is JSON' => ['application/vnd.api+json', 422],
    'control: JSON' => ['application/json', 422],
]);

/**
 * One request through the real kernel carrying `$content` with the `Content-Type` as given, or none: what
 * `curl -X POST url` sends, which `Request::create()` would otherwise default to a form's.
 *
 * @param  class-string<FormRequest>  $class
 * @param  array<string, mixed>  $parameters
 * @param  array<string, mixed>  $files
 */
function statusForContentType(string $class, ?string $contentType, string $content = '', array $parameters = [], array $files = []): int
{
    return responseForContentType($class, $contentType, $content, $parameters, $files)->getStatusCode();
}

/**
 * What the emitted class answers to one request, whose `Content-Type` is set or removed as written.
 *
 * @param  array<string, mixed>  $parameters
 * @param  array<string, UploadedFile>  $files
 */
function responseForContentType(string $class, ?string $contentType, string $content = '', array $parameters = [], array $files = []): SymfonyResponse
{
    Route::post('/_generated/typed-content', fn () => response()->json(app($class)->validated()));

    $request = Request::create(
        '/_generated/typed-content',
        'POST',
        $parameters,
        [],
        $files,
        ['HTTP_ACCEPT' => 'application/json'] + ($contentType === null ? [] : ['CONTENT_TYPE' => $contentType]),
        $content,
    );

    if ($contentType === null) {
        $request->headers->remove('Content-Type');
    }

    return app(HttpKernel::class)->handle($request);
}

/**
 * A `POST` whose body is an object read as a form only, every property optional.
 *
 * @param  list<string>  $mediaTypes
 */
function postWithOptionalFormObject(array $mediaTypes, bool $bodyRequired = true): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/search'),
        operationId: 'searchForm',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, new Schema(
            types: [SchemaType::Object],
            properties: ['q' => new Schema(types: [SchemaType::String])],
        )), $bodyRequired),
    );
}

/**
 * A `POST` whose body is a form object requiring one string.
 */
function postWithRequiredFormField(): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/search'),
        operationId: 'searchForm',
        requestBody: new RequestBody(
            [
                'application/x-www-form-urlencoded' => new Schema(
                    types: [SchemaType::Object],
                    properties: ['q' => new Schema(types: [SchemaType::String])],
                    required: ['q'],
                ),
                'multipart/form-data' => new Schema(
                    types: [SchemaType::Object],
                    properties: ['q' => new Schema(types: [SchemaType::String])],
                    required: ['q'],
                ),
            ],
            true,
        ),
    );
}

// `curl -X POST url` and `fetch(url, { method: 'POST' })` send no `Content-Type` when there is no body,
// and a request with none carries no form field either (a multipart always sets one), so it is as absent
// as a body can be.
it('refuses a required body sent with no content type and no content', function (bool $typed, bool $closed): void {
    $class = emittedRequestClass(postWithOptionalJsonObject($typed, $closed));

    expect(statusForContentType($class, null))->toBe(422)
        ->and(statusForContentType($class, null, "  \n"))->toBe(422)
        ->and(statusForContentType($class, null, '{}'))->toBe(200);
})->with([
    'typed, open' => [true, false],
    'untyped, open' => [false, false],
    'typed, closed' => [true, true],
]);

it('accepts a body sent with no content type and no content when the body is optional', function (): void {
    expect(statusForContentType(emittedRequestClass(postWithOptionalJsonObject(bodyRequired: false)), null))->toBe(200)
        ->and(statusForContentType(emittedRequestClass(postWithOptionalFormObject(['multipart/form-data'], false)), null))->toBe(200);
});

// The check is not JSON-specific: a body that is only a form has every property optional here too, so
// no rule refuses it either.
it('refuses a required form body sent with no content type and no content', function (array $mediaTypes): void {
    $class = emittedRequestClass(postWithOptionalFormObject(array_values($mediaTypes)));

    expect(statusForContentType($class, null))->toBe(422)
        ->and(statusForContentType($class, 'application/x-www-form-urlencoded', 'q=x', ['q' => 'x']))->toBe(200);
})->with([
    'urlencoded' => [['application/x-www-form-urlencoded']],
    'multipart' => [['multipart/form-data']],
    'both forms' => [['application/x-www-form-urlencoded', 'multipart/form-data']],
]);

// What stays ambiguous, and the generated file says so: a form's fields need not show in the raw content
// (PHP leaves `php://input` empty for a multipart), so an empty form is not read as an absent body.
it('does not read a form with a content type and no fields as an absent body', function (): void {
    $class = emittedRequestClass(postWithOptionalFormObject(['multipart/form-data']));

    expect(statusForContentType($class, 'application/x-www-form-urlencoded'))->toBe(200)
        ->and(statusForContentType($class, 'multipart/form-data; boundary=x'))->toBe(200);
});

// The premise of leaving the empty-form finding out where a required field exists: that field refuses an empty
// form on its own, so no extra check is owed.
it('refuses an empty form when the body requires a field', function (string $contentType): void {
    $class = emittedRequestClass(postWithRequiredFormField());

    $response = responseForContentType($class, $contentType);
    $errors = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors'] ?? [];

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys((array) $errors))->toBe(['q']);
})->with([
    'urlencoded' => ['application/x-www-form-urlencoded'],
    'multipart' => ['multipart/form-data; boundary=x'],
]);

it('does not refuse an upload sent with no content type', function (): void {
    $class = emittedRequestClass(postWithOptionalFormObject(['multipart/form-data']));

    expect(statusForContentType($class, null, files: ['avatar' => realUpload('x', 'a.txt')]))->toBe(200);
});

// An empty content is an absent body for every type but a form's: a form's fields need not show in the raw
// content (PHP leaves `php://input` empty for a multipart), but nothing else is read from the form bag.
it('refuses an empty content of a type that is not a form when the body is required', function (string $contentType, string $content, bool $json): void {
    $operation = $json ? postWithOptionalJsonObject() : postWithOptionalFormObject(['multipart/form-data']);
    $class = emittedRequestClass($operation);

    $response = responseForContentType($class, $contentType, $content);
    $errors = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors'] ?? [];

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys((array) $errors))->toBe(['body']);
})->with([
    'plain, form body' => ['text/plain', '', false],
    'xml, form body' => ['application/xml', '', false],
    'plain with a charset, form body' => ['text/plain; charset=utf-8', '', false],
    'blanks, form body' => ['text/plain', "  \n", false],
    'plain, JSON body' => ['text/plain', '', true],
    'xml, JSON body' => ['application/xml', '', true],
    'plain with a charset, JSON body' => ['text/plain; charset=utf-8', '', true],
    'blanks, JSON body' => ['text/plain', "  \n", true],
]);

// Off-contract content is not an absent body: the request reads nothing from it, so it is not refused here.
it('does not read a content of a type that is not a form as an absent body when it has content', function (): void {
    $class = emittedRequestClass(postWithOptionalFormObject(['multipart/form-data']));

    expect(statusForContentType($class, 'text/plain', 'hello'))->toBe(200);
});

// Symfony compares a format's type exactly, but PHP parses a form whatever the case of its type, so a form with
// fields and an empty raw content is not an absent body when its type is spelled in capitals.
it('does not read a form whose type is spelled in capitals as an absent body', function (): void {
    $class = emittedRequestClass(postWithOptionalFormObject(['multipart/form-data', 'application/x-www-form-urlencoded']));

    expect(statusForContentType($class, 'Application/X-WWW-Form-Urlencoded', '', ['q' => 'x']))->toBe(200)
        ->and(statusForContentType($class, 'Multipart/Form-Data; boundary=x'))->toBe(200)
        ->and(statusForContentType($class, 'multipart/form-data; boundary=x'))->toBe(200);
});

// A request that is a form has its body read from the form bag, and a form's fields need not show in the raw content.
it('does not read an empty content as an absent body when the request is a form', function (): void {
    $class = emittedRequestClass(postWithOptionalJsonObject());
    Route::post('/_generated/form-to-json', fn () => response()->json(app($class)->validated()));

    $send = static fn (array $parameters): int => app(HttpKernel::class)->handle(Request::create(
        '/_generated/form-to-json',
        'POST',
        $parameters,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'],
    ))->getStatusCode();

    expect($send(['q' => 'x']))->toBe(200);
});

it('accepts a null body when the schema is nullable', function (): void {
    $class = emittedRequestClass(postWithOptionalJsonObject(nullable: true));

    expect(statusForRawJson($class, 'null'))->toBe(200)
        ->and(statusForRawJson($class, '"Ada"'))->toBe(422);
});

it('refuses a non-object root once, not once per check', function (): void {
    $class = emittedRequestClass(postWithOptionalJsonObject(closed: true));
    Route::post('/_generated/raw-json-errors', fn () => response()->json(app($class)->validated()));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/raw-json-errors',
        'POST',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        '[1,2]',
    ));

    expect(array_keys((array) (json_decode((string) $response->getContent(), true)['errors'] ?? [])))->toBe(['body']);
});

it('does not check the root of a body that is only a form', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/search'),
        operationId: 'searchForm',
        requestBody: new RequestBody(['application/x-www-form-urlencoded' => new Schema(
            types: [SchemaType::Object],
            properties: ['q' => new Schema(types: [SchemaType::String])],
        )], true),
    );

    expect(emittedRequestClass($operation))->toBeString()
        ->and((new FormRequestEmitter('App', 'x.yaml'))->emit(plannedRequests([$operation])['requests'][0])->contents)
        ->not->toContain('json_decode');
});

// The read the finding on a boolean query parameter suggests, run against a real request: the
// parameter is optional, and an absent one has to stay `null` rather than become `false`.
it('reads an optional boolean query parameter as null when it is absent, with the suggested read', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listThings',
        queryParameters: [new QueryParameter('notify', new Schema(types: [SchemaType::Boolean]))],
    ));

    Route::get('/_generated/optional-notify', function () use ($class) {
        $request = app($class);

        return response()->json(['notify' => $request->query->has('notify') ? filter_var($request->validated('notify'), FILTER_VALIDATE_BOOL) : null]);
    });

    $notify = static fn (string $query): mixed => json_decode((string) app(HttpKernel::class)->handle(Request::create(
        '/_generated/optional-notify'.$query,
        'GET',
        server: ['HTTP_ACCEPT' => 'application/json'],
    ))->getContent(), true)['notify'];

    expect($notify(''))->toBeNull()
        ->and($notify('?notify=false'))->toBeFalse()
        ->and($notify('?notify=true'))->toBeTrue()
        ->and($notify('?notify=0'))->toBeFalse();
});

// The read the finding suggests for a bracketed boolean, run as written: it names `filter.active`, the key PHP
// delivers, where `validated('filter[active]')` and `query->has('filter[active]')` would find nothing.
it('reads a bracketed boolean query parameter with the read its finding suggests', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listBracketed',
        queryParameters: [new QueryParameter('filter[active]', new Schema(types: [SchemaType::Boolean]))],
    ));

    Route::get('/_generated/bracketed-active', function () use ($class) {
        $request = app($class);

        return response()->json(['active' => Arr::has($request->query->all(), 'filter.active') ? filter_var($request->validated('filter.active'), FILTER_VALIDATE_BOOL) : null]);
    });

    $active = static fn (string $query): mixed => json_decode((string) app(HttpKernel::class)->handle(Request::create(
        '/_generated/bracketed-active'.$query,
        'GET',
        server: ['HTTP_ACCEPT' => 'application/json'],
    ))->getContent(), true)['active'];

    expect($active(''))->toBeNull()
        ->and($active('?filter[other]=1'))->toBeNull()
        ->and($active('?filter[active]=false'))->toBeFalse()
        ->and($active('?filter[active]=true'))->toBeTrue();
});

// The same for a name with a dot inside the brackets, which no dotted key can spell: the read goes by the steps, and
// finds what `validated()` holds under the dot.
it('reads a boolean query parameter with a dot inside its brackets with the read its finding suggests', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listDotted',
        queryParameters: [new QueryParameter('filter[a.b]', new Schema(types: [SchemaType::Boolean]))],
    ));

    Route::get('/_generated/dotted-active', function () use ($class) {
        $request = app($class);

        return response()->json(['active' => ($value = data_get($request->validated(), ['filter', 'a.b'])) === null ? null : filter_var($value, FILTER_VALIDATE_BOOL)]);
    });

    $active = static fn (string $query): mixed => json_decode((string) app(HttpKernel::class)->handle(Request::create(
        '/_generated/dotted-active'.$query,
        'GET',
        server: ['HTTP_ACCEPT' => 'application/json'],
    ))->getContent(), true)['active'];

    expect($active(''))->toBeNull()
        ->and($active('?filter[a.b]=false'))->toBeFalse()
        ->and($active('?filter[a.b]=true'))->toBeTrue();
});

// `has()` reads the body as well as the query string, so an open body carrying a key named like the
// parameter would make an unsent one read as `false`.
it('reads an unsent boolean query parameter as null even when the body carries a key of that name', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'sendThings',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: ['q' => new Schema(types: [SchemaType::String])],
        )], false),
        queryParameters: [new QueryParameter('notify', new Schema(types: [SchemaType::Boolean]))],
    ));

    Route::post('/_generated/body-notify', function () use ($class) {
        $request = app($class);

        return response()->json(['notify' => $request->query->has('notify') ? filter_var($request->validated('notify'), FILTER_VALIDATE_BOOL) : null]);
    });

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/body-notify',
        'POST',
        server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        content: '{"notify":true}',
    ));

    expect(json_decode((string) $response->getContent(), true)['notify'])->toBeNull();
});

// A property named `body` answers on the `body` key too, so the root's own failure must not use it
// when the contract has one: the client could not tell which of the two is wrong.
it('answers a required body sent empty on a key no property of the contract has', function (string $property, string $key): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/posts'),
        operationId: 'sendPost',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: [$property => new Schema(types: [SchemaType::String])],
        )], true),
    ));
    Route::post('/_generated/empty-post', fn () => response()->json(app($class)->validated()));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/empty-post',
        'POST',
        server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        content: '',
    ));

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys(json_decode((string) $response->getContent(), true)['errors']))->toBe([$key]);
})->with([
    'no property named like it' => ['title', 'body'],
    'a property named body' => ['body', '_body'],
]);

// A constraint written beside a `$ref` at 3.1 holds together with the schema the `$ref` names, so a value that breaks it
// is refused by the request: a 200 before the keyword was read, a 422 now. `ref-siblings-request.yaml` writes a ceiling
// of three beside `Code` and a floor of one beside `Count`, and a `description` beside the third, which says nothing a
// rule can hold.
it('answers 422 for a value breaking a constraint written beside a $ref', function (array $payload, int $status): void {
    expect(statusForBuiltRules(bodyOfFixture('ref-siblings-request.yaml', 'createThing'), $payload))->toBe($status);
})->with([
    'a code at the ceiling' => [['code' => 'ABC'], 200],
    'a code over the ceiling' => [['code' => 'ABCD'], 422],
    'an amount at the floor' => [['amount' => 1], 200],
    'an amount under the floor' => [['amount' => 0], 422],
    'the type of the referenced schema still holds' => [['amount' => 'one'], 422],
    'a label with only a description beside it' => [['label' => 'a label longer than three'], 200],
]);

/**
 * A `POST` whose body is a required object that requires some of its properties, and allows `null` or not.
 *
 * @param  array<string, SchemaType>  $properties  the declared properties, by type
 * @param  list<string>  $required
 * @param  list<string>  $mediaTypes
 */
function postWithRequiredObject(array $properties, array $required, array $mediaTypes = ['application/json'], bool $closed = false, bool $nullable = true): Operation
{
    $schema = new Schema(
        types: [SchemaType::Object, ...($nullable ? [SchemaType::Null] : [])],
        properties: array_map(static fn (SchemaType $type): Schema => new Schema(types: [$type]), $properties),
        required: $required,
        additionalProperties: $closed ? false : null,
    );

    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/schedule'),
        operationId: 'schedulePost',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $schema), true),
    );
}

/**
 * What one request through the real kernel to a route that answers with `dto()` got back: the status, the keys it was
 * refused on, and the DTO as an array (`null` for a `null` body or a refusal).
 *
 * @param  class-string<FormRequest>  $class
 * @param  array<string, mixed>  $parameters  form fields
 * @return array{0: int, 1: list<string>, 2: array<array-key, mixed>|null}
 */
function dtoForContentType(string $class, ?string $contentType, string $content = '', array $parameters = []): array
{
    Route::post('/_generated/typed-content-dto', fn () => response()->json(['dto' => app($class)->dto()?->toArray()]));

    $request = Request::create(
        '/_generated/typed-content-dto',
        'POST',
        $parameters,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'] + ($contentType === null ? [] : ['CONTENT_TYPE' => $contentType]),
        $content,
    );

    if ($contentType === null) {
        $request->headers->remove('Content-Type');
    }

    $response = app(HttpKernel::class)->handle($request);

    /** @var array{dto?: array<array-key, mixed>|null, errors?: array<string, mixed>} $decoded */
    $decoded = json_decode((string) $response->getContent(), true) ?? [];

    return [$response->getStatusCode(), array_keys($decoded['errors'] ?? []), $decoded['dto'] ?? null];
}

// A JSON `null` reaches the rules as `[]`, so a `present` on a required key refused a body the contract allows. The keys
// now ask through each other, and the request refuses `{}` from the raw content, so only `null` itself passes.
it('accepts null for a nullable body that requires a property', function (
    array $properties,
    array $required,
    array $mediaTypes,
    bool $closed,
    string $content,
): void {
    $class = emittedRequestClass(postWithRequiredObject($properties, array_values($required), array_values($mediaTypes), $closed));

    expect(statusForRawJson($class, $content))->toBe(200);
})->with([
    'one required property, alone' => [['publishAt' => SchemaType::String], ['publishAt'], ['application/json'], false],
    'two required and one optional' => [
        ['a' => SchemaType::String, 'b' => SchemaType::String, 'c' => SchemaType::String], ['a', 'b'], ['application/json'], false,
    ],
    'a required integer, which asked for `required`' => [['age' => SchemaType::Integer], ['age'], ['application/json'], false],
    'a dotted name' => [['user.name' => SchemaType::String], ['user.name'], ['application/json'], false],
    'a closed root' => [['publishAt' => SchemaType::String], ['publishAt'], ['application/json'], true],
    'JSON and a form, requiring a string' => [
        ['publishAt' => SchemaType::String], ['publishAt'], ['application/json', 'application/x-www-form-urlencoded'], false,
    ],
])->with([
    'null' => ['null'],
    'null between blanks' => ["  null\n"],
]);

/**
 * Every payload the twin test sends: a raw JSON content, or the fields of a form, and the status both bodies answer.
 *
 * @return array<string, array{0: list<string>, 1: bool, 2: string|array<string, string>, 3: int}>
 */
function nullableTwinRows(): array
{
    $json = [
        '{}' => ['{}', 422],
        'an optional key only' => ['{"note":"x"}', 422],
        'one required key' => ['{"street":"a"}', 422],
        'an empty integer, which arrives as null' => ['{"street":"a","user.name":"b","age":""}', 422],
        'an undeclared key only' => ['{"x":1}', 422],
        'a whole body' => ['{"street":"a","user.name":"b","age":1}', 200],
        'a string' => ['"Ada"', 422],
        'a list' => ['[1,2]', 422],
        'an empty content' => ['', 422],
        'malformed' => ['{', 422],
    ];
    $forms = [
        'a form with no field' => [[], 422],
        'a form with only an undeclared field' => [['x' => '1'], 422],
        'a form with only the rewritten name' => [['user_name' => 'b'], 422],
        'a whole form' => [['street' => 'a', 'user_name' => 'b', 'age' => '1'], 200],
    ];
    $rows = [];

    foreach ([
        'JSON only, open' => [['application/json'], false],
        'JSON only, closed' => [['application/json'], true],
        'JSON and a form, open' => [['application/json', 'application/x-www-form-urlencoded'], false],
        'JSON and a form, closed' => [['application/json', 'application/x-www-form-urlencoded'], true],
    ] as $shape => [$mediaTypes, $closed]) {
        foreach ($json as $payload => [$content, $status]) {
            $rows[$shape.': '.$payload] = [$mediaTypes, $closed, $content, $status];
        }

        foreach (count($mediaTypes) === 2 ? $forms : [] as $payload => [$fields, $status]) {
            $rows[$shape.': '.$payload] = [$mediaTypes, $closed, $fields, $status];
        }
    }

    return $rows;
}

// Only a JSON `null` changes: everything else is the answer the same body gives when it cannot be `null`, the one the
// contract always asked for. Compared as a set, since the root's own key can come before a missing key now.
it('answers every other body as the same body not nullable does', function (array $mediaTypes, bool $closed, string|array $payload, int $status): void {
    $properties = ['street' => SchemaType::String, 'user.name' => SchemaType::String, 'age' => SchemaType::Integer, 'note' => SchemaType::String];
    $required = ['street', 'user.name', 'age'];
    $answers = [];

    foreach ([true, false] as $nullable) {
        $class = emittedRequestClass(postWithRequiredObject($properties, $required, array_values($mediaTypes), $closed, $nullable));

        if (is_string($payload)) {
            $answers[] = errorsForRawJson($class, $payload);
        } else {
            $response = responseForContentType($class, 'application/x-www-form-urlencoded', parameters: $payload);
            /** @var array<string, list<string>> $errors */
            $errors = json_decode((string) $response->getContent(), true)['errors'] ?? [];
            $answers[] = [$response->getStatusCode(), $errors];
        }
    }

    [[$nullableStatus, $nullableErrors], [$twinStatus, $twinErrors]] = $answers;
    $nullableKeys = array_keys($nullableErrors);
    $twinKeys = array_keys($twinErrors);
    sort($nullableKeys);
    sort($twinKeys);

    expect($twinStatus)->toBe($status)
        ->and($nullableStatus)->toBe($twinStatus)
        ->and($nullableKeys)->toBe($twinKeys)
        // The required-key check skips a key `present_with` already refused, a dotted one included: each key is named
        // once, never twice.
        ->and(array_map('count', $nullableErrors))->each->toBe(1)
        ->and(array_map('count', $twinErrors))->each->toBe(1)
        // The key is the name as written, never the escaped one the rules are keyed by.
        ->and(implode(',', $nullableKeys))->not->toContain('\\');
})->with(fn (): array => nullableTwinRows());

// A body with no declared key is not `null` either, whatever its type says it is: a form with an undeclared field, a
// `text/plain` content, a content with no `Content-Type` at all. They are refused on the required key, as the body that
// cannot be `null` refuses them, and the three answers that pass reach `dto()` without a 500.
it('refuses a body with no declared key on a nullable required body, whatever its type', function (
    ?string $contentType,
    string $content,
    array $parameters,
    int $status,
): void {
    $class = emittedRequestClass(postWithRequiredObject(['publishAt' => SchemaType::String], ['publishAt']));

    [$answered, $keys] = dtoForContentType($class, $contentType, $content, $parameters);

    expect($answered)->toBe($status)
        ->and($keys === [])->toBe($status === 200)
        ->and($status === 200 || in_array('publishAt', $keys, true))->toBeTrue();
})->with([
    'a form with only an undeclared field' => ['application/x-www-form-urlencoded', '', ['x' => '1'], 422],
    'a text/plain content' => ['text/plain', 'hello', [], 422],
    'no Content-Type, with content' => [null, 'x=1', [], 422],
    'a JSON null' => ['application/json', 'null', [], 200],
    'a whole JSON body' => ['application/json', '{"publishAt":"2026-12-01T09:00:00Z"}', [], 200],
    'a whole form' => ['application/x-www-form-urlencoded', '', ['publishAt' => '2026-12-01T09:00:00Z'], 200],
]);

// The body requires a property, so `dto()` builds the full type from a whole body and gives nothing for `null`.
it('returns null from dto() for a null body that requires a property', function (): void {
    $class = emittedRequestClass(postWithRequiredObject(['street' => SchemaType::String, 'age' => SchemaType::Integer], ['street', 'age']));
    $returns = (new ReflectionMethod($class, 'dto'))->getReturnType();

    expect(dtoForRawJson($class, 'POST', 'null'))->toBe([200, null])
        ->and(dtoForRawJson($class, 'POST', '{"street":"a","age":1}'))->toBe([200, ['street' => 'a', 'age' => 1]])
        ->and($returns instanceof ReflectionNamedType ? $returns->getName() : null)->toEndWith('\\SchedulePostInputDto')
        ->and($returns?->allowsNull())->toBeTrue();
});

// Laravel skips every rule that is not implicit on a blank string, and `WholeNumber`, `numeric:strict` and
// `boolean:strict` are not. `required` is, which is how a required integer, number or boolean refused `""` in a project
// that removed `ConvertEmptyStringsToNull`. A nullable required body asks through its siblings instead, so those keys
// also get `filled`, and the answer stays the one the same body gives when it cannot be `null`.
it('refuses an empty string for a required scalar of a nullable body, without ConvertEmptyStringsToNull', function (
    array $properties,
    array $mediaTypes,
    string $content,
    array $keys,
): void {
    ConvertEmptyStringsToNull::skipWhen(static fn (): bool => true);

    try {
        $answers = [];

        foreach ([true, false] as $nullable) {
            $class = emittedRequestClass(postWithRequiredObject($properties, array_keys($properties), array_values($mediaTypes), false, $nullable));
            [$status, $errors] = errorsForRawJson($class, $content);
            $errorKeys = array_keys($errors);
            sort($errorKeys);
            $answers[] = [$status, $errorKeys];
        }
    } finally {
        ConvertEmptyStringsToNull::flushState();
    }

    expect($answers)->toBe([[422, $keys], [422, $keys]]);
})->with([
    'an integer and a boolean beside a string' => [
        ['publishAt' => SchemaType::String, 'retries' => SchemaType::Integer, 'notify' => SchemaType::Boolean],
        ['application/json'],
        '{"publishAt":"2026-12-01T09:00:00Z","retries":"","notify":""}',
        ['notify', 'retries'],
    ],
    'a number' => [
        ['publishAt' => SchemaType::String, 'ratio' => SchemaType::Number],
        ['application/json'],
        '{"publishAt":"2026-12-01T09:00:00Z","ratio":""}',
        ['ratio'],
    ],
    'an integer alone' => [
        ['retries' => SchemaType::Integer],
        ['application/json'],
        '{"retries":""}',
        ['retries'],
    ],
    'JSON and a form' => [
        ['publishAt' => SchemaType::String, 'retries' => SchemaType::Integer, 'notify' => SchemaType::Boolean],
        ['application/json', 'application/x-www-form-urlencoded'],
        '{"publishAt":"2026-12-01T09:00:00Z","retries":"","notify":""}',
        ['notify', 'retries'],
    ],
]);

/**
 * A `POST` whose JSON body is a map or an open object, with the `query` parameters beside it.
 *
 * @param  list<QueryParameter>  $query
 * @param  list<string>  $mediaTypes
 */
function postWithMapBody(Schema $body, array $query = [], array $mediaTypes = ['application/json']): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/hooks'),
        operationId: 'receiveHook',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $body), true),
        queryParameters: $query,
    );
}

/**
 * An answer as `[status, body]` with the body's keys in order: Laravel puts the keys a wildcard reached before the ones
 * a rule names, and no caller reads that order.
 *
 * @param  array{0: int, 1: array<array-key, mixed>|null}  $answer
 * @return array{0: int, 1: array<array-key, mixed>|null}
 */
function withKeysSorted(array $answer): array
{
    if ($answer[1] !== null) {
        ksort($answer[1]);
    }

    return $answer;
}

// The keys of a map are the data: the DTO holds them all under `additionalProperties`, as sent, and `validated()` holds
// them all too (no rule names a key, so a wildcard keeps them). A `null` body is the payload `null`.
it('hands a map body to its DTO, every key as sent', function (string $content, int $status, ?array $dto): void {
    $class = emittedRequestClass(postWithMapBody(mapOf(new Schema(types: [SchemaType::Integer]), nullable: true)));

    expect(withKeysSorted(dtoForRawJson($class, 'POST', $content)))->toBe([$status, $dto]);
})->with([
    'two keys' => ['{"a":1,"b":2}', 200, ['a' => 1, 'b' => 2]],
    'an empty object' => ['{}', 200, []],
    'a value the map refuses' => ['{"a":"x"}', 422, null],
    'null' => ['null', 200, null],
]);

it('keeps every key of a map or open-object body in validated()', function (Schema $body, array $json): void {
    $class = emittedRequestClass(postWithMapBody($body));

    expect(withKeysSorted(sendThroughEmitted($class, 'POST', json: $json)))->toBe([200, $json]);
})->with([
    'a map of integers' => [fn () => mapOf(new Schema(types: [SchemaType::Integer])), ['a' => 1, 'b' => 2]],
    'an open object' => [fn () => new Schema(types: [SchemaType::Object]), ['a' => 1, 'b' => 'x', 'c' => [1, 2]]],
    'an empty value schema' => [fn () => mapOf(new Schema), ['a' => [1]]],
]);

it('carries an open-object body whole', function (): void {
    $class = emittedRequestClass(postWithMapBody(new Schema(types: [SchemaType::Object])));
    $body = ['a' => ['b' => [1]], 'c' => null, 'd' => 'x'];

    expect(withKeysSorted(dtoThroughEmitted($class, 'POST', $body)))->toBe([200, $body]);
});

// Laravel expands `*` over data it has put through `array_merge()`, which renumbers an integer-like top-level key, so the
// wildcard alone never reaches a key such as an ID: it would be missing from `validated()` and its value never judged.
// Every rule under `*` is written again under each such key the request carries, so a map of IDs keeps its keys, judges
// their values and answers on the key itself (`1042`).
it('keeps and judges the integer-like keys of a root map', function (Schema $body, array $json, int $status, array $answer): void {
    $class = emittedRequestClass(postWithMapBody($body));
    [$got, $validated] = sendThroughEmitted($class, 'POST', json: $json);

    expect($got)->toBe($status);

    if ($status === 200) {
        expect(withKeysSorted([200, $validated]))->toBe([200, $answer])
            ->and(withKeysSorted(dtoThroughEmitted($class, 'POST', $json)))->toBe([200, $answer]);

        return;
    }

    expect(array_map(strval(...), array_keys((array) $validated['errors'])))->toBe($answer);
})->with([
    'a map of integers keyed by IDs' => [fn () => mapOf(new Schema(types: [SchemaType::Integer])), ['1042' => 3, '877' => 1], 200, [877 => 1, 1042 => 3]],
    'a map of integers keyed by IDs and names' => [fn () => mapOf(new Schema(types: [SchemaType::Integer])), ['1042' => 3, 'sku-1' => 2, 'a.b' => 1], 200, [1042 => 3, 'a.b' => 1, 'sku-1' => 2]],
    'a refused value under an ID' => [fn () => mapOf(new Schema(types: [SchemaType::Integer])), ['1042' => 'abc'], 422, ['1042']],
    'a refused value under an ID, beside a good one' => [fn () => mapOf(new Schema(types: [SchemaType::Integer])), ['1042' => 3, '877' => 'x'], 422, ['877']],
    'a map of objects' => [fn () => mapOf(new Schema(types: [SchemaType::Object], properties: ['n' => new Schema(types: [SchemaType::String])])), ['1042' => ['n' => 'x']], 200, [1042 => ['n' => 'x']]],
    'a refused property of an object under an ID' => [fn () => mapOf(new Schema(types: [SchemaType::Object], properties: ['n' => new Schema(types: [SchemaType::String])])), ['1042' => ['n' => 5]], 422, ['1042.n']],
    'a map of lists' => [fn () => mapOf(new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer]))), ['1042' => [1, 2]], 200, [1042 => [1, 2]]],
    'a refused element of a list under an ID' => [fn () => mapOf(new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::Integer]))), ['1042' => [1, 'x']], 422, ['1042.1']],
    'an open object' => [fn () => new Schema(types: [SchemaType::Object]), ['2024' => 'x', 'b' => [1]], 200, [2024 => 'x', 'b' => [1]]],
]);

// A form delivers the same integer keys, and a key that is also a `query` parameter is the parameter's: the rules the
// request writes again for an integer-like body key leave it alone.
it('keeps and judges the integer-like keys of a form map, and leaves the query parameters their own rules', function (): void {
    $form = emittedRequestClass(postWithMapBody(mapOf(new Schema(types: [SchemaType::Integer])), mediaTypes: ['application/x-www-form-urlencoded']));
    $beside = emittedRequestClass(postWithMapBody(
        mapOf(new Schema(types: [SchemaType::Integer])),
        [new QueryParameter('limit', new Schema(types: [SchemaType::Integer]))],
    ));

    expect(withKeysSorted(sendThroughEmitted($form, 'POST', parameters: ['1042' => '3', 'b' => '2'])))->toBe([200, [1042 => '3', 'b' => '2']])
        ->and(sendThroughEmitted($form, 'POST', parameters: ['1042' => 'abc'])[0])->toBe(422)
        ->and(withKeysSorted(sendThroughEmitted($beside, 'POST', '?limit=5', ['1042' => 3])))->toBe([200, [1042 => 3, 'limit' => '5']])
        ->and(sendThroughEmitted($beside, 'POST', '?limit=x', ['1042' => 3])[0])->toBe(422);
});

// The generated file keeps its claim that nothing was left unenforced for a body that is `{type: object}`.
it('keeps the unenforced sentence in the file of an open-object body', function (): void {
    $planned = plannedRequests([postWithMapBody(new Schema(types: [SchemaType::Object]))])['requests'][0];
    $file = (new FormRequestEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($planned)->contents;

    // The docblock wraps its lines, so the sentence is compared with the whitespace folded.
    $flat = (string) preg_replace('/\s*\n\s*\*\s*/', ' ', $file);

    expect($flat)->toContain('Nothing was read and left unenforced.');
});

// A field named `address.id` and the `id` of an `address` object write the same sentence, and the gap of the second is
// not hidden by the information of the first: the file does not claim that nothing was left unenforced.
it('does not claim that nothing was left unenforced when a gap shares its sentence with information', function (Schema $nested): void {
    $body = new Schema(types: [SchemaType::Object], properties: [
        'address.id' => new Schema,
        'address' => new Schema(types: [SchemaType::Object], properties: ['id' => $nested]),
    ]);
    $planned = plannedRequests([postWithMapBody($body)])['requests'][0];
    $file = (new FormRequestEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($planned)->contents;
    $flat = (string) preg_replace('/\s*\n\s*\*\s*/', ' ', $file);

    expect($flat)->not->toContain('Nothing was read and left unenforced.');
})->with([
    'an enum of integers' => [new Schema(enum: [1, 2])],
    'a oneOf the package does not read' => [new Schema(ignored: ['oneOf' => '#/paths/~1hooks/post/requestBody'])],
]);

// A `query` parameter's key is read from the query string, so it is in `validated()` and not in the body's keys: the DTO
// holds the body and none of the query string, and a body key of the same name is in neither. The same for a name PHP
// nests (`page[size]`), whose key is `page`.
it('keeps the query parameters out of the DTO of a map body', function (string $parameter, string $query, array $json, array $validated, array $dto): void {
    $class = emittedRequestClass(postWithMapBody(
        mapOf(new Schema(types: [SchemaType::Integer])),
        [new QueryParameter($parameter, new Schema(types: [SchemaType::Integer]))],
    ));

    expect(withKeysSorted(sendThroughEmitted($class, 'POST', $query, $json)))->toBe([200, $validated])
        ->and(withKeysSorted(dtoThroughEmitted($class, 'POST', $json, query: $query)))->toBe([200, $dto]);
})->with([
    'a query parameter, sent' => ['page', '?page=2', ['a' => 1], ['a' => 1, 'page' => '2'], ['a' => 1]],
    'a body key of that name' => ['page', '', ['a' => 1, 'page' => 9], ['a' => 1], ['a' => 1]],
    'a bracketed query name, sent' => ['page[size]', '?page[size]=3', ['a' => 1], ['a' => 1, 'page' => ['size' => '3']], ['a' => 1]],
    'a body key named like the head of a bracketed name' => ['page[size]', '', ['a' => 1, 'page' => 9], ['a' => 1], ['a' => 1]],
]);

/**
 * An object that declares `$properties` and judges every other key by `$value`: what `properties` and
 * `additionalProperties: <schema>` say together.
 *
 * @param  array<string, Schema>  $properties
 */
function mapBeside(array $properties, Schema $value): Schema
{
    return new Schema(types: [SchemaType::Object], properties: $properties, additionalPropertiesSchema: $value);
}

/**
 * A body whose one property, `meta`, is `$map`.
 */
function bodyHolding(Schema $map): Schema
{
    return new Schema(types: [SchemaType::Object], properties: ['meta' => $map]);
}

/**
 * The answer of a request as `[status, errors]`: the messages by attribute when it was refused, and the validated data
 * when it passed.
 *
 * @param  array<array-key, mixed>  $answer  what `sendThroughEmitted()` returned
 * @return array{0: int, 1: array<array-key, mixed>}
 */
function refusedOrValidated(array $answer): array
{
    $body = (array) $answer[1];

    return $answer[0] === 422 ? [422, (array) ($body['errors'] ?? [])] : [$answer[0], keysSortedDeep($body)];
}

/**
 * An array with the keys of every level in order: Laravel puts the keys a wildcard reached before the ones a rule
 * names.
 *
 * @param  array<array-key, mixed>  $data
 * @return array<array-key, mixed>
 */
function keysSortedDeep(array $data): array
{
    ksort($data);

    return array_map(static fn (mixed $value): mixed => is_array($value) ? keysSortedDeep($value) : $value, $data);
}

// Beside declared properties the value schema judges every key the object does not declare, and a declared key keeps
// exactly its own rules: it is never an integer for the value schema's sake, and the message it fails with is its own.
// Run through the class the emitter writes, since `answerForBuiltRules()` skips the guard the emitter puts on the wildcard.
it('validates a map\'s values beside declared properties', function (bool $nested, array $json, int $status, array $expected): void {
    $map = mapBeside(['locale' => new Schema(types: [SchemaType::String], maxLength: 5)], new Schema(types: [SchemaType::Integer]));
    $class = emittedRequestClass(postWithMapBody($nested ? bodyHolding($map) : $map));
    $prefix = $nested ? 'meta.' : '';

    $answer = refusedOrValidated(sendThroughEmitted($class, 'POST', json: $nested ? ['meta' => $json] : $json));
    $expected = $status === 422
        ? array_combine(
            array_map(static fn (string $key): string => $prefix.$key, array_keys($expected)),
            array_map(static fn (string $key, string $message): array => ['The '.$prefix.$key.' field '.$message], array_keys($expected), $expected),
        )
        : ($nested ? ['meta' => $expected] : $expected);

    expect($answer)->toBe([$status, $expected]);
})->with(function (): Generator {
    $rows = [
        'a declared key and an undeclared one that is fine' => [['locale' => 'fr', 'a' => 1], 200, ['a' => 1, 'locale' => 'fr']],
        'only the declared key' => [['locale' => 'fr'], 200, ['locale' => 'fr']],
        // A fraction rather than text: text fails `numeric` too, and says so.
        'an undeclared key the value schema refuses' => [['locale' => 'fr', 'a' => 1.5], 422, ['a' => 'must be an integer.']],
        // Its own message only: `max:5` of a string, never the value schema's `integer`.
        'a declared key its own rules refuse' => [['locale' => 'toolong'], 422, ['locale' => 'must not be greater than 5 characters.']],
    ];

    foreach ([false, true] as $nested) {
        foreach ($rows as $name => [$json, $status, $expected]) {
            yield ($nested ? 'nested, ' : 'at the root, ').$name => [$nested, $json, $status, $expected];
        }
    }
});

// The declared object of a map of objects is never judged by the value schema, and the children of a value are guarded
// too: `n` of an undeclared `b` is judged, and the `n` an object the contract declares may carry is not.
it('judges the objects of a map beside a declared object with a different shape', function (array $meta, int $status, array $errors, array $validated): void {
    $declared = new Schema(types: [SchemaType::Object], properties: ['m' => new Schema(types: [SchemaType::String])]);
    $value = new Schema(types: [SchemaType::Object], properties: ['n' => new Schema(types: [SchemaType::Integer])], required: ['n']);
    $class = emittedRequestClass(postWithMapBody(bodyHolding(mapBeside(['a' => $declared], $value))));

    [$got, $answer] = sendThroughEmitted($class, 'POST', json: ['meta' => $meta]);

    // `validated()` is what a controller hands to `create()`: the declared object keeps the members it declares and
    // nothing the value schema happens to name, since nothing judged them there.
    expect($got)->toBe($status)
        ->and($status === 422 ? array_keys((array) ($answer['errors'] ?? [])) : [])->toBe($errors)
        ->and($status === 200 ? keysSortedDeep($answer) : [])->toBe($validated);
})->with([
    'the declared object lacks the value schema\'s required member' => [['a' => ['m' => 'ok'], 'b' => ['n' => 1]], 200, [], ['meta' => ['a' => ['m' => 'ok'], 'b' => ['n' => 1]]]],
    'the declared object carries a member the value schema would refuse' => [['a' => ['m' => 'ok', 'n' => 'str', 'z' => 'z']], 200, [], ['meta' => ['a' => ['m' => 'ok']]]],
    'an undeclared object missing its required member' => [['a' => ['m' => 'ok'], 'c' => []], 422, ['meta.c'], []],
    'an undeclared object with a wrong member' => [['a' => ['m' => 'ok'], 'b' => ['n' => 'str']], 422, ['meta.b.n'], []],
]);

// The same at the root, beside an open declared object: a member of `owner` named like a property of the value schema
// (`tag`) is no declared member of `owner` and no value of the map, so no rule reaches it and `validated()` leaves it out,
// where an undeclared key the map judges (`p`) is kept whole.
it('keeps a member of a declared open object that only the value schema names out of validated()', function (): void {
    $string = new Schema(types: [SchemaType::String]);
    $pet = new Schema(types: [SchemaType::Object], properties: ['name' => $string, 'tag' => $string]);
    $owner = new Schema(types: [SchemaType::Object], properties: ['name' => $string]);
    $class = emittedRequestClass(postWithMapBody(mapBeside(['owner' => $owner], $pet)));

    $answer = refusedOrValidated(sendThroughEmitted($class, 'POST', json: [
        'owner' => ['name' => 'a', 'tag' => ['evil' => 1]],
        'p' => ['name' => 'b', 'tag' => 't'],
    ]));

    expect($answer)->toBe([200, ['owner' => ['name' => 'a'], 'p' => ['name' => 'b', 'tag' => 't']]]);
});

// Laravel renumbers an integer-like top-level key when it expands `*`, so a root that declares properties beside a value
// schema repeats the value rules under each such key, and leaves the declared ones alone: copying over a declared
// `"2024"` would give it the value schema's rules instead of its own.
it('keeps and judges the integer-like keys of a root that declares properties', function (array $json, int $status, array $expected): void {
    $class = emittedRequestClass(postWithMapBody(mapBeside(
        ['locale' => new Schema(types: [SchemaType::String]), '2024' => new Schema(types: [SchemaType::String])], // @phpstan-ignore argument.type
        new Schema(types: [SchemaType::Integer]),
    )));

    $answer = refusedOrValidated(sendThroughEmitted($class, 'POST', json: $json));

    expect($status === 422 ? [422, array_map(strval(...), array_keys($answer[1]))] : $answer)->toBe([$status, $expected]);
})->with([
    'an undeclared ID with a good value' => [['1042' => 3], 200, [1042 => 3]],
    'an undeclared ID the value schema refuses' => [['1042' => 'x'], 422, ['1042']],
    // The declared `2024` is a string: the integer the value schema asks for would refuse it, and 5 is no string.
    'a declared ID sent a value of the wrong type for it' => [['2024' => 5], 422, ['2024']],
    'a declared ID sent its own type' => [['2024' => 'y'], 200, [2024 => 'y']],
    'a declared ID beside an undeclared one' => [['2024' => 'y', '1042' => 3], 200, [1042 => 3, 2024 => 'y']],
]);

// A value that is a map itself keeps its own declared key under an integer-like key too: the copy of the root's rules
// leaves the root's step out of the guard, and the inner step still spares `n`.
it('keeps the inner guard of a value that is a map itself under an integer-like key of the root', function (): void {
    $inner = mapBeside(['n' => new Schema(types: [SchemaType::String])], new Schema(types: [SchemaType::Integer]));
    $class = emittedRequestClass(postWithMapBody(mapBeside(['id' => new Schema(types: [SchemaType::String])], $inner)));

    expect(refusedOrValidated(sendThroughEmitted($class, 'POST', json: ['1042' => ['n' => 'ok', 'k' => 1]])))->toBe([200, [1042 => ['k' => 1, 'n' => 'ok']]])
        ->and(array_keys(refusedOrValidated(sendThroughEmitted($class, 'POST', json: ['1042' => ['n' => 'ok', 'bad' => 'z']]))[1]))->toBe(['1042.bad']);
});

// A declared integer-like name nested in a map is spared by its string: the strict comparison would not match the
// integer PHP makes of the key.
it('spares a nested declared integer-like name and judges the undeclared ones', function (array $meta, int $status, array $errors): void {
    $class = emittedRequestClass(postWithMapBody(bodyHolding(mapBeside(
        ['2024' => new Schema(types: [SchemaType::String])], // @phpstan-ignore argument.type
        new Schema(types: [SchemaType::Integer]),
    ))));

    [$got, $answer] = sendThroughEmitted($class, 'POST', json: ['meta' => $meta]);

    expect($got)->toBe($status)
        ->and($status === 422 ? array_keys((array) ($answer['errors'] ?? [])) : [])->toBe($errors);
})->with([
    'the declared name and an undeclared value that is fine' => [['2024' => 'x', '1042' => 3], 200, []],
    'the declared name and an undeclared value the schema refuses' => [['2024' => 'x', '1042' => 'y'], 422, ['meta.1042']],
]);

// The recursion cut by `x-max-depth` runs through the values: the declared key at the cut passes, and an undeclared key
// below the depth answers on the key `missing` puts there.
it('cuts a recursion through the values beside a declared property at its depth', function (array $json, int $status, array $errors): void {
    $position = '#/components/schemas/Node';
    $node = new Schema(
        name: 'Node',
        types: [SchemaType::Object],
        properties: ['id' => new Schema(types: [SchemaType::String])],
        maxDepth: 1,
        source: $position,
        additionalPropertiesSchema: Schema::recursion($position, 'Node'),
    );
    $class = emittedRequestClass(postWithMapBody($node));

    [$got, $answer] = sendThroughEmitted($class, 'POST', json: $json);

    expect($got)->toBe($status)
        ->and($status === 422 ? array_keys((array) ($answer['errors'] ?? [])) : [])->toBe($errors);
})->with([
    'a level the depth allows, its declared key at the cut' => [['id' => 'x', 'a' => ['id' => 'y']], 200, []],
    'an undeclared value below the depth' => [['id' => 'x', 'a' => ['b' => []]], 422, ['a.b']],
]);

// `validated()` holds the undeclared keys a guarded wildcard judges, beside the declared ones, and `dto()` is the
// declared properties only: the keys of the map are not on it.
it('holds the undeclared keys in validated() and the declared properties on the DTO', function (bool $nested): void {
    $map = mapBeside(['locale' => new Schema(types: [SchemaType::String])], new Schema(types: [SchemaType::Integer]));
    $class = emittedRequestClass(postWithMapBody($nested ? bodyHolding($map) : $map));
    $json = ['locale' => 'fr', 'a' => 1];

    expect(refusedOrValidated(sendThroughEmitted($class, 'POST', json: $nested ? ['meta' => $json] : $json)))
        ->toBe([200, $nested ? ['meta' => ['a' => 1, 'locale' => 'fr']] : ['a' => 1, 'locale' => 'fr']])
        ->and(dtoThroughEmitted($class, 'POST', $nested ? ['meta' => $json] : $json))
        ->toBe([200, $nested ? ['meta' => ['locale' => 'fr']] : ['locale' => 'fr']]);
})->with(['at the root' => [false], 'nested' => [true]]);

// A form carries text, so the value schema's `integer` is loose there. A declared name PHP rewrites (`user.name` arrives
// as `user_name`) is spared under that name. **The stated gap:** an undeclared `user_name` arrives under the same key and
// is judged by `user.name`'s rule alone, so it passes though the value schema would refuse it, and the generated file
// says so. A later fix shows as a change to this row.
it('validates a form map\'s values beside declared properties', function (string $mediaType): void {
    $integers = new Schema(types: [SchemaType::Integer]);
    $beside = emittedRequestClass(postWithMapBody(
        mapBeside(['locale' => new Schema(types: [SchemaType::String])], $integers),
        mediaTypes: [$mediaType],
    ));
    $rewritten = mapBeside(['user.name' => new Schema(types: [SchemaType::String])], $integers);
    $dotted = emittedRequestClass(postWithMapBody($rewritten, mediaTypes: [$mediaType]));
    $planned = plannedRequests([postWithMapBody($rewritten, mediaTypes: [$mediaType])])['requests'][0];
    $file = (new FormRequestEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($planned)->contents;

    expect(sendThroughEmitted($beside, 'POST', parameters: ['locale' => 'fr', 'a' => 'x'])[0])->toBe(422)
        ->and(withKeysSorted(sendThroughEmitted($beside, 'POST', parameters: ['locale' => 'fr', 'a' => '3'])))->toBe([200, ['a' => '3', 'locale' => 'fr']])
        ->and(sendThroughEmitted($dotted, 'POST', parameters: ['user_name' => 'x'])[0])->toBe(200)
        ->and(sendThroughEmitted($dotted, 'POST', parameters: ['user_name' => 'x', 'other' => 'bad'])[0])->toBe(422)
        ->and((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $file))
        ->toContain('A form delivers `user.name` as `user_name`, so an undeclared `user_name` is judged by `user.name`\'s rules, not by the value schema.');
})->with([
    'form-encoded' => ['application/x-www-form-urlencoded'],
    'multipart' => ['multipart/form-data'],
]);

// The size row: Laravel's own `Rule::forEach` guard takes 22 seconds for a map of 10,000 values beside a declared key
// (it undots the whole data for every key), `UndeclaredKeys` takes 0.3. A bound of five seconds fails a quadratic
// mechanism by four times and leaves a slow CI runner more than ten times the room.
it('validates a map of 10,000 values beside a declared key in under 5 seconds', function (): void {
    $class = emittedRequestClass(postWithMapBody(bodyHolding(mapBeside(
        ['locale' => new Schema(types: [SchemaType::String])],
        new Schema(types: [SchemaType::Integer]),
    ))));
    $meta = ['locale' => 'fr'] + array_combine(array_map(static fn (int $i): string => 'key'.$i, range(1, 10000)), range(1, 10000));

    $start = hrtime(true);
    [$status] = sendThroughEmitted($class, 'POST', json: ['meta' => $meta]);
    $seconds = (hrtime(true) - $start) / 1e9;

    expect($status)->toBe(200)
        ->and($seconds)->toBeLessThan(5.0);
});

// A root map beside a `query` parameter is validated: the guard spares the key the parameter arrives under (read from the
// query string, with its own loose rules), and every other key of the body is the map's. `validated()` holds both, and the
// DTO holds the body's keys alone.
it('validates a root map\'s values beside a query parameter', function (string $parameter, string $query, array $json, int $status, array $expected): void {
    $class = emittedRequestClass(postWithMapBody(
        mapOf(new Schema(types: [SchemaType::Integer])),
        [new QueryParameter($parameter, new Schema(types: [SchemaType::Integer]))],
    ));

    $answer = refusedOrValidated(sendThroughEmitted($class, 'POST', $query, $json));

    expect($status === 422 ? [422, array_map(strval(...), array_keys($answer[1]))] : $answer)->toBe([$status, $expected]);
})->with([
    'a good value and the parameter' => ['page', '?page=2', ['a' => 1], 200, ['a' => 1, 'page' => '2']],
    'a value the map refuses' => ['page', '?page=2', ['a' => 'x'], 422, ['a']],
    // The parameter's own rule, `integer`, and never the map's `WholeNumber`: `page` is not a key of the map.
    'a parameter its own rule refuses' => ['page', '?page=abc', ['a' => 1], 422, ['page']],
    'an ID the map refuses' => ['page', '?page=2', ['1042' => 'x'], 422, ['1042']],
    'an ID the map accepts' => ['page', '?page=2', ['1042' => 3], 200, [1042 => 3, 'page' => '2']],
    // PHP delivers `page[size]` under `page`, so the head is the key the guard spares.
    'a bracketed name, its head spared' => ['page[size]', '?page[size]=3', ['a' => 1], 200, ['a' => 1, 'page' => ['size' => '3']]],
]);

it('keeps the message of a query parameter its own beside a root map', function (): void {
    $class = emittedRequestClass(postWithMapBody(
        mapOf(new Schema(types: [SchemaType::Integer])),
        [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));

    [$status, $answer] = sendThroughEmitted($class, 'POST', '?page=abc', ['a' => 1]);

    expect($status)->toBe(422)
        ->and($answer['errors'] ?? null)->toBe(['page' => ['The page field must be an integer.']]);
});

it('types the DTO of a root map beside a query parameter by its values, and emits the value class', function (): void {
    $pet = new Schema(name: 'Pet', source: '#/components/schemas/Pet', types: [SchemaType::Object], properties: ['name' => new Schema(types: [SchemaType::String])]);
    $class = emittedRequestClass(postWithMapBody(mapOf($pet), [new QueryParameter('dryRun', new Schema(types: [SchemaType::Boolean]))]));
    $namespace = substr($class, 0, (int) strrpos($class, '\\Requests\\'));

    expect(class_exists($namespace.'\\Data\\PetInputDto'))->toBeTrue()
        ->and(dtoThroughEmitted($class, 'POST', ['x' => ['name' => 'a']], query: '?dryRun=1'))->toBe([200, ['x' => ['name' => 'a']]])
        ->and(sendThroughEmitted($class, 'POST', '?dryRun=1', ['x' => ['name' => 5]])[0])->toBe(422);
});

// A root that declares properties beside a value schema and a `query` parameter: an undeclared key is judged, the
// parameter's key is read from the query string whatever the body says, and a body key of that name is left out.
it('validates the undeclared keys of a root with properties beside a query parameter', function (string $query, array $json, int $status, array $expected): void {
    $class = emittedRequestClass(postWithMapBody(
        mapBeside(['locale' => new Schema(types: [SchemaType::String])], new Schema(types: [SchemaType::Integer])),
        [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    ));

    $answer = refusedOrValidated(sendThroughEmitted($class, 'POST', $query, $json));

    expect($status === 422 ? [422, array_map(strval(...), array_keys($answer[1]))] : $answer)->toBe([$status, $expected]);
})->with([
    'a declared key, an undeclared one and the parameter' => ['?page=2', ['locale' => 'fr', 'a' => 1], 200, ['a' => 1, 'locale' => 'fr', 'page' => '2']],
    'an undeclared key the value schema refuses' => ['?page=2', ['locale' => 'fr', 'a' => 'x'], 422, ['a']],
    'a body key named like the parameter' => ['', ['locale' => 'fr', 'page' => 'bad'], 200, ['locale' => 'fr']],
]);

it('says a body key named like a query parameter is left out, beside declared properties', function (): void {
    $planned = plannedRequests([postWithMapBody(
        mapBeside(['locale' => new Schema(types: [SchemaType::String])], new Schema(types: [SchemaType::Integer])),
        [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    )])['requests'][0];
    $file = (new FormRequestEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit($planned)->contents;

    expect((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $file))
        ->toContain('A body key named `page` is not an undeclared key of this body: `page` is also a `query` parameter');
});

// A declared name no form delivers is sent by a JSON client as written. Without a dot it is spared by that name; with one
// Laravel replaces the dot of the key with a placeholder, so the name cannot be spared and the values keep the report: the
// declared key stays judged by its own rule alone, with no value rule written for anything.
it('answers a JSON client for a declared name no form delivers, in a body that is JSON and a form', function (string $name, array $json, int $status): void {
    $class = emittedRequestClass(postWithMapBody(
        mapBeside([$name => new Schema(types: [SchemaType::String])], new Schema(types: [SchemaType::Integer])),
        mediaTypes: ['application/json', 'application/x-www-form-urlencoded'],
    ));

    expect(sendThroughEmitted($class, 'POST', json: $json)[0])->toBe($status);
})->with([
    'a name with no dot, an undeclared value that is fine' => ['[x]', ['[x]' => 'ok', 'a' => 1], 200],
    'a name with no dot, an undeclared value the schema refuses' => ['[x]', ['[x]' => 'ok', 'a' => 'bad'], 422],
    // Reported and not validated: the contract's valid body must not be refused for the sake of a rule that cannot be spared.
    'a name with a dot' => ['[x.y]', ['[x.y]' => 'ok', 'a' => 1], 200],
]);

// The copy under an integer-like key is written for a form body too: PHP gives `1042` an integer key as it does in JSON.
it('keeps and judges the integer-like keys of a form root that declares properties', function (string $mediaType): void {
    $class = emittedRequestClass(postWithMapBody(
        mapBeside(['locale' => new Schema(types: [SchemaType::String])], new Schema(types: [SchemaType::Integer])),
        [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
        [$mediaType],
    ));

    $refused = sendThroughEmitted($class, 'POST', '?page=2', parameters: ['locale' => 'fr', '1042' => 'x']);
    $accepted = refusedOrValidated(sendThroughEmitted($class, 'POST', '?page=2', parameters: ['locale' => 'fr', '1042' => '5']));

    expect($refused[0])->toBe(422)
        ->and(array_map(strval(...), array_keys((array) ($refused[1]['errors'] ?? []))))->toBe(['1042'])
        ->and($accepted)->toBe([200, [1042 => '5', 'locale' => 'fr', 'page' => '2']]);
})->with([
    'form-encoded' => ['application/x-www-form-urlencoded'],
    'multipart' => ['multipart/form-data'],
]);

// Laravel's `multiple_of` throws on a number PHP cannot hold, which answers `500`: PHP reads a JSON `1e400` as `INF`, and
// the package's `MultipleOf` refuses it with a `422`, at every level of the body. `1e308` is a number PHP holds, a
// multiple of `0.5`, and passes. The raw JSON is sent as written, since `json_encode()` cannot write `1e400`.
it('answers 422, not 500, to a JSON number too large for a double on a field with multipleOf', function (Schema $body, string $content, int $status, array $keys): void {
    expect(responseForBuiltRules($body, $content))->toBe([$status, $keys]);
})->with(function (): Generator {
    $number = new Schema(types: [SchemaType::Number], multipleOf: 0.5);
    $integer = new Schema(types: [SchemaType::Integer], multipleOf: 3.0);

    yield 'a number, past the largest double' => [oneField($number, required: false), '{"field": 1e400}', 422, ['field']];
    yield 'a number, past the smallest double' => [oneField($number, required: false), '{"field": -1e400}', 422, ['field']];
    yield 'an integer' => [oneField($integer, required: false), '{"field": 1e400}', 422, ['field']];
    yield 'a number the double holds' => [oneField($number, required: false), '{"field": 1e308}', 200, []];
    yield 'a nested field' => [
        oneField(new Schema(types: [SchemaType::Object], properties: ['b' => $number]), required: false),
        '{"field": {"b": 1e400}}',
        422,
        ['field.b'],
    ];
    yield 'a list element' => [
        oneField(new Schema(types: [SchemaType::Object], properties: ['xs' => new Schema(types: [SchemaType::Array], items: $number)]), required: false),
        '{"field": {"xs": [2, 1e400]}}',
        422,
        ['field.xs.1'],
    ];
});

// The same body when the operation also declares a form: the generated request reads it as JSON for a JSON client, with
// one rule set for both.
it('answers 422 to a JSON client too large for a double in a body that is JSON and a form', function (SchemaType $type): void {
    $body = oneField(new Schema(types: [$type], multipleOf: 2.0), required: false);

    expect(responseForBuiltRules($body, '{"field": 1e400}', mediaTypes: ['application/json', 'application/x-www-form-urlencoded']))
        ->toBe([422, ['field']]);
})->with(['an integer' => [SchemaType::Integer], 'a number' => [SchemaType::Number]]);

// A form-only operation still reads a JSON body as JSON (`bodyInput()` does not look at the media type), so a client off
// the contract reaches the rule with an `INF` there too.
it('answers 422 to a JSON client too large for a double on an operation that declares only a form', function (string $mediaType, SchemaType $type, array $messages): void {
    $class = emittedRequestClass(postWithFormSchema($mediaType, oneField(new Schema(types: [$type], multipleOf: 2.0))));

    expect(errorsForRawJson($class, '{"field": 1e400}'))->toBe([422, ['field' => $messages]]);
})->with(['application/x-www-form-urlencoded', 'multipart/form-data'])->with([
    'a number' => [SchemaType::Number, ['The field field must be a multiple of 2.']],
    'an integer' => [SchemaType::Integer, ['The field field must be an integer.', 'The field field must be a multiple of 2.']],
]);

// A text number with a huge exponent reaches a query, a form field and a JSON string alike, and Laravel's `multiple_of`
// writes out every digit the exponent stands for: `1e200000000` is 200 MB of zeros, a memory fatal that answers `500`,
// and an exponent PHP cannot hold (`1e99999999999999999999`) threw. The package decides from the digits, so each
// request takes milliseconds, answers by the contract (`10^n` is a multiple of 2 and never of 3), and stays far below
// a 128 MB `memory_limit`. A JSON string is no number, so it is `422` for that whatever the divisor.
it('judges a text number with a huge exponent exactly, in a query, a form field and a JSON string', function (string $text): void {
    $send = function (int $divisor) use ($text): array {
        $number = new Schema(types: [SchemaType::Number], multipleOf: (float) $divisor);
        $query = emittedRequestClass(new Operation(
            index: 0,
            method: HttpMethod::Get,
            path: PathTemplate::fromString('/things'),
            operationId: 'listThings',
            queryParameters: [new QueryParameter('field', $number)],
        ));
        $form = emittedRequestClass(postWithFormSchema('application/x-www-form-urlencoded', oneField($number)));
        $json = emittedRequestClass(postWithFormSchema('application/json', oneField($number)));

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);

        $answers = [
            'query' => sendThroughEmitted($query, 'GET', '?field='.$text),
            'form' => sendThroughEmitted($form, 'POST', parameters: ['field' => $text]),
            'json' => errorsForRawJson($json, '{"field": "'.$text.'"}'),
        ];

        return [$answers, (hrtime(true) - $start) / 1e9, memory_get_peak_usage() - $before];
    };

    [$byTwo, $secondsByTwo, $growthByTwo] = $send(2);
    [$byThree, $secondsByThree, $growthByThree] = $send(3);

    expect($byTwo['query'][0])->toBe(200)
        ->and($byTwo['form'][0])->toBe(200)
        ->and($byTwo['json'])->toBe([422, ['field' => ['The field field must be a number.']]])
        ->and($byThree['query'][0])->toBe(422)
        ->and($byThree['query'][1]['errors'])->toBe(['field' => ['The field field must be a multiple of 3.']])
        ->and($byThree['form'][0])->toBe(422)
        ->and($byThree['form'][1]['errors'])->toBe(['field' => ['The field field must be a multiple of 3.']])
        ->and($byThree['json'])->toBe([422, ['field' => ['The field field must be a number.', 'The field field must be a multiple of 3.']]])
        ->and($secondsByTwo)->toBeLessThan(0.5)
        ->and($secondsByThree)->toBeLessThan(0.5)
        ->and($growthByTwo)->toBeLessThan(16 * 1024 * 1024)
        ->and($growthByThree)->toBeLessThan(16 * 1024 * 1024);
})->with([
    'an exponent in the hundreds of millions' => '1e200000000',
    'an exponent PHP cannot hold' => '1e99999999999999999999',
]);

// The generated file imports the class and writes the divisor as text, and the answer is `multiple_of`'s own message.
it('answers the emitted request with the message of multiple_of', function (): void {
    $class = emittedRequestClass(postWithFormSchema('application/json', new Schema(
        types: [SchemaType::Object],
        properties: ['q' => new Schema(types: [SchemaType::Number], multipleOf: 0.5)],
        required: ['q'],
    )));

    expect(errorsForRawJson($class, '{"q": 1e400}'))->toBe([422, ['q' => ['The q field must be a multiple of 0.5.']]])
        ->and(errorsForRawJson($class, '{"q": 1.5}')[0])->toBe(200)
        ->and(errorsForRawJson($class, '{"q": 1.2}'))->toBe([422, ['q' => ['The q field must be a multiple of 0.5.']]]);
});

// A form or query client sends text, and the rule answers it as `multiple_of` did: `1e400` as text is a multiple of 2
// that brick/math reads exactly, and `3` is not. Green before the change too: it pins that the rule asks Laravel.
it('reads a form or query client\'s text exactly', function (string $where): void {
    $number = new Schema(types: [SchemaType::Number], multipleOf: 2.0);

    if ($where === 'a query parameter') {
        $class = emittedRequestClass(new Operation(
            index: 0,
            method: HttpMethod::Get,
            path: PathTemplate::fromString('/things'),
            operationId: 'listThings',
            queryParameters: [new QueryParameter('field', $number)],
        ));
        $send = static fn (string $text): array => sendThroughEmitted($class, 'GET', '?field='.$text);
    } else {
        $class = emittedRequestClass($where === 'a form' ? postWithFormSchema('application/x-www-form-urlencoded', oneField($number)) : postWithJsonAndForm('application/x-www-form-urlencoded', oneField($number)));
        $send = static fn (string $text): array => sendThroughEmitted($class, 'POST', parameters: ['field' => $text]);
    }

    expect($send('1e400')[0])->toBe(200)
        ->and($send('4')[0])->toBe(200)
        ->and($send('3')[0])->toBe(422)
        ->and($send('3')[1]['errors'])->toBe(['field' => ['The field field must be a multiple of 2.']]);
})->with(['a form', 'a body that is JSON and a form', 'a query parameter']);

// A map's value is judged under a wildcard, inside the guard of the keys a map spares.
it('answers 422 to a map value too large for a double', function (): void {
    $class = emittedRequestClass(postWithMapBody(mapOf(new Schema(types: [SchemaType::Number], multipleOf: 2.0))));

    expect(errorsForRawJson($class, '{"a": 4, "b": 1e400}'))->toBe([422, ['b' => ['The b field must be a multiple of 2.']]])
        ->and(errorsForRawJson($class, '{"a": 4, "b": 6}')[0])->toBe(200);
});

/**
 * An operation whose body is a person: `name` is required and not nullable, `age` is optional, and an `address` needs
 * both of its keys.
 *
 * @param  list<string>  $mediaTypes  one schema, read by every media type
 */
function personBody(HttpMethod $method, array $mediaTypes): Operation
{
    $text = new Schema(types: [SchemaType::String]);

    return new Operation(
        index: 0,
        method: $method,
        path: PathTemplate::fromString('/people/{id}'),
        operationId: 'changePerson',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, new Schema(
            types: [SchemaType::Object],
            properties: [
                'name' => $text,
                'age' => new Schema(types: [SchemaType::Integer]),
                'address' => new Schema(types: [SchemaType::Object], properties: ['city' => $text, 'street' => $text], required: ['city', 'street']),
            ],
            required: ['name'],
        )), true),
    );
}

// A merge patch is a partial document (RFC 7396): nothing is required of a top-level key, whatever the method, and a
// value is judged when it is sent. The nested object keeps its list, which the doctor counts.
it('answers a merge patch as a partial JSON body through the kernel', function (
    HttpMethod $method,
    array $mediaTypes,
    string $contentType,
    string $content,
    int $status,
    array $errorKeys,
    ?array $dto,
): void {
    $class = emittedRequestClass(personBody($method, array_values($mediaTypes)));

    expect(dtoForContentType($class, $contentType, $content))->toBe([$status, $errorKeys, $dto]);
})->with(function (): array {
    $patch = [HttpMethod::Patch, ['application/merge-patch+json'], 'application/merge-patch+json'];

    return [
        'an empty object' => [...$patch, '{}', 200, [], []],
        'a value sent' => [...$patch, '{"name":"x"}', 200, [], ['name' => 'x']],
        'a value the schema refuses' => [...$patch, '{"age":"x"}', 422, ['age'], null],
        'null for a name that is not nullable' => [...$patch, '{"name":null}', 422, ['name'], null],
        'no content, for a required body' => [...$patch, '', 422, ['body'], null],
        'a POST with an empty object' => [HttpMethod::Post, ['application/merge-patch+json'], 'application/merge-patch+json', '{}', 200, [], []],
        'a nested object without a name it requires' => [...$patch, '{"address":{"city":"x"}}', 422, ['address'], null],
        'a JSON Patch beside a merge patch' => [
            HttpMethod::Patch,
            ['application/json-patch+json', 'application/merge-patch+json'],
            'application/json-patch+json',
            '[{"op":"remove","path":"/a"}]',
            422,
            ['body'],
            null,
        ],
    ];
});
