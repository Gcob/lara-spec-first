<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Exceptions\InvalidPathTemplateException;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\BuildPlan;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnroutablePathException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

/*
 * Planning, and only planning: which files a document produces, which names they
 * take, what is refused, and that a refusal produces nothing at all.
 *
 * What each emitted file *contains* belongs to the class that decides it, and is
 * asserted there — tests/Unit/Generation/RoutesEmitterTest.php for the
 * registrations and the routes metadata, ControllerEmitterTest.php for the
 * controller's. Asserting it here too would mean one change breaking two files
 * for one reason.
 */

/**
 * @param  list<array{0: string, 1: string, 2?: string|null, 3?: string|null, 4?: bool}>  $rows
 * @return list<Operation>
 */
function operations(array $rows): array
{
    $operations = [];

    foreach ($rows as $index => [$method, $path]) {
        $operations[] = new Operation(
            index: $index,
            method: HttpMethod::from($method),
            path: PathTemplate::fromString($path),
            operationId: $rows[$index][2] ?? null,
            controller: $rows[$index][3] ?? null,
            // A fifth column, so a row can state that the operation has
            // something to validate. Absent means it has not, which is what
            // every row written before requests existed meant.
            requestBody: ($rows[$index][4] ?? false) === true
                ? new RequestBody(['application/json' => new Schema], true)
                : null,
        );
    }

    return $operations;
}

/**
 * A class this repository really autoloads, standing in for a custom controller a project has written.
 *
 * Real rather than plausible, because the question the plan asks about an `x-controller` value is whether the
 * autoloader can find a file for it — and a fake would only prove that a fake answers.
 */
function writtenController(): string
{
    return 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\CustomControllers\\WrittenController';
}

function planner(): BuildPlanner
{
    return new BuildPlanner('App\\Http\\Generated', 'openapi.yaml');
}

/**
 * The contents of the routes file a plan produced.
 */
function routesOf(BuildPlan $plan): string
{
    foreach ($plan->files as $file) {
        if ($file->relativePath === GeneratedRoutesLocator::FILE) {
            return $file->contents;
        }
    }

    throw new RuntimeException('the plan produced no routes file');
}

it('plans one controller per operation, plus the routes file', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users'],
        ['get', '/users/{id}'],
    ]));

    expect(array_map(fn (GeneratedFile $f): string => $f->relativePath, $plan->files))->toBe([
        'Controllers/GetUsersController.php',
        'Controllers/GetUsersIdController.php',
        GeneratedRoutesLocator::FILE,
    ]);
});

// Distinct operations reducing to one class name would mean the second silently
// overwrites the first, which is the kind of guess this package refuses.
it('refuses two operations that claim one class name', function (): void {
    expect(fn () => planner()->plan(operations([
        ['get', '/users', 'showUser'],
        ['post', '/accounts', 'show-user'],
    ])))->toThrow(UnusableNameException::class, 'both generate the class name "ShowUserController"');
});

// Reachable without the controller collision above firing, and the advice
// differs: a request's name comes from the `operationId` alone, so two
// operations sharing one while declaring different `x-controller` values pass
// that check and collide here.
it('refuses two operations whose generated requests would be one class', function (): void {
    expect(fn () => planner()->plan(operations([
        ['get', '/users', 'showUser', 'App\\Http\\Controllers\\AController', true],
        ['post', '/accounts', 'showUser', 'App\\Http\\Controllers\\BController', true],
    ])))->toThrow(UnusableNameException::class, 'both generate the request class "ShowUserRequest"');
});

// `routeAction` declares the generated request as `$request` ahead of the
// path's own parameters, so `{request}` beside it would be one variable
// declared twice, which PHP refuses at compile time.
it('refuses a path parameter named like the generated request', function (): void {
    expect(fn () => planner()->plan(operations([
        ['patch', '/requests/{request}', 'updateRequest', null, true],
    ])))->toThrow(UnusableNameException::class, 'path parameter `{request}`');
});

// Only beside a request: an operation with nothing to validate declares no
// `$request`, and the name is free.
it('accepts a path parameter named request when nothing is validated', function (): void {
    expect(planner()->plan(operations([['get', '/requests/{request}', 'showRequest']]))->controllers)
        ->toHaveCount(1);
});

