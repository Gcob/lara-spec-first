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
        ->toContain("'age' => ['sometimes', 'integer', 'min:18'],")
        ->toContain("'nickname' => ['sometimes', 'nullable', 'string'],")
        ->toContain("'tags.*' => ['string', 'distinct:strict'],")
        ->toContain("'address' => ['sometimes', 'array', 'required_array_keys:city'],")
        ->toContain("'notify' => ['sometimes', 'boolean'],");
});

// An array, never `in:a,b`: the string form splits on a comma inside a value.
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
        ->toContain('return CreateUserInputDto::from($this->validated());')
        ->toContain('@see `\\App\\Http\\Generated\\Data\\CreateUserInputDto`')
        ->toContain('`dto()` returns the body as `CreateUserInputDto`');
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

    expect($emitted)->not->toContain('use Illuminate\\Validation\\Rule;')
        ->and($emitted)->not->toContain('use Illuminate\\Validation\\Validator;')
        ->and($emitted)->not->toContain('function after()');
});

// A root has no field for `array:` to sit on, so a closed root becomes a check
// on its top-level keys, read from the body rather than from the query string.
it('closes the root with an after() check on its declared keys', function (): void {
    expect(emittedRequest(goldenOperation()))
        ->toContain("private const BODY_KEYS = ['email', 'age', 'nickname', 'role', 'tags', 'address', 'website'];")
        ->toContain('public function after(): array')
        // The body bag and the uploaded files, never `getInputSource()`, which
        // answers with the query string on a `GET` or a `HEAD`.
        ->toContain('$this->isJson() ? $this->json()->all() : $this->request->all() + $this->files->all()');
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

// The closed root reads the form bag and the files with `+`, which keeps an integer
// key a spread would renumber: a property named "2024" is a valid payload.
it('joins the form bag and the files without renumbering integer keys', function (): void {
    $emitted = emittedRequest(goldenOperation());

    expect($emitted)->toContain('$this->request->all() + $this->files->all()')
        ->and($emitted)->not->toContain('...$this->files');
    expect([2024 => 'a'] + [])->toBe([2024 => 'a'])
        ->and([...[2024 => 'a'], ...[]])->toBe([0 => 'a']);
});
