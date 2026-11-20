<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\RuleSetOptions;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * `dto()` and the arrays Laravel's `validated()` leaves out.
 *
 * By default `validated()` drops an array that has child rules when none of its
 * declared children was sent, so `{"settings": {}}` passes `present|array` and then
 * carries no `settings` at all: a required property `from()` reads as an undefined
 * key, a list of objects that loses every element with no declared key, an optional
 * object reported as not sent. The classes under test are generated from
 * tests/Fixtures/declared-arrays.yaml by the real build and loaded for the test,
 * because the contract that exposes this is not one a committed golden file needs.
 *
 * @see docs/guide/code-generation/request-validation.md — "The payload arrives as a DTO"
 */

const DECLARED_ARRAYS_NAMESPACE = 'Gcob\\LaraSpecFirst\\Tests\\Generated\\DeclaredArrays';

/**
 * Generate the fixture's requests and DTOs, write them under a temporary directory and load them. Once per run: the
 * second call finds the classes declared.
 */
function loadDeclaredArrays(): void
{
    if (class_exists(DECLARED_ARRAYS_NAMESPACE.'\\Requests\\CreateAccountRequest', false)) {
        return;
    }

    $plan = (new BuildPlanner(DECLARED_ARRAYS_NAMESPACE, 'tests/Fixtures/declared-arrays.yaml', new RuleSetOptions))
        ->plan(extractFixture('declared-arrays.yaml'));

    $directory = sys_get_temp_dir().'/lara-spec-first-declared-arrays-'.bin2hex(random_bytes(4));

    register_shutdown_function(static function () use ($directory): void {
        foreach (glob($directory.'/*/*.php') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($directory.'/*') ?: [] as $folder) {
            rmdir($folder);
        }

        rmdir($directory);
    });

    foreach ($plan->files as $file) {
        if (! str_starts_with($file->relativePath, 'Data/') && ! str_starts_with($file->relativePath, 'Requests/')) {
            continue;
        }

        if (! is_dir(dirname($directory.'/'.$file->relativePath))) {
            mkdir(dirname($directory.'/'.$file->relativePath), 0777, true);
        }

        file_put_contents($directory.'/'.$file->relativePath, $file->contents);
        require_once $directory.'/'.$file->relativePath;
    }
}

beforeEach(function (): void {
    loadDeclaredArrays();

    // Resolved from the container inside the body, because the classes do not exist
    // until the test loads them: it validates the same way a typed parameter does.
    foreach (['post' => ['/_generated/accounts', 'CreateAccountRequest'], 'patch' => ['/_generated/accounts/{id}', 'UpdateAccountRequest']] as $method => [$uri, $class]) {
        Route::$method($uri, function () use ($class): JsonResponse {
            $request = app(DECLARED_ARRAYS_NAMESPACE.'\\Requests\\'.$class);

            return response()->json(['dto' => $request->dto()->toArray(), 'validated' => $request->validated()]);
        });
    }
});

/**
 * One JSON request through the real kernel.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: int, 1: array<string, mixed>}
 */
function sendToAccounts(array $payload, string $method = 'POST'): array
{
    $response = app(HttpKernel::class)->handle(Request::create(
        '/_generated/accounts'.($method === 'PATCH' ? '/1' : ''),
        $method,
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

// A required object sent as `{}` passes `present|array`, and `from()` read a `settings`
// `validated()` had left out: a 500 on a payload the contract allows.
it('accepts a required object sent empty', function (): void {
    [$status, $body] = sendToAccounts(['settings' => [], 'widgets' => []]);

    expect($status)->toBe(200)
        ->and($body['dto'])->toBe(['settings' => [], 'widgets' => []]);
});

it('accepts a required object holding only keys the contract does not declare', function (): void {
    [$status, $body] = sendToAccounts(['settings' => ['unknown' => 1], 'widgets' => []]);

    expect($status)->toBe(200)
        ->and($body['dto'])->toBe(['settings' => [], 'widgets' => []]);
});

// An optional `{}` became `Optional`, which tells the controller the client did not send it.
it('tells an optional object sent empty from one not sent', function (): void {
    [, $sent] = sendToAccounts(['settings' => [], 'widgets' => [], 'extras' => []]);
    [, $notSent] = sendToAccounts(['settings' => [], 'widgets' => []]);

    expect($sent['dto'])->toHaveKey('extras', [])
        ->and($notSent['dto'])->not->toHaveKey('extras');
});

// A list of objects with no declared key lost its elements, and a list of such lists is the same.
it('keeps every element of a list of objects sent empty', function (): void {
    [$status, $body] = sendToAccounts(['settings' => [], 'widgets' => [[], [], []]]);

    expect($status)->toBe(200)
        ->and($body['dto']['widgets'])->toBe([[], [], []]);
});

it('keeps the elements that hold keys beside the ones that hold none', function (): void {
    [, $body] = sendToAccounts(['settings' => [], 'widgets' => [[], ['label' => 'a'], []]]);

    expect($body['dto']['widgets'])->toBe([[], ['label' => 'a'], []]);
});

it('keeps an empty object a PATCH sent', function (): void {
    [$status, $body] = sendToAccounts(['settings' => [], 'widgets' => []], 'PATCH');

    expect($status)->toBe(200)
        ->and($body['dto'])->toBe(['settings' => [], 'widgets' => []]);
});

// What `validated()` left out keeping its place must not let an undeclared key into the DTO.
it('leaves a key the contract does not declare out of the DTO', function (): void {
    [$status, $body] = sendToAccounts([
        'name' => 'Ada',
        'unknown' => 1,
        'settings' => ['theme' => 'dark', 'unknown' => 2],
        'widgets' => [['label' => 'a', 'unknown' => 3], ['unknown' => 4]],
    ]);

    expect($status)->toBe(200)
        ->and($body['dto'])->toBe([
            'settings' => ['theme' => 'dark'],
            'widgets' => [['label' => 'a'], []],
            'name' => 'Ada',
        ]);
});

// `validated()` is Laravel's method and keeps its default, before and after `dto()` ran.
it('leaves validated() the way Laravel returns it', function (): void {
    [, $body] = sendToAccounts([
        'settings' => ['theme' => 'dark', 'unknown' => 2],
        'extras' => [],
        'widgets' => [],
    ]);

    expect($body['validated'])->toBe(['settings' => ['theme' => 'dark'], 'widgets' => []]);
});
