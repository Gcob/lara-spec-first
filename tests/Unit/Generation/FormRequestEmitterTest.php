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
use Gcob\LaraSpecFirst\Generation\PlannedRequest;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\Process\Process;

/*
 * The text of a generated request, asserted the way any other output is.
 *
 * Emitter-level rather than through the command: what is under test is the
 * string, and reaching it through a build would put a filesystem, a document
 * and a planner between the assertion and the thing it is about. The golden
 * file beside it is the other half — it pins the whole shape at once, and it is
 * a real class, so Pint and Larastan judge it on every run.
 *
 * @see tests/Fixtures/Generated/Requests/CreateUserRequest.php
 * @see docs/guide/code-generation/request-validation.md — "The generated request is final"
 */

/**
 * The PHP of one operation's request.
 */
function emittedRequest(
    Operation $operation,
    string $namespace = 'App\\Http\\Generated',
    string $specPath = 'openapi.yaml',
    string $controllerShortName = 'CreateUserController',
): string {
    return (new FormRequestEmitter($namespace, $specPath))
        ->emit(plannedRequests([$operation], $controllerShortName)['requests'][0])
        ->contents;
}

it('emits a final class extending the framework base', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain('use Illuminate\\Foundation\\Http\\FormRequest;')
        ->toContain('final class CreateUserRequest extends FormRequest');
});

it('writes the rule set as rules() returns it', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain("'email' => ['present', 'string', 'max:255', 'email'],")
        ->toContain("'age' => ['sometimes', 'integer:strict', 'min:18'],")
        ->toContain("'nickname' => ['sometimes', 'nullable', 'string'],")
        ->toContain("'tags.*' => ['string', 'distinct:strict'],")
        ->toContain("'address' => ['sometimes', 'array', 'required_array_keys:city'],")
        ->toContain("'notify' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],");
});

// An array, never `in:a,b`: the string form splits on a comma inside a value.
it('writes the rule for a nullable recursive field below its depth, and imports it', function (): void {
    $address = new Schema(
        name: 'Address',
        types: [SchemaType::Object, SchemaType::Null],
        properties: ['parent' => Schema::recursion('#/components/schemas/Address', 'Address')],
        maxDepth: 1,
        source: '#/components/schemas/Address',
    );
    $emitted = emittedRequest(new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/addresses'),
        operationId: 'createAddress',
        requestBody: new RequestBody(['application/json' => $address], true),
    ));

    expect($emitted)
        ->toContain('use Gcob\\LaraSpecFirst\\Validation\\NullOrAbsent;')
        ->toContain("'parent.parent' => ['sometimes', new NullOrAbsent],")
        ->and($emitted)->not->toContain('use Illuminate\\Validation\\Rule;');
});

it('writes an enumeration as Rule::in over an array, and imports Rule', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain("'role' => ['sometimes', 'string', Rule::in(['admin', 'member'])],")
        ->toContain('use Illuminate\\Validation\\Rule;');
});

// `validated()` keeps returning Laravel's array, and `dto()` sits beside it
// under a name of ours.
it('declares dto() returning the input DTO, built from validated()', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain('use App\\Http\\Generated\\Data\\CreateUserInputDto;')
        ->toContain('public function dto(): CreateUserInputDto')
        ->toContain('return CreateUserInputDto::from($validator->validated());')
        ->toContain('@see `\\App\\Http\\Generated\\Data\\CreateUserInputDto`')
        ->toContain('`dto()` returns the body as `CreateUserInputDto`');
});

/**
 * A `/things` operation whose object body is read as `$mediaType`, or as each of them, for `dto()`'s return type.
 *
 * @param  string|list<string>  $mediaType
 */
function nullableBodyOperation(HttpMethod $method, string|array $mediaType, bool $nullable, bool $required): Operation
{
    $schema = new Schema(
        types: [SchemaType::Object, ...($nullable ? [SchemaType::Null] : [])],
        properties: ['name' => new Schema(types: [SchemaType::String])],
        required: ['name'],
    );

    return new Operation(
        index: 0,
        method: $method,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(
            array_fill_keys((array) $mediaType, $schema),
            $required,
        ),
    );
}

