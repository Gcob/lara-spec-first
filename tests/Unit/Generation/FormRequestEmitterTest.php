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
use Gcob\LaraSpecFirst\Generation\RequestName;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
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
 * The operation the golden file is generated from, and the one most cases here
 * use: enough shapes to exercise presence, nullability and a finding.
 */
function goldenOperation(): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/users'),
        operationId: 'createUser',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: [
                'email' => new Schema(types: [SchemaType::String], format: 'email', maxLength: 255),
                'age' => new Schema(types: [SchemaType::Integer], minimum: 18.0),
                'nickname' => new Schema(types: [SchemaType::String, SchemaType::Null]),
                'role' => new Schema(types: [SchemaType::String], enum: ['admin', 'member']),
                'tags' => new Schema(
                    types: [SchemaType::Array],
                    items: new Schema(types: [SchemaType::String]),
                    uniqueItems: true,
                ),
                'address' => new Schema(
                    types: [SchemaType::Object],
                    properties: ['city' => new Schema(types: [SchemaType::String])],
                    required: ['city'],
                ),
                'website' => new Schema(types: [SchemaType::String], format: 'uri'),
            ],
            required: ['email'],
            additionalProperties: false,
        )], true),
        queryParameters: [new QueryParameter('notify', new Schema(types: [SchemaType::Boolean]))],
    );
}

/**
 * The PHP of one operation's request.
 */
function emittedRequest(
    Operation $operation,
    string $namespace = 'App\\Http\\Generated',
    string $specPath = 'openapi.yaml',
    string $controllerShortName = 'CreateUserController',
): string {
    $name = RequestName::for($operation);
    assert($name !== null);

    return (new FormRequestEmitter($namespace, $specPath))->emit(new PlannedRequest(
        $operation,
        $name,
        RuleSetBuilder::for($operation),
        $controllerShortName,
    ))->contents;
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
        ->toContain('$this->isJson() ? $this->json()->all() : [...$this->request->all(), ...$this->files->all()]');
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
