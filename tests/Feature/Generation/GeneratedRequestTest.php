<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Requests\CreateUserRequest;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
 * Send one JSON payload at a route validated by exactly what `RuleSetBuilder`
 * emits for a body schema — the builder's output, not a hand-written copy of
 * it, so a regression in the builder fails here.
 *
 * @param  array<string, mixed>  $payload
 */
function statusForBuiltRules(Schema $body, array $payload): int
{
    $rules = RuleSetBuilder::for(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/_generated/built'),
        operationId: 'built',
        requestBody: new RequestBody(['application/json' => $body], true),
    ))->rules;

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