// The request lets a JSON `null` through exactly when its root is nullable and read as JSON, so `dto()` is nullable
// in the same cases. A form cannot send `null`, so a nullable root read only as a form keeps the plain type.
it('declares dto() nullable only for a nullable JSON body', function (
    HttpMethod $method,
    string|array $mediaType,
    bool $nullable,
    bool $required,
    string $signature,
    bool $returnsNull,
): void {
    assert(is_string($mediaType) || array_is_list($mediaType));
    /** @var string|list<string> $mediaType */
    $emitted = emittedRequest(nullableBodyOperation($method, $mediaType, $nullable, $required), controllerShortName: 'CreateThingController');

    expect($emitted)->toContain($signature)
        ->and(str_contains($emitted, 'return null;'))->toBe($returnsNull)
        ->and(str_contains($emitted, 'or `null` when the body is `null`'))->toBe($returnsNull);
})->with([
    'a required nullable JSON body' => [HttpMethod::Post, 'application/json', true, true, 'public function dto(): ?CreateThingInputDto', true],
    'a PATCH nullable JSON body' => [HttpMethod::Patch, 'application/json', true, true, 'public function dto(): ?CreateThingPartialInputDto', true],
    'an optional nullable JSON body' => [HttpMethod::Post, 'application/json', true, false, 'public function dto(): ?CreateThingPartialInputDto', true],
    'a non-nullable JSON body' => [HttpMethod::Post, 'application/json', false, true, 'public function dto(): CreateThingInputDto', false],
    'a nullable body read as JSON and as a form' => [HttpMethod::Post, ['application/json', 'multipart/form-data'], true, true, 'public function dto(): ?CreateThingInputDto', true],
    'a nullable body read as JSON and as urlencoded' => [HttpMethod::Post, ['application/json', 'application/x-www-form-urlencoded'], true, true, 'public function dto(): ?CreateThingInputDto', true],
    'a nullable multipart body' => [HttpMethod::Post, 'multipart/form-data', true, true, 'public function dto(): CreateThingInputDto', false],
]);

// Pint rewrites what it dislikes in a consumer's project, and the build rewrites it back: the new `dto()` lines are
// held to the same formatter as the golden. The body requires a property, so the file also carries the rules and the
// root checks of a nullable required body.
it('emits a nullable dto() Pint has nothing to change in', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'lsf-nullable-dto-').'.php';
    file_put_contents($path, emittedRequest(
        nullableBodyOperation(HttpMethod::Post, 'application/json', nullable: true, required: true),
        controllerShortName: 'CreateThingController',
    ));

    $pint = new Process([PHP_BINARY, 'vendor/bin/pint', '--test', $path], dirname(__DIR__, 3));
    $pint->run();
    unlink($path);

    expect($pint->getExitCode())->toBe(0, $pint->getOutput().$pint->getErrorOutput());
});

// `validated()` leaves out an array with child rules when none of its children was sent. `dto()`
// reads with that off, and puts it back, so `validated()` is what Laravel returns after a `dto()`
// call as before it. Run against Laravel in tests/Feature/Generation/GeneratedDeclaredArraysTest.php.
it('reads the validated data with excludeUnvalidatedArrayKeys off, and restores it', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain('$excluded = $validator->excludeUnvalidatedArrayKeys;')
        ->toContain('$validator->excludeUnvalidatedArrayKeys = false;')
        ->toContain('} finally {')
        ->toContain('$validator->excludeUnvalidatedArrayKeys = $excluded;')
        ->and(emittedRequest(goldenOperation()))->not->toContain('$this->validated()');
});

// The reason the method is `dto()`: `Request::data()` exists and is read by the typed
// accessors, so a generated `data(): CreateUserInputDto` is a fatal error at load. A
// Laravel that grows a `dto()` fails here, rather than in a consumer's boot.
it('is named dto() because Laravel owns data()', function (): void {
    $request = new ReflectionClass(FormRequest::class);

    expect($request->hasMethod('data'))->toBeTrue()
        ->and($request->hasMethod('dto'))->toBeFalse();
});