// Symfony's compiler finds placeholders with `[\w\x80-\xFF]+`, so `{user-id}` is
// never recognized as one: it stays literal text and the endpoint answers 404
// forever with nothing reporting it.
it('refuses a path parameter Laravel would never match', function (): void {
    expect(fn () => planner()->plan(operations([['get', '/users/{user-id}']])))
        ->toThrow(UnroutablePathException::class, 'letters, digits and underscores');
});

// The same compiler throws above 32 characters, and it runs while the
// application boots — so the failure would arrive far from its cause.
it('refuses a path parameter longer than the route compiler accepts', function (): void {
    $long = str_repeat('a', UnroutablePathException::PARAMETER_NAME_LIMIT + 1);

    expect(fn () => planner()->plan(operations([['get', '/users/{'.$long.'}']])))
        ->toThrow(UnroutablePathException::class, 'longer than the 32 characters');
});

/*
 * Routable and still unusable, which is what makes these a second check rather
 * than a stricter first one. The generated `routeAction` declares one parameter
 * per path parameter — that is what lets a custom controller override it — so a
 * name legal in a route and illegal as a PHP variable would produce a file that
 * does not parse.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */
it('refuses a path parameter that could not be a PHP variable', function (string $path): void {
    expect(fn () => planner()->plan(operations([['get', $path]])))
        ->toThrow(UnusableNameException::class, 'cannot be a PHP variable');
})->with([
    // `\w` accepts a leading digit and Symfony's compiler matches `{2fa}`
    // happily. `$2fa` is not a variable.
    'a leading digit' => '/users/{2fa}',
    // Legal in a route, fatal as a parameter name.
    'this' => '/users/{this}',
]);

// The neighbouring cases, each refused by the class that owns the reason: a
// character the router would never match is `UnroutablePathException`'s, and one
// path naming a parameter twice is refused where the path is read, before anything
// asks what could be generated from it.
it('leaves the neighbouring refusals to the classes that own them', function (
    string $path,
    string $exception,
    string $message,
): void {
    expect(fn () => planner()->plan(operations([['get', $path]])))->toThrow($exception, $message);
})->with([
    'a character the router drops' => ['/users/{user-id}', UnroutablePathException::class, 'letters, digits'],
    'one name twice' => ['/users/{id}/posts/{id}', InvalidPathTemplateException::class, 'declares "id" twice'],
]);

it('accepts a parameter name PHP can carry, whatever it looks like', function (string $path): void {
    expect(planner()->plan(operations([['get', $path]]))->files)->toHaveCount(2);
})->with([
    'ordinary' => '/users/{id}',
    'an underscore first' => '/users/{_private}',
    'digits after a letter' => '/users/{v2Id}',
]);

// The property the whole two-step exists for: a refusal costs nothing, because
// nothing was ever produced for the operations that came before it.
it('produces nothing at all when one operation is refused', function (): void {
    $planned = null;

    try {
        $planned = planner()->plan(operations([
            ['get', '/users'],
            ['get', '/users/{user-id}'],
        ]));
    } catch (UnroutablePathException) {
        // The point is what did not happen.
    }

    expect($planned)->toBeNull();
});

/*
 * The custom-controller half of planning: which class a route ends up pointing at,
 * and the two collisions that answer differently from the `operationId` one.
 *
 * @see docs/guide/controllers.md — "Two classes, found by name"
 */

it('points the route at the custom controller once that class exists', function (): void {
    $routes = routesOf(planner()->plan(operations([['get', '/users/{id}', 'showUser', writtenController()]])));

    expect($routes)
        ->toContain('use '.writtenController().';')
        ->toContain("Route::get('/users/{id}', [WrittenController::class, 'routeAction']);")
        ->and($routes)->not->toContain('App\\Http\\Generated\\Controllers\\WrittenController');
});

// Until the class exists the generated parent answers, which is what keeps the
// operation at 501 rather than at a class-not-found.
it('points the route at the generated parent while the custom controller is missing', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\Controllers\\NotWrittenYetController'],
    ]));

    expect(routesOf($plan))
        ->toContain('use App\\Http\\Generated\\Controllers\\NotWrittenYetController;')
        ->toContain("[NotWrittenYetController::class, 'routeAction']");
});

it('names the generated parent after the class the contract declared', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUserAccountDetails', 'App\\Http\\Controllers\\UserController'],
    ]));

    expect(array_map(fn (GeneratedFile $f): string => $f->relativePath, $plan->files))->toBe([
        'Controllers/UserController.php',
        GeneratedRoutesLocator::FILE,
    ]);
});

