<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\FormRequestEmitter;
use Gcob\LaraSpecFirst\Generation\InputDtoEmitter;
use Gcob\LaraSpecFirst\Generation\InRule;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Requests\CreateUserRequest;
use Gcob\LaraSpecFirst\Validation\NullOrAbsent;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\FormRequest;
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
 * @param  array<string, list<string|InRule|NullOrAbsent>>  $built
 * @return array<string, list<mixed>>
 */
function frameworkRules(array $built): array
{
    return array_map(
        static fn (array $list): array => array_map(
            static fn (string|InRule|NullOrAbsent $rule): mixed => $rule instanceof InRule ? Rule::in($rule->values) : $rule,
            $list,
        ),
        $built,
    );
}

/**
 * Send one JSON payload at a route validated by exactly what `RuleSetBuilder` emits for a body schema — the builder's
 * output, not a hand-written copy of it, so a regression in the builder fails here.
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
 * @param  array<string, array<string, string>>  $encoding  the body's `encoding`: media type, part, `contentType`
 */
function statusForUpload(Schema $body, array $files, array $fields = [], array $mediaTypes = ['multipart/form-data'], HttpMethod $method = HttpMethod::Post, array $encoding = []): int
{
    $built = RuleSetBuilder::for(new Operation(
        index: 0,
        method: $method,
        path: PathTemplate::fromString('/_generated/upload'),
        operationId: 'upload',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, $body), true, $encoding),
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
 * @return class-string<FormRequest>
 */
function emittedRequestClass(Operation $operation): string
{
    static $count = 0;

    $namespace = 'Gcob\\LaraSpecFirst\\Tests\\Emitted\\Run'.++$count;
    $planned = plannedRequests([$operation])['requests'][0];
    $path = (string) tempnam(sys_get_temp_dir(), 'lsf-request-');

    file_put_contents($path, (new FormRequestEmitter($namespace, 'tests/emitted.yaml'))->emit($planned)->contents);
    require $path;
    unlink($path);

    // `dto()` names a class the build writes beside the request, so it has to exist.
    foreach (plannedRequests([$operation])['plan']->dtos as $dto) {
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
 * @param  array<string, mixed>  $parameters  form fields, for a request that is not JSON
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
 * @return array{0: int, 1: array<string, mixed>}
 */
function dtoThroughEmitted(string $class, string $method, array $json = [], array $parameters = []): array
{
    Route::match([$method], '/_generated/emitted-dto', fn () => response()->json(app($class)->dto()->toArray()));

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/emitted-dto',
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

it('reads a form-encoded body', function (): void {
    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/users',
        'POST',
        ['email' => 'someone@example.test', 'age' => '30'],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'],
    ));

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['email' => 'someone@example.test', 'age' => '30']);
});

it('refuses a form-encoded body that leaves a required field to the query string', function (): void {
    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/users?email=x@example.test',
        'POST',
        ['age' => '30'],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'],
    ));

    expect($response->getStatusCode())->toBe(422);
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

// PHP turns `.` and a space in a query name into `_` and `page[size]` into a
// nested array before Laravel sees the request, so a rule keyed by the name the
// contract writes can never find it. The request accepts what the contract does.
it('accepts a request carrying the query names PHP rewrites', function (): void {
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listRewritten',
        queryParameters: [
            new QueryParameter('user.name', new Schema(types: [SchemaType::String]), required: true),
            new QueryParameter('a b', new Schema(types: [SchemaType::Integer]), required: true),
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer]), required: true),
            new QueryParameter('limit', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect(sendThroughEmitted($class, 'GET', '?user.name=ada&a%20b=1&page[size]=3&limit=2'))->toBe([200, ['limit' => '2']])
        ->and(sendThroughEmitted($class, 'GET')[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'GET', '?limit=x')[0])->toBe(422);
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

// A bracketed name is delivered below the top-level key another name uses (`page[size]` is
// `page => [size => ...]`), so the two cannot be told apart in the data: no rule for either, any
// value passes, and neither is built into the DTO, which has them optional. Two names that differ
// only by a rewritten character (`a b`, `a.b`) never get this far: the DTO refuses their shared property name.
it('gives a collision no rule, so a form value passes unchecked and does not reach the DTO', function (): void {
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
        requestBody: new RequestBody(['multipart/form-data' => $schema], true),
    ));

    expect(sendThroughEmitted($class, 'POST', parameters: ['plain' => 'x']))->toBe([200, ['plain' => 'x']])
        ->and(dtoThroughEmitted($class, 'POST', parameters: ['page' => ['size' => 'anything'], 'plain' => 'x']))->toBe([200, ['plain' => 'x']])
        ->and(sendThroughEmitted($class, 'POST', parameters: ['page' => 'anything'])[0])->toBe(422);
});

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

// `page` and `page[size]` in a body that is JSON and a form: a rule on `page` would judge the array a
// form builds from `page[size]=3` and refuse it, so neither has one.
it('does not refuse a form client for a name that collides with another in a mixed body', function (): void {
    $schema = new Schema(
        types: [SchemaType::Object],
        properties: ['page' => new Schema(types: [SchemaType::Integer]), 'page[size]' => new Schema(types: [SchemaType::Integer]), 'plain' => new Schema(types: [SchemaType::String])],
    );
    $class = emittedRequestClass(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/mixed-collide'),
        operationId: 'sendMixedCollision',
        requestBody: new RequestBody(['application/json' => $schema, 'multipart/form-data' => $schema], true),
    ));

    expect(sendThroughEmitted($class, 'POST', parameters: ['page' => ['size' => '3'], 'plain' => 'x'])[0])->toBe(200)
        ->and(sendThroughEmitted($class, 'POST', json: ['page' => 1, 'page[size]' => 3, 'plain' => 'x'])[0])->toBe(200);
});

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

it('keeps Laravel\'s boolean for a JSON body', function (): void {
    $class = emittedRequestClass(postWithBooleanField(['application/json']));

    expect(sendThroughEmitted($class, 'POST', json: ['active' => false]))->toBe([200, ['active' => false]])
        ->and(sendThroughEmitted($class, 'POST', json: ['active' => 'true'])[0])->toBe(422);
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
function postWithOptionalJsonObject(bool $typed = true, bool $closed = false, bool $nullable = false, bool $bodyRequired = true): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
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
 * One request through the real kernel carrying `$content` with the `Content-Type` as given, or none: what
 * `curl -X POST url` sends, which `Request::create()` would otherwise default to a form's.
 *
 * @param  class-string<FormRequest>  $class
 * @param  array<string, mixed>  $parameters
 * @param  array<string, mixed>  $files
 */
function statusForContentType(string $class, ?string $contentType, string $content = '', array $parameters = [], array $files = []): int
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

    return app(HttpKernel::class)->handle($request)->getStatusCode();
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

it('does not refuse an upload sent with no content type', function (): void {
    $class = emittedRequestClass(postWithOptionalFormObject(['multipart/form-data']));

    expect(statusForContentType($class, null, files: ['avatar' => realUpload('x', 'a.txt')]))->toBe(200);
});

// Only a JSON request is read as an absent body: a request that is not JSON has its body read from
// the form bag, and a form's fields need not show in the raw content.
it('does not read an empty content as an absent body when the request is not JSON', function (): void {
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