// The method decides which type its request reads: a `PATCH` and a body that may
// be absent take the partial one, so an absent property is not read as `null`.
it('returns the partial type for a PATCH and for an optional body', function (string $method, bool $required): void {
    $golden = goldenOperation();
    $body = $golden->requestBody;
    assert($body !== null);

    $operation = new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString('/users'),
        operationId: 'createUser',
        requestBody: new RequestBody($body->content, $required),
    );

    expect(emittedRequest($operation))->toContain('public function dto(): CreateUserPartialInputDto');
})->with([
    'a PATCH' => ['patch', true],
    'an optional body' => ['post', false],
]);

// No body, no DTO: the query parameters stay on the request, where
// `validated('page')` reads them.
it('has no dto() for a request with only query parameters', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/users'),
        operationId: 'listUsers',
        queryParameters: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    );

    expect(emittedRequest($operation))
        ->not->toContain('function dto()')
        ->and(emittedRequest($operation))->not->toContain('\\Data\\');
});

it('has no dto() for a body that is not an object', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/tags'),
        operationId: 'addTags',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Array],
            items: new Schema(types: [SchemaType::String]),
        )], true),
    );

    expect(emittedRequest($operation))->not->toContain('function dto()');
});

it('imports nothing it does not use', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: ['name' => new Schema(types: [SchemaType::String])],
        )], true),
    );

    $emitted = emittedRequest($operation, controllerShortName: 'CreateThingController');

    // `Validator` is used here, by `dto()` and by the root check, and `Rule` by nothing.
    expect($emitted)->not->toContain('use Illuminate\\Validation\\Rule;')
        ->and($emitted)->toContain('use Illuminate\\Validation\\Validator;')
        ->and($emitted)->toContain('assert($validator instanceof Validator);')
        ->and($emitted)->not->toContain('BODY_KEYS');
});

it('imports no Validator for a request with no dto() and no closed root', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listThings',
        queryParameters: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    );

    expect(emittedRequest($operation, controllerShortName: 'ListThingsController'))
        ->not->toContain('use Illuminate\\Validation\\Validator;');
});

// A root has no field for `array:` to sit on, so a closed root becomes a check
// on its top-level keys, read from the body rather than from the query string.
it('closes the root with an after() check on its declared keys', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain("private const BODY_KEYS = ['email', 'age', 'nickname', 'role', 'tags', 'address', 'website'];")
        ->toContain('public function after(): array')
        // The same reader `validationData()` uses, so the two cannot disagree
        // about what the body is.
        ->toContain('foreach (array_keys($this->bodyInput()) as $key)');
});

// An open JSON object has no keys to compare, and still has a root that must be one: the same
// `after()` carries the check, and a form-only body carries neither.
it('checks that a JSON root is an object in the same after() as the closed root', function (): void {
    $emitted = emittedRequest(goldenOperation());

    expect($emitted)->toContain('! is_object(json_decode($content))')
        ->and($emitted)->toContain("\$validator->addFailure('body', 'array');")
        ->and(substr_count($emitted, 'public function after()'))->toBe(1)
        ->and(substr_count($emitted, 'function (Validator $validator): void'))->toBe(1);
});

it('emits an after() for an open JSON object and imports Validator for it', function (): void {
    $emitted = emittedRequest(bodyOnlyOperation(), controllerShortName: 'CreateThingController');

    expect($emitted)->toContain('public function after(): array')
        ->and($emitted)->toContain('json_decode($content)')
        ->and($emitted)->not->toContain('BODY_KEYS')
        ->and($emitted)->not->toContain('return;');
});

it('lets a nullable body be null', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object, SchemaType::Null],
            properties: ['name' => new Schema(types: [SchemaType::String])],
        )], true),
    );

    expect(emittedRequest($operation, controllerShortName: 'CreateThingController'))
        ->toContain("&& trim(\$content) !== 'null'");
});