// Distinct fully-qualified names can still share a short name, and one generated
// parent cannot serve two operations. The message names both, and says the thing
// that actually fixes it rather than mentioning `operationId`, which neither of
// these operations is being named from.
it('refuses two declared controllers that reduce to one generated parent', function (): void {
    expect(fn () => planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\Controllers\\Admin\\UserController'],
        ['get', '/accounts/{id}', 'showAccount', 'App\\Http\\Controllers\\Api\\UserController'],
    ])))->toThrow(UnusableNameException::class, 'both generate the same parent');
});

// A child declared inside the generated tree would extend itself, and a build
// rewrites everything under that namespace — so the work would not survive one.
it('refuses a custom controller inside the generated tree', function (): void {
    expect(fn () => planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\Generated\\Controllers\\UserController'],
    ])))->toThrow(UnusableNameException::class, 'inside the generated namespace');
});

// A namespace that merely starts with the same characters is a different
// namespace, and refusing it would refuse a legitimate name.
it('accepts a namespace that only looks like the generated one', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', 'App\\Http\\GeneratedThings\\UserController'],
    ]));

    expect($plan->files)->toHaveCount(2);
});

/*
 * What the plan can tell the command. Counted here rather than read off the
 * command's output, because the number is the plan's answer and the sentence
 * around it is the command's.
 */

it('counts the operations whose route lands on their generated parent', function (): void {
    $plan = planner()->plan(operations([
        ['get', '/users/{id}', 'showUser', writtenController()],
        ['get', '/posts', 'listPosts', 'App\\Http\\Controllers\\NotWrittenYetController'],
        ['get', '/health', 'health'],
    ]));

    expect($plan->routedToGeneratedParent())->toBe(2)
        ->and($plan->controllers)->toHaveCount(3);
});

/*
 * The response DTOs are planned with the controllers, because each controller says what its statuses answer with.
 */

// Each direction keeps its own names, so only the plan sees both: a response schema `UserInput` and a request schema
// `User` derive one class name, and the request's input DTO outranks it. The response schema gives way and the build
// goes on, as it does for every contested name.
it('gives no DTO to a response schema whose class name an input DTO takes', function (): void {
    $plan = planner()->plan(extractFixture('response-name-taken-by-input.yaml'));
    $input = collect($plan->files)
        ->first(static fn (GeneratedFile $file): bool => $file->relativePath === 'Data/UserInputDto.php');

    assert($input instanceof GeneratedFile);

    // Its provenance is the request schema, and it is the input DTO, not the response's.
    expect($input->contents)->toContain('#/components/schemas/User')
        ->and($input->contents)->toContain('implements Arrayable, JsonSerializable')
        ->and(controllerSays($plan, 'ReplaceUser'))->toContain(
            'The `200` response has no DTO: its schema `UserInput` (`#/components/schemas/UserInput`) derives the class '
            .'name `UserInputDto`, which is the input DTO of the request schema written at `#/components/schemas/User`. '
            .'Rename one of the schemas.',
        );
});

it('writes each controller with the response types of its statuses', function (): void {
    $plan = planner()->plan(extractFixture('response-statuses.yaml'));
    $controller = collect($plan->files)
        ->first(static fn (GeneratedFile $file): bool => $file->relativePath === 'Controllers/UpdateUserController.php');

    assert($controller instanceof GeneratedFile);

    expect(preg_replace('/\s*\n \*\s+/', ' ', $controller->contents))
        ->toContain('The `200` response is `UserDto`')
        ->toContain('The `422` response is `ValidationErrorDto`')
        ->toContain('The `204` response has no DTO: it declares no body.');
});

// Two JSON schemas under one status are not a contract the build refuses: the status has no DTO and the controller says
// so, while the status that writes one schema twice has its DTO.
it('builds a contract with two JSON schemas under one status and says so in the controller', function (): void {
    $plan = planner()->plan(extractFixture('response-two-json-schemas.yaml'));
    $paths = array_map(static fn (GeneratedFile $file): string => $file->relativePath, $plan->files);
    $controller = collect($plan->files)
        ->first(static fn (GeneratedFile $file): bool => $file->relativePath === 'Controllers/ShowVersionsController.php');

    assert($controller instanceof GeneratedFile);

    expect($paths)->toContain('Data/ProblemDto.php')
        ->and(preg_replace('/\s*\n \*\s+/', ' ', $controller->contents))
        ->toContain('The `200` response has no DTO: it declares two JSON media types with different schemas')
        ->toContain('The `422` response is `ProblemDto`');
});

