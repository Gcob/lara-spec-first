<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gcob\LaraSpecFirst\Data\Optional;
use Gcob\LaraSpecFirst\Generation\FormRequestEmitter;
use Gcob\LaraSpecFirst\Generation\InputDtoEmitter;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Data\AddressInputDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Data\NewUserInputDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Data\NewUserPartialInputDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Requests\UpdateUserRequest;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

/*
 * A generated DTO, run.
 *
 * The emitter tests assert what the class says, and Larastan judges the golden
 * file's types. This is the third half: what the class does when a validated
 * payload goes through it, which is where a cast that reads right and converts
 * wrongly shows up. The classes are the committed golden files, so they are the
 * artefact rather than a copy of it.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
 */

/**
 * What `validated()` hands a multipart body: every scalar a string.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function multipartPayload(array $overrides = []): array
{
    return [
        'email' => 'ada@example.test',
        'born_on' => null,
        'nickname' => null,
        'address' => ['street' => '1 Main St'],
        ...$overrides,
    ];
}

it('casts what a multipart body delivers as strings', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'age' => '42',
        'score' => '1.5',
        'active' => '0',
        'level' => '2',
        'ids' => ['1', '2'],
        '2fa' => '1',
    ]));

    expect($dto->age)->toBe(42)
        ->and($dto->score)->toBe(1.5)
        ->and($dto->active)->toBeFalse()
        ->and($dto->level)->toBe(2)
        ->and($dto->ids)->toBe([1, 2])
        ->and($dto->_2fa)->toBeTrue();
});

// The rule set accepts the text a form writes for a boolean, and `(bool) 'false'` is
// `true`: the cast has to read the text, not its truthiness.
it('reads the text of a boolean as the boolean it spells', function (string $text, bool $expected): void {
    $dto = NewUserInputDto::from(multipartPayload(['active' => $text, '2fa' => $text]));

    expect($dto->active)->toBe($expected)
        ->and($dto->_2fa)->toBe($expected);
})->with([
    'false' => ['false', false],
    'true' => ['true', true],
    'zero' => ['0', false],
    'one' => ['1', true],
]);

// `(int) $payload` over a list the validator already proved is one.
it('builds a nested list of lists with its own casts', function (): void {
    $dto = NewUserInputDto::from(multipartPayload(['matrix' => [['1', '2'], ['3']]]));

    expect($dto->matrix)->toBe([[1, 2], [3]]);
});

// A `null` element of a list of nullable lists passes the rules, so the closure that
// converts each element must accept it: `array $item` made it a 500.
it('lets a nullable element of a list of lists be null', function (): void {
    $dto = NewUserInputDto::from(multipartPayload(['matrix' => [['1'], null]]));

    expect($dto->matrix)->toBe([[1], null]);
});

// `CarbonImmutable::parse(null)` is the current time: a nullable date has to be
// tested before anything converts it.
it('keeps a null date null instead of turning it into now', function (): void {
    $dto = NewUserInputDto::from(multipartPayload(['born_on' => null]));

    expect($dto->born_on)->toBeNull();
});

// The rule set accepts a fraction of any length, and `toRfc3339String()` would drop it
// on the way to mass assignment. Carbon holds microseconds, and so does `toArray()`.
it('keeps the fraction of a date-time on the way back out', function (): void {
    $dto = NewUserInputDto::from(multipartPayload(['seen_at' => '2026-10-03T13:52:51.123456789Z']));

    expect($dto->toArray()['seen_at'])->toBe('2026-10-03T13:52:51.123456+00:00');
});

it('parses the dates the rule set accepted', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'born_on' => '1990-01-02',
        'seen_at' => '2026-10-03T13:52:51.123456789Z',
        'visits' => ['2026-10-03T13:52:51Z'],
    ]));

    expect($dto->born_on)->toBeInstanceOf(CarbonImmutable::class)
        ->and($dto->born_on?->toDateString())->toBe('1990-01-02')
        ->and($dto->seen_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($dto->visits)->toHaveCount(1);
});

it('builds nested DTOs', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'address' => ['street' => '1 Main St', 'parent' => ['street' => '2 Side St']],
        'profile' => ['bio' => 'Hello'],
        'roles' => [['name' => 'admin'], ['name' => 'member']],
    ]));

    expect($dto->address)->toBeInstanceOf(AddressInputDto::class)
        ->and($dto->address->parent)->toBeInstanceOf(AddressInputDto::class)
        ->and($dto->toArray()['address']['parent'])->toBe(['street' => '2 Side St'])
        ->and($dto->roles)->toHaveCount(2);
});

it('hands an upload through untouched', function (): void {
    $file = UploadedFile::fake()->create('a.png', 1, 'image/png');

    expect(NewUserInputDto::from(multipartPayload(['avatar' => $file]))->avatar)->toBe($file);
});

// The reason the type exists, asserted the way `request-validation.md` states it:
// a field the client did not send must not be written back as `null`.
it('does not write a null over a field the client did not send', function (): void {
    $dto = NewUserPartialInputDto::from(['email' => 'ada@example.test']);

    expect($dto->nickname)->toBeInstanceOf(Optional::class)
        ->and($dto->toArray())->toBe(['email' => 'ada@example.test']);
});

// ...and a `null` the client did send on purpose is written.
it('writes a null the client sent on purpose', function (): void {
    $dto = NewUserPartialInputDto::from(['nickname' => null]);

    expect($dto->nickname)->toBeNull()
        ->and($dto->toArray())->toBe(['nickname' => null]);
});

it('writes the contract\'s keys back out, the way the contract spells them', function (): void {
    $dto = NewUserInputDto::from(multipartPayload([
        'born_on' => '1990-01-02',
        'seen_at' => '2026-10-03T13:52:51Z',
        'roles' => [['name' => 'admin']],
        'visits' => ['2026-10-03T13:52:51Z'],
        'user-id' => 'u1',
        '2fa' => '1',
        'ids' => ['1'],
    ]));

    expect($dto->toArray())->toBe([
        'email' => 'ada@example.test',
        'born_on' => '1990-01-02',
        'nickname' => null,
        'address' => ['street' => '1 Main St'],
        'seen_at' => '2026-10-03T13:52:51.000000+00:00',
        'ids' => [1],
        'roles' => [['name' => 'admin']],
        'visits' => ['2026-10-03T13:52:51.000000+00:00'],
        'user-id' => 'u1',
        '2fa' => true,
    ]);
});

// A DTO returned from a controller becomes a JSON response with nothing
// registered, which is what `JsonSerializable` is there for.
it('serializes to the same array', function (): void {
    $dto = NewUserInputDto::from(multipartPayload());

    expect(json_decode((string) json_encode($dto), true))->toBe($dto->toArray());
});

// `Illuminate\Support\Optional` is the class behind `optional()`, and the one an
// IDE auto-imports. The golden files import this package's, explicitly.
it('imports the package\'s Optional and not Laravel\'s', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Generated/Data/NewUserInputDto.php');

    expect($source)->toContain('use Gcob\\LaraSpecFirst\\Data\\Optional;')
        ->and($source)->not->toContain('Illuminate\\Support\\Optional');
});

/*
 * The whole path, through Laravel: a PATCH reaches the generated request, its
 * rule set validates, `dto()` hands back the partial DTO, and `toArray()` is
 * what a controller would pass to `$model->update()`.
 *
 * The class under test is the committed golden `UpdateUserRequest`, which is
 * what the build writes for the PATCH of `tests/Fixtures/input-dto-golden.yaml`.
 */