// A form cannot send a non-object, so there is no root to decode. A required one still gets the check for an
// empty content, which no rule makes when every property is optional; an optional one gets no `after()`.
it('emits no root check for a form body, and the empty-content check only when it is required', function (bool $required, bool $expected): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['multipart/form-data' => new Schema(
            types: [SchemaType::Object],
            properties: ['name' => new Schema(types: [SchemaType::String])],
        )], $required),
    );

    $emitted = emittedRequest($operation, controllerShortName: 'CreateThingController');

    expect($emitted)->not->toContain('json_decode')
        ->and(str_contains($emitted, 'function after()'))->toBe($expected)
        ->and(str_contains($emitted, "\$this->getFormat(strtolower((string) \$this->headers->get('Content-Type'))) !== 'form'"))->toBe($expected);
})->with([
    'required' => [true, true],
    'optional' => [false, false],
]);

// An optional JSON body that requires properties cannot refuse `{}` by a rule, so the request reads the raw content:
// the keys are one constant, keyed by name and valued by the rule their failure carries, and one loop adds them.
it('emits the required-key check only for an optional JSON body or a nullable required one', function (string $media, bool $bodyRequired, array $required, bool $expected): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody([$media => new Schema(
            types: [SchemaType::Object],
            properties: [
                'name' => new Schema(types: [SchemaType::String]),
                'size' => new Schema(types: [SchemaType::Integer]),
            ],
            required: array_values($required),
        )], $bodyRequired),
    );

    $emitted = emittedRequest($operation, controllerShortName: 'CreateThingController');

    expect(str_contains($emitted, "private const REQUIRED_KEYS = ['name' => 'present', 'size' => 'required'];"))->toBe($expected)
        ->and(str_contains($emitted, '$validator->addFailure((string) $key, $rule);'))->toBe($expected)
        ->and(str_contains($emitted, 'REQUIRED_KEYS'))->toBe($expected);
})->with([
    'optional, JSON' => ['application/json', false, ['name', 'size'], true],
    'required, JSON' => ['application/json', true, ['name', 'size'], false],
    'optional, a form only' => ['multipart/form-data', false, ['name', 'size'], false],
    'optional, requiring nothing' => ['application/json', false, [], false],
]);

/**
 * A `/things` operation whose body requires `name` and `age`, read as the given media types.
 *
 * @param  list<string>  $mediaTypes
 */
function nullableRequiringOperation(array $mediaTypes, bool $nullable, bool $bodyRequired = true): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(array_fill_keys($mediaTypes, new Schema(
            types: [SchemaType::Object, ...($nullable ? [SchemaType::Null] : [])],
            properties: [
                'name' => new Schema(types: [SchemaType::String]),
                'age' => new Schema(types: [SchemaType::Integer]),
            ],
            required: ['name', 'age'],
        )), $bodyRequired),
    );
}

// A required body whose root allows `null` asks for its required keys through each other, so a rule cannot refuse `{}`
// either: it gets the same constant and loop as an optional body, and `dto()` is nullable. The same body that cannot be
// `null` keeps its plain rules and has neither.
it('emits the required-key check for a nullable required JSON body', function (array $mediaTypes, bool $nullable, bool $expected): void {
    $emitted = emittedRequest(nullableRequiringOperation(array_values($mediaTypes), $nullable), controllerShortName: 'CreateThingController');

    expect(str_contains($emitted, "private const REQUIRED_KEYS = ['name' => 'present', 'age' => 'required'];"))->toBe($expected)
        ->and(str_contains($emitted, '$validator->addFailure((string) $key, $rule);'))->toBe($expected)
        ->and(str_contains($emitted, 'public function dto(): ?CreateThingInputDto'))->toBe($nullable);
})->with([
    'JSON, nullable' => [['application/json'], true, true],
    'JSON and a form, nullable' => [['application/json', 'application/x-www-form-urlencoded'], true, true],
    'JSON, not nullable' => [['application/json'], false, false],
    'JSON and a form, not nullable' => [['application/json', 'application/x-www-form-urlencoded'], false, false],
]);