/**
 * The relative paths of every file a plan writes.
 *
 * @return list<string>
 */
function plannedPaths(BuildPlan $plan): array
{
    return array_map(static fn (GeneratedFile $file): string => $file->relativePath, $plan->files);
}

/**
 * What one generated controller says, its docblock lines joined the way a reader reads them.
 */
function controllerSays(BuildPlan $plan, string $name): string
{
    $controller = collect($plan->files)
        ->first(static fn (GeneratedFile $file): bool => $file->relativePath === 'Controllers/'.$name.'Controller.php');

    assert($controller instanceof GeneratedFile);

    return (string) preg_replace('/\s*\n \*\s+/', ' ', $controller->contents);
}

/*
 * A response schema with a fault of its own has no DTO, and the build goes on: the status that would have used it says
 * why in the controller. Only the contract's own faults count, and 0.2.0 built every one of these documents.
 */

it('gives no DTO to a response schema whose keys derive one property', function (): void {
    $plan = planner()->plan(extractFixture('response-keys-collide.yaml'));

    expect(plannedPaths($plan))->not->toContain('Data/ListUsers200ResponseDto.php')
        ->and(controllerSays($plan, 'ListUsers'))
        ->toContain('The `200` response has no DTO: its keys `user-id` and `user_id` both derive the property `$user_id`')
        // A `writeOnly` key is never sent, so the other key does not clash with it.
        ->and(plannedPaths($plan))->toContain('Data/ListMembers200ResponseDto.php')
        ->and(controllerSays($plan, 'ListMembers'))->toContain('The `200` response is `ListMembers200ResponseDto`');
});

it('gives no DTO to a response schema whose allOf branches disagree', function (): void {
    $plan = planner()->plan(extractFixture('response-allof-disagrees.yaml'));

    // The 3.1 `$ref` with a sibling `properties` meets its branches in the other order.
    foreach (['ShowThing' => 'integer` and another is `string', 'ShowSibling' => 'string` and another is `integer'] as $controller => $branches) {
        $says = controllerSays($plan, $controller);

        expect($says)->toContain('The `200` response has no DTO: its `allOf` branches disagree at `id`: one branch is `'.$branches.'`')
            ->and($says)->not->toContain('rule set')
            ->and($says)->not->toContain('will not choose');
    }

    expect(array_filter(plannedPaths($plan), static fn (string $path): bool => str_starts_with($path, 'Data/')))->toBe([]);
});

it('gives no DTO to a response schema whose name leaves no class name', function (): void {
    $plan = planner()->plan(extractFixture('response-name-unusable.yaml'));

    expect(controllerSays($plan, 'ShowCode'))
        ->toContain('The `200` response has no DTO: the schema name `123` leaves no class name')
        // `456` would be a DTO and its name is no class name: that is what is said first, ahead of its branches.
        ->and(controllerSays($plan, 'ShowOther'))
        ->toContain('The `200` response has no DTO: the schema name `456` leaves no class name')
        ->and(array_filter(plannedPaths($plan), static fn (string $path): bool => str_starts_with($path, 'Data/')))->toBe([]);

    expect(controllerSays($plan, 'ShowOther'))->not->toContain('`allOf`');
});

it('types mixed a response value whose schema cannot be typed and keeps the others, in the file the build writes', function (): void {
    $plan = planner()->plan(extractFixture('response-property-gives-way.yaml'));
    $file = collect($plan->files)
        ->first(static fn (GeneratedFile $file): bool => $file->relativePath === 'Data/TeamDto.php');

    assert($file instanceof GeneratedFile);

    expect(plannedPaths($plan))->not->toContain('Data/TeamOwnerDto.php')
        ->and($file->contents)->toContain('public string $name')
        ->and($file->contents)->toContain('public int $id')
        ->and($file->contents)->toContain('@param  Optional|mixed  $owner')
        ->and($file->contents)->toContain('public mixed $owner = new Optional')
        ->and((string) preg_replace('/\s*\n \*\s+/', ' ', $file->contents))
        ->toContain('`owner` is `mixed`: its keys `user-id` and `user_id` both derive the property `$user_id`');
});