/**
 * One PATCH, as JSON, through the real kernel.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: int, 1: array<string, mixed>}
 */
function patchUser(array $payload): array
{
    Route::patch('/_generated/users/{id}', function (UpdateUserRequest $request, string $id) {
        $data = $request->dto();

        return response()->json([
            'type' => $data::class,
            'array' => $data->toArray(),
            'validated' => array_keys($request->validated()),
        ]);
    });

    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/users/1',
        'PATCH',
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

// The data loss `request-validation.md` names: a DTO whose absent properties
// arrived as `null` would have `update()` write nulls over stored columns.
it('does not write a null over a column the PATCH did not send', function (): void {
    [$status, $body] = patchUser(['email' => 'ada@example.test']);

    expect($status)->toBe(200)
        ->and($body['type'])->toBe(NewUserPartialInputDto::class)
        ->and($body['array'])->toBe(['email' => 'ada@example.test']);
});

it('writes a null the PATCH sent on purpose', function (): void {
    [$status, $body] = patchUser(['nickname' => null]);

    expect($status)->toBe(200)
        ->and($body['array'])->toBe(['nickname' => null]);
});

it('casts what the rule set accepted', function (): void {
    [$status, $body] = patchUser(['age' => '42', 'level' => '2', 'ids' => ['1', '2']]);

    expect($status)->toBe(200)
        ->and($body['array'])->toBe(['age' => 42, 'level' => 2, 'ids' => [1, 2]]);
});

// The DTO sits beside `validated()` and does not replace it.
it('leaves validated() returning the array of what was sent', function (): void {
    [, $body] = patchUser(['email' => 'ada@example.test', 'age' => 30]);

    expect($body['validated'])->toBe(['email', 'age']);
});

// A payload the rule set refuses never becomes a DTO, which is what makes the
// casts safe: a bad value never gets as far as `from()`.
it('refuses what the rule set refuses before any DTO exists', function (): void {
    [$status, $body] = patchUser(['age' => 'forty']);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['age']);
});