// Why the two conditions differ: an optional body may be absent, so the loop waits for a JSON object, while a required
// body may only be `null`, so the loop runs on anything else (a form, a `text/plain` content, an empty one), which is
// the answer it always had.
it('switches the required-key check on whether the body is required', function (bool $bodyRequired, string $present, string $absent): void {
    $emitted = emittedRequest(nullableRequiringOperation(['application/json'], true, $bodyRequired), controllerShortName: 'CreateThingController');

    expect($emitted)->toContain($present)
        ->and($emitted)->not->toContain($absent);
})->with([
    'required' => [
        true,
        'if (! ($this->isJson() && trim($content) === \'null\')) {',
        'if ($this->isJson() && is_object(json_decode($content))) {',
    ],
    'optional' => [
        false,
        'if ($this->isJson() && is_object(json_decode($content))) {',
        'if (! ($this->isJson() && trim($content) === \'null\')) {',
    ],
]);

// A form delivers `user.name` as `user_name`, and `validated()` holds it there, so `dto()` puts the value
// back under the name the contract writes. Written only when a name is rewritten.
it('puts the names a form delivered back before dto() builds the DTO', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['multipart/form-data' => new Schema(
            types: [SchemaType::Object],
            properties: [
                'user.name' => new Schema(types: [SchemaType::String]),
                'page[size]' => new Schema(types: [SchemaType::Integer]),
                'plain' => new Schema(types: [SchemaType::String]),
            ],
        )], true),
    );

    $emitted = emittedRequest($operation, controllerShortName: 'CreateThingController');

    expect($emitted)->toContain("private const FORM_NAMES = ['user.name' => ['user_name'], 'page[size]' => ['page', 'size']];")
        ->and($emitted)->toContain('CreateThingInputDto::from($this->contractNames($validator->validated()));')
        ->and($emitted)->toContain('private function contractNames(array $payload): array')
        ->and($emitted)->toContain("'user_name' => ['sometimes', 'string'],")
        ->and($emitted)->toContain("'page.size' => ['sometimes', 'integer'],")
        ->and(emittedRequest(bodyOnlyOperation(), controllerShortName: 'CreateThingController'))
        ->not->toContain('contractNames');
});

/**
 * A `GET` whose only input is one query parameter.
 */
function queryOnlyOperation(): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/users'),
        operationId: 'listUsers',
        queryParameters: [new QueryParameter('page', new Schema(types: [SchemaType::Integer]))],
    );
}

/**
 * A `POST` with an open body and no query parameter.
 */
function bodyOnlyOperation(): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: ['name' => new Schema(types: [SchemaType::String])],
        )], true),
    );
}

// Laravel's `validationData()` is `all()`, the body with the query string merged
// in. The generated one reads each key from the one place the contract put it.
it('reads the body keys from the body and the declared query keys from the query string', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain("private const QUERY_KEYS = ['notify'];")
        ->toContain('public function validationData(): array')
        ->toContain('$query = array_flip(self::QUERY_KEYS);')
        ->toContain('return array_diff_key($this->bodyInput(), $query) + array_intersect_key($this->query->all(), $query);')
        // The body bag and the uploads, never `getInputSource()` or `input()`,
        // which answer with the query string on a `GET` or a `HEAD`.
        ->toContain('$body = $this->isJson() ? $this->json()->all() : $this->request->all();')
        ->toContain('return array_replace_recursive($body, $this->allFiles(), $body);')
        ->and(emittedRequest(goldenOperation()))->not->toContain('getInputSource')
        ->and(emittedRequest(goldenOperation()))->not->toContain('$this->all()')
        ->and(emittedRequest(goldenOperation()))->not->toContain('->input(');
});

it('reads only the body when the operation declares no query parameter', function (): void {
    expect(emittedRequest(bodyOnlyOperation()))
        ->toContain('return $this->bodyInput();')
        ->and(emittedRequest(bodyOnlyOperation()))->not->toContain('QUERY_KEYS')
        ->and(emittedRequest(bodyOnlyOperation()))->not->toContain('array_intersect_key');
});

it('reads only the declared query keys when the operation has no body', function (): void {
    $emitted = emittedRequest(queryOnlyOperation());

    expect($emitted)
        ->toContain("private const QUERY_KEYS = ['page'];")
        ->toContain('return array_intersect_key($this->query->all(), array_flip(self::QUERY_KEYS));')
        ->and($emitted)->not->toContain('bodyInput')
        ->and($emitted)->not->toContain('isJson');
});