// What a response gives way to, a request still refuses: the body is read for what it validates, and a rule set that
// drops half of what the document wrote enforces half of a contract.
it('still refuses in a request what a response schema gives way to', function (Schema $body, string $class, string $message): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/things'),
        operationId: 'createThing',
        requestBody: new RequestBody(['application/json' => $body], true),
        responses: [new Response('201', [])],
    );

    expect(static fn (): BuildPlan => planner()->plan([$operation]))->toThrow($class, $message);
})->with([
    'two keys deriving one property' => [
        new Schema(types: [SchemaType::Object], properties: [
            'user-id' => new Schema(types: [SchemaType::String]),
            'user_id' => new Schema(types: [SchemaType::Integer]),
        ]),
        UnusableNameException::class,
        'The keys "user-id" and "user_id" of the schema behind the DTO "CreateThingInputDto" both derive the property name "$user_id"',
    ],
    'allOf branches that disagree' => [
        new Schema(allOf: [
            new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [SchemaType::Integer])]),
            new Schema(types: [SchemaType::Object], properties: ['id' => new Schema(types: [SchemaType::String])]),
        ]),
        ConflictingInputException::class,
        'whose branches disagree: one branch is `integer` and another is `string`. A payload cannot satisfy both, or one rule set cannot say both',
    ],
    'a name that leaves no class name' => [
        new Schema(
            name: '123',
            source: '#/components/schemas/123',
            types: [SchemaType::Object],
            properties: ['value' => new Schema(types: [SchemaType::String])],
        ),
        UnusableNameException::class,
        'The schema "123", written at "#/components/schemas/123", would generate a DTO class name that is not a usable PHP identifier',
    ],
    'an inline property beside a component of the same class name' => [
        new Schema(name: 'User', source: '#/components/schemas/User', types: [SchemaType::Object], properties: [
            'address' => new Schema(types: [SchemaType::Object], properties: ['street' => new Schema(types: [SchemaType::String])]),
            'home' => new Schema(
                name: 'UserAddress',
                source: '#/components/schemas/UserAddress',
                types: [SchemaType::Object],
                properties: ['street' => new Schema(types: [SchemaType::String])],
            ),
        ]),
        UnusableNameException::class,
        'both generate the input DTO "UserAddressInputDto", and the two are not the same schema',
    ],
    'two components of the same class name' => [
        new Schema(types: [SchemaType::Object], properties: [
            'first' => new Schema(name: 'new-user', source: '#/components/schemas/new-user', types: [SchemaType::Object], properties: ['email' => new Schema(types: [SchemaType::String])]),
            'second' => new Schema(name: 'NewUser', source: '#/components/schemas/NewUser', types: [SchemaType::Object], properties: ['name' => new Schema(types: [SchemaType::String])]),
        ]),
        UnusableNameException::class,
        'both generate the input DTO "NewUserInputDto", and the two are not the same schema',
    ],
]);

/**
 * What a plan writes under `Data/` and `Controllers/`, by path and in one order: the files that say what a contested
 * name came to. `routes.php` is left out, because the document's own order is the order of its routes.
 *
 * @return array<string, string>
 */
function writtenContents(BuildPlan $plan): array
{
    $written = [];

    foreach ($plan->files as $file) {
        if (str_starts_with($file->relativePath, 'Data/') || str_starts_with($file->relativePath, 'Controllers/')) {
            $written[$file->relativePath] = $file->contents;
        }
    }

    ksort($written);

    return $written;
}

// Which schema keeps a contested name is a fact about the contract, not about the order it is written in: every case a
// response can give way for is in one document, read both ways round.
it('generates the same files whichever order the operations come in', function (): void {
    $operations = extractFixture('response-untypeable.yaml');
    $forward = writtenContents(planner()->plan($operations));
    $backward = writtenContents(planner()->plan(array_reverse($operations)));

    expect(array_keys($forward))->toContain('Data/MemberAddressDto.php')
        ->and(array_keys($forward))->not->toContain('Data/NewUserDto.php')
        ->and(array_keys($forward))->not->toContain('Data/TeamLeadUserDto.php')
        ->and(array_keys($backward))->toBe(array_keys($forward))
        ->and($backward)->toBe($forward);
});