/**
 * An `Address` that holds `$levels` more of itself as `parent`, each with the street the schema requires.
 *
 * @return array<string, mixed>
 */
function addressDeep(int $levels): array
{
    $address = ['street' => 'level '.$levels];

    for ($level = $levels - 1; $level >= 0; $level--) {
        $address = ['street' => 'level '.$level, 'parent' => $address];
    }

    return $address;
}

// The rules used to stop at the recursion, so `parent: "oops"` passed validation and the node
// reached the controller as it arrived. `Address` states no `x-max-depth`, so the request reads
// three levels of `parent`, and a value that is not an address is refused there with a 422.
it('refuses a recursive node that is not the schema it points back at', function (): void {
    [$status, $body] = patchUser(['address' => ['street' => 'x', 'parent' => 'oops']]);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['address.parent']);
});

/**
 * The files the build would write for a fixture contract, with the classes loaded under a namespace of its own.
 *
 * The golden files cover a recursion that is not nullable. These cover the shapes that behave differently at the cut (a
 * list, and a schema that allows `null`) and a recursive pair, and a golden file for each would be one nothing else
 * reads. A namespace is loaded once, whatever the number of tests that ask for it.
 *
 * @param  bool  $reversed  the operations in the opposite order, which must write the same classes
 * @return array<string, string> the contents of each file, by its path under the generated root
 */
function generatedFrom(string $fixture, string $namespace, bool $reversed = false): array
{
    static $files = [];

    if (isset($files[$namespace])) {
        return $files[$namespace];
    }

    $operations = extractFixture($fixture);
    ['requests' => $requests, 'plan' => $plan] = plannedRequests($reversed ? array_reverse($operations) : $operations);
    $dataEmitter = new InputDtoEmitter($namespace, 'tests/Fixtures/'.$fixture);
    $requestEmitter = new FormRequestEmitter($namespace, 'tests/Fixtures/'.$fixture);
    $generated = [
        ...array_map($dataEmitter->emit(...), $plan->dtos),
        ...array_map($requestEmitter->emit(...), $requests),
    ];

    $directory = sys_get_temp_dir().'/lsf-recursion-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        foreach ($generated as $index => $file) {
            file_put_contents($path = $directory.'/'.$index.'.php', $file->contents);
            require $path;
            $files[$namespace][$file->relativePath] = $file->contents;
        }
    } finally {
        array_map(unlink(...), glob($directory.'/*.php') ?: []);
        rmdir($directory);
    }

    ksort($files[$namespace]);

    return $files[$namespace];
}

/**
 * A class the build wrote for a fixture, by its name under the generated namespace.
 *
 * @return class-string
 */
function generatedClass(string $namespace, string $relativeName): string
{
    $class = $namespace.'\\'.$relativeName;

    if (! class_exists($class)) {
        throw new RuntimeException('The build wrote no class '.$class);
    }

    return $class;
}

/**
 * One JSON request through the real kernel, to the `dto()` of a generated request.
 *
 * @param  class-string  $request
 * @param  array<string, mixed>  $payload
 */
function dtoOfPost(string $request, array $payload): object
{
    $dto = null;
    $route = '/_generated/'.md5($request);

    Route::post($route, function () use ($request, &$dto) {
        $dto = app($request)->dto();

        return response()->json([]);
    });

    $response = app(HttpKernel::class)->handle(Request::create(
        $route,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        (string) json_encode($payload),
    ));

    if (! is_object($dto)) {
        throw new RuntimeException(sprintf('`%s` refused the payload with a %d: %s', $request, $response->getStatusCode(), $response->getContent()));
    }

    return $dto;
}