// PHP delivers `page[size]` as `page => [size => ...]`, so the query bag has one key, `page`, and that is the one
// `validationData()` reads. The rule is keyed by the path, which is how Laravel finds the value inside it.
it('names the key a rewritten query name arrives under in QUERY_KEYS', function (): void {
    $emitted = emittedRequest(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/users'),
        operationId: 'listUsers',
        queryParameters: [
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('page[number]', new Schema(types: [SchemaType::Integer])),
        ],
    ));

    expect($emitted)
        ->toContain("private const QUERY_KEYS = ['page'];")
        ->toContain("The keys the contract's `query` parameters arrive under, the only ones read from the query string.")
        ->toContain("'page.size' => [")
        ->toContain("'page.number' => [");
});

// Authorization is the route's and a Policy's. A generated answer here would be
// a second place to look for one decision.
it('authorizes everything and says why', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain('public function authorize(): bool')
        ->toContain('return true;')
        ->toContain('`authorize()` returns true, and that is not an omission');
});

it('carries the provenance every generated file carries', function (): void {
    expect(emittedRequest(goldenOperation(), specPath: 'contracts/api.yaml'))
        ->toContain('contracts/api.yaml')
        ->toContain('#/paths/~1users/post');
});

// Laravel runs a FormRequest because a method declared one, so a generated
// class nobody declares is emitted, correct, tested and never executed.
it('points at the method that declares it', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain('@see `\\App\\Http\\Generated\\Controllers\\CreateUserController::routeAction()`');
});

it('prints every constraint it read and did not enforce', function (): void {
    expect(emittedRequest(goldenOperation()))->toContain('`website` declares `format: uri`');
});

it('says so when there was nothing left unenforced', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: ['name' => new Schema(types: [SchemaType::String])],
            required: ['name'],
        )], true),
    );

    expect(emittedRequest($operation, controllerShortName: 'CreateThingController'))
        ->toContain('Every constraint the contract states is in the rule set');
});

// A formatter that has something to strip is a formatter fighting the next
// build, and the idempotence this package promises would hold only for projects
// that format nothing.
it('leaves no trailing whitespace in the docblock it writes', function (): void {
    foreach (explode("\n", emittedRequest(goldenOperation())) as $line) {
        expect($line)->toBe(rtrim($line));
    }
});

// The three-argument merge reads like a mistake to whoever meets it, and it is Laravel's own.
it('says the merge of the body and its uploads is Laravel\'s own all()', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain("// Why: this is Laravel's own all(): a text field wins over an upload of the same name.");
});

it('reproduces the golden file byte for byte', function (): void {
    $emitted = emittedRequest(
        goldenOperation(),
        namespace: 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\Generated',
        specPath: 'tests/Fixtures/golden.yaml',
    );

    expect($emitted)->toBe(file_get_contents(
        dirname(__DIR__, 2).'/Fixtures/Generated/Requests/CreateUserRequest.php'
    ));
});

// Risk 3 of the card #35 plan. The build and a consumer's formatter rewrite
// each other forever if the emitted bytes are not already formatted, and the
// docblock is where that bites: `phpdoc_separation` inserts a blank line, and
// `no_trailing_whitespace_in_comment` strips one. Asserted against the real
// formatter rather than reasoned about, on the golden file — which the test
// above pins as the emitter's own output, byte for byte.
it('emits bytes Pint has nothing to change in', function (): void {
    $golden = dirname(__DIR__, 2).'/Fixtures/Generated/Requests/CreateUserRequest.php';

    $pint = new Process(
        [PHP_BINARY, 'vendor/bin/pint', '--test', $golden],
        dirname(__DIR__, 3),
    );

    $pint->run();

    expect($pint->getExitCode())->toBe(0, $pint->getOutput().$pint->getErrorOutput());
});