/**
 * What a DTO holds at the end of a path of property names and list positions.
 */
function dtoAt(object $dto, string|int ...$path): mixed
{
    $value = $dto;

    foreach ($path as $step) {
        $value = match (true) {
            is_array($value) => $value[$step],
            is_object($value) => $value->{$step},
            default => throw new RuntimeException(sprintf('Nothing at `%s` in %s.', $step, implode('.', $path))),
        };
    }

    return $value;
}

// The request validates down to the depth the schema states, so the DTO is one class at every level. `Address` is not
// nullable and states no depth (three), so the key below the last level is absent: `Optional`. The two shapes the
// golden files do not hold are built from the fixture: a nullable schema, where `null` is the value that ends a tree,
// and a list, where an empty one does.
it('builds nested input DTOs down to the depth', function (): void {
    $namespace = 'Gcob\\LaraSpecFirst\\Tests\\Generated\\RecursionDepth';
    generatedFrom('recursion-dto-depth.yaml', $namespace);
    $tree = generatedClass($namespace, 'Requests\\CreateTreeRequest');
    $node = generatedClass($namespace, 'Data\\NodeInputDto');
    $category = generatedClass($namespace, 'Data\\CategoryInputDto');

    $user = dtoOfPost(UpdateUserRequest::class, ['address' => addressDeep(3)]);

    expect(get_debug_type(dtoAt($user, 'address')))->toBe(AddressInputDto::class)
        ->and(get_debug_type(dtoAt($user, 'address', 'parent')))->toBe(AddressInputDto::class)
        ->and(get_debug_type(dtoAt($user, 'address', 'parent', 'parent')))->toBe(AddressInputDto::class)
        ->and(dtoAt($user, 'address', 'parent', 'parent', 'parent'))->toBeInstanceOf(AddressInputDto::class)
        ->and(dtoAt($user, 'address', 'parent', 'parent', 'parent', 'street'))->toBe('level 3')
        // The key below the last level the request validates.
        ->and(dtoAt($user, 'address', 'parent', 'parent', 'parent', 'parent'))->toBeInstanceOf(Optional::class);

    $ended = dtoOfPost($tree, ['tree' => ['label' => 'a', 'next' => ['label' => 'b', 'next' => null]]]);

    expect(get_debug_type(dtoAt($ended, 'tree')))->toBe($node)
        ->and(get_debug_type(dtoAt($ended, 'tree', 'next')))->toBe($node)
        ->and(dtoAt($ended, 'tree', 'next', 'label'))->toBe('b')
        ->and(dtoAt($ended, 'tree', 'next', 'next'))->toBeNull();

    $absent = dtoOfPost($tree, ['tree' => ['label' => 'a', 'next' => ['label' => 'b']]]);

    expect(dtoAt($absent, 'tree', 'next', 'next'))->toBeInstanceOf(Optional::class);

    $categories = dtoOfPost(generatedClass($namespace, 'Requests\\CreateCategoryRequest'), [
        'name' => 'a',
        'children' => [['name' => 'b', 'children' => [['name' => 'c', 'children' => []]]]],
    ]);

    expect(get_debug_type($categories))->toBe($category)
        ->and(get_debug_type(dtoAt($categories, 'children', 0)))->toBe($category)
        ->and(get_debug_type(dtoAt($categories, 'children', 0, 'children', 0)))->toBe($category)
        ->and(dtoAt($categories, 'children', 0, 'children', 0, 'children'))->toBe([]);
});

// The ceiling lowers a depth the contract states (20 on a binary tree) to the one the rules fit (7 crossings, see
// `RuleSetBuilderTest`), and the DTO is typed down to that applied depth, on both sides: the last level the request
// validates is a `TreeInputDto`, and the key below it is the cut.
it('types a tree whose depth was lowered down to the applied depth', function (string $side): void {
    $namespace = 'Gcob\\LaraSpecFirst\\Tests\\Generated\\TreeCeiling';
    generatedFrom('recursion-binary-tree-deep.yaml', $namespace);
    $tree = generatedClass($namespace, 'Data\\TreeInputDto');

    $payload = ['value' => 'level 7'];

    for ($level = 6; $level >= 0; $level--) {
        $payload = ['value' => 'level '.$level, $side => $payload];
    }

    $dto = dtoOfPost(generatedClass($namespace, 'Requests\\CreateTreeRequest'), $payload);

    for ($level = 1; $level <= 7; $level++) {
        $path = array_fill(0, $level, $side);

        expect(get_debug_type(dtoAt($dto, ...$path)))->toBe($tree)
            ->and(dtoAt($dto, ...[...$path, 'value']))->toBe('level '.$level);
    }

    expect(dtoAt($dto, ...array_fill(0, 8, $side)))->toBeInstanceOf(Optional::class);
})->with(['left', 'right']);

// A recursive pair is entered from both sides, by two operations. The build is run in both orders and the classes of each
// are loaded and executed: the same bytes, and `dto()` reads each side of the pair as the other's class.
it('executes a recursive pair the same in both orders', function (): void {
    $authorsFirst = 'Gcob\\LaraSpecFirst\\Tests\\Generated\\PairAuthorsFirst';
    $booksFirst = 'Gcob\\LaraSpecFirst\\Tests\\Generated\\PairBooksFirst';

    $first = generatedFrom('recursion-pair.yaml', $authorsFirst);
    $second = generatedFrom('recursion-pair.yaml', $booksFirst, reversed: true);

    expect(array_keys($first))->toBe([
        'Data/AuthorInputDto.php',
        'Data/AuthorPartialInputDto.php',
        'Data/BookInputDto.php',
        'Data/BookPartialInputDto.php',
        'Requests/CreateAuthorRequest.php',
        'Requests/CreateBookRequest.php',
    ])
        ->and(str_replace($booksFirst, $authorsFirst, implode("\n", $second)))->toBe(implode("\n", $first));

    foreach ([$authorsFirst, $booksFirst] as $namespace) {
        $author = generatedClass($namespace, 'Data\\AuthorInputDto');
        $book = generatedClass($namespace, 'Data\\BookInputDto');

        $fromAuthor = dtoOfPost(generatedClass($namespace, 'Requests\\CreateAuthorRequest'), [
            'name' => 'Ada',
            'books' => [['title' => 'Notes', 'author' => ['name' => 'Charles', 'books' => []]]],
        ]);

        expect(get_debug_type($fromAuthor))->toBe($author)
            ->and(get_debug_type(dtoAt($fromAuthor, 'books', 0)))->toBe($book)
            ->and(get_debug_type(dtoAt($fromAuthor, 'books', 0, 'author')))->toBe($author)
            ->and(dtoAt($fromAuthor, 'books', 0, 'author', 'books'))->toBe([]);

        $fromBook = dtoOfPost(generatedClass($namespace, 'Requests\\CreateBookRequest'), [
            'title' => 'Notes',
            'author' => ['name' => 'Ada', 'books' => [['title' => 'More']]],
        ]);

        expect(get_debug_type($fromBook))->toBe($book)
            ->and(get_debug_type(dtoAt($fromBook, 'author')))->toBe($author)
            ->and(get_debug_type(dtoAt($fromBook, 'author', 'books', 0)))->toBe($book)
            ->and(dtoAt($fromBook, 'author', 'books', 0, 'author'))->toBeInstanceOf(Optional::class);
    }
});

it('refuses a node that is wrong at the last level the schema validates', function (): void {
    $payload = ['address' => addressDeep(2)];
    $payload['address']['parent']['parent']['parent'] = 'oops';

    [$status, $body] = patchUser($payload);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['address.parent.parent.parent']);
});

// A deeper payload is stricter than the contract, which is what the finding in the generated file says.
it('refuses a payload deeper than the depth, on the first field below it', function (): void {
    [$status, $body] = patchUser(['address' => addressDeep(4)]);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['address.parent.parent.parent.parent']);
});

// Laravel's `missing` asks for the key to be absent, so an empty value is refused at the cut as well: a
// typed DTO is never built from `[]` below the depth. `prohibited` let all three through.
it('refuses an empty value at the depth as it refuses any other', function (mixed $empty): void {
    $payload = ['address' => addressDeep(3)];
    $payload['address']['parent']['parent']['parent']['parent'] = $empty;

    [$status, $body] = patchUser($payload);

    expect($status)->toBe(422)
        ->and(array_keys((array) ($body['errors'] ?? [])))->toBe(['address.parent.parent.parent.parent']);
})->with([
    'null' => [null],
    'an empty string' => [''],
    'an empty list' => [[]],
]);