// A plain `query` name beside the names PHP nests under it is judged only when its value is not an array: Laravel's
// own `Rule::when()`, with the rules the name would have had anyway, and `array` for the value PHP built for the
// nested names that have a rule (the next test covers the ones that have none). The class is read by Pint, so a
// consumer's formatter has nothing to rewrite.
it('writes a plain query name behind Rule::when beside the names PHP nests under it', function (): void {
    $emitted = emittedRequest(new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/things'),
        operationId: 'listThings',
        queryParameters: [
            new QueryParameter('page', new Schema(types: [SchemaType::Integer], minimum: 1.0)),
            new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer])),
            new QueryParameter('filter[a.b]', new Schema(types: [SchemaType::String])),
            new QueryParameter('filter[a.b][c]', new Schema(types: [SchemaType::String])),
        ],
    ));

    expect($emitted)
        ->toContain("use Illuminate\\Support\\Fluent;\n")
        ->toContain("use Illuminate\\Validation\\Rule;\n")
        ->toContain(
            "            'page' => Rule::when(\n"
            ."                fn (Fluent \$input): bool => ! is_array(\$input->get('page')),\n"
            ."                ['sometimes', 'integer', 'min:1'],\n"
            ."                ['array'],\n"
            .'            ),'
        )
        ->toContain("            'page.size' => ['sometimes', 'integer'],")
        // A step that holds a dot cannot be written as one dotted key.
        ->toContain("fn (Fluent \$input): bool => ! is_array(data_get(\$input, ['filter', 'a.b'])),")
        ->toContain("private const QUERY_KEYS = ['page', 'filter'];");

    $file = tempnam(sys_get_temp_dir(), 'lsf-request-').'.php';
    file_put_contents($file, $emitted);

    $pint = new Process([PHP_BINARY, 'vendor/bin/pint', '--test', $file], dirname(__DIR__, 3));
    $pint->run();
    unlink($file);

    expect($pint->getExitCode())->toBe(0, $pint->getOutput().$pint->getErrorOutput());
});

// The `dto()` finding names the key a rewritten `query` name is read under only for a request that has one, with its
// own example, so the others carry no key they never use.
it('names the key of a rewritten query name in the dto finding only where there is one', function (): void {
    /** @param  list<QueryParameter>  $query */
    $withBody = static fn (array $query): Operation => new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'sendThings',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: ['title' => new Schema(types: [SchemaType::String])],
        )], true),
        queryParameters: array_values($query),
    );

    expect(emittedRequest($withBody([new QueryParameter('page[size]', new Schema(types: [SchemaType::Integer]))])))
        ->toContain("`validated('page.size')` for `page[size]`");

    foreach ([[new QueryParameter('limit', new Schema(types: [SchemaType::Integer]))], []] as $query) {
        expect(emittedRequest($withBody($query)))->not->toContain('under the key it arrives under');
    }
});

// An integer key survives: a property named "2024" is a valid payload, and a
// spread renumbers it to 0. `array_diff_key()`, `+` and `array_replace_recursive()`
// all keep the key.
it('joins the body and the query string without renumbering integer keys', function (): void {
    $emitted = emittedRequest(goldenOperation());

    expect($emitted)->not->toContain('...$')
        ->and($emitted)->not->toContain('array_merge');
    expect(array_diff_key([2024 => 'a', 'b' => 'c'], ['b' => 0]) + [])->toBe([2024 => 'a'])
        ->and(array_replace_recursive([2024 => 'a'], [], [2024 => 'a']))->toBe([2024 => 'a'])
        ->and([...[2024 => 'a'], ...[]])->toBe([0 => 'a']);
});

// The PATCH golden, from the YAML contract: no query parameter and an open body, so
// it reads the body alone. Pinned beside the other so the shape it takes is read.
it('reproduces the PATCH golden file byte for byte', function (): void {
    $requests = plannedRequests(extractFixture('input-dto-golden.yaml'), 'UpdateUserController')['requests'];
    $request = array_values(array_filter(
        $requests,
        static fn (PlannedRequest $planned): bool => $planned->name->shortName === 'UpdateUserRequest',
    ))[0];

    $emitted = (new FormRequestEmitter('Gcob\\LaraSpecFirst\\Tests\\Fixtures\\Generated', 'tests/Fixtures/input-dto-golden.yaml'))
        ->emit($request)
        ->contents;

    expect($emitted)->toBe(file_get_contents(
        dirname(__DIR__, 2).'/Fixtures/Generated/Requests/UpdateUserRequest.php'
    ));
});
