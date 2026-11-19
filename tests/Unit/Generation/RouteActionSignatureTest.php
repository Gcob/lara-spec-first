<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\RouteActionSignature;
use Gcob\LaraSpecFirst\Generation\StatusResponse;

/*
 * The count is the number of parameters `declaration()` writes, asked for by a reader that has no use for the text:
 * the build compares it with a custom controller's own count. The expected numbers are written out, not derived.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */

it('counts the parameters the declaration writes', function (string $template, bool $hasRequest, int $count): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString($template),
        operationId: 'showUser',
    );

    expect(RouteActionSignature::count($operation, $hasRequest))->toBe($count);
})->with([
    'nothing' => ['/users', false, 0],
    'a request alone' => ['/users', true, 1],
    'a path parameter alone' => ['/users/{id}', false, 1],
    'a request and two path parameters' => ['/users/{id}/posts/{post}', true, 3],
]);

/**
 * What the parent declares as `routeAction`'s native return type, with every class named in full, for the statuses
 * given as status, DTO (null for none), depth, whether the body may be null, and the media types the contract writes.
 *
 * @param  array<array{0: string, 1: string|null, 2?: int, 3?: bool, 4?: list<string>}>  $rows
 */
function qualifiedReturnOf(array $rows): string
{
    $declared = [];
    $planned = [];

    foreach ($rows as $row) {
        $media = $row[4] ?? ($row[1] === null ? [] : ['application/json']);
        $declared[] = new Response($row[0], array_fill_keys($media, new Schema(types: [SchemaType::Object])));
        $planned[] = $row[1] === null
            ? StatusResponse::without($row[0], 'it has no DTO')
            : StatusResponse::of($row[0], $row[1], $row[2] ?? 0, $row[3] ?? false);
    }

    $operation = new Operation(
        index: 0,
        method: HttpMethod::Get,
        path: PathTemplate::fromString('/users/{id}'),
        operationId: 'showUser',
        responses: $declared,
    );

    return RouteActionSignature::returns($operation, $planned, 'App\\Http\\Generated\\')->qualified();
}

it('writes the return type with every class in full, so the line loads as pasted', function (array $rows, string $type): void {
    expect(qualifiedReturnOf($rows))->toBe($type);
})->with([
    'nothing typed' => [[['204', null]], 'mixed'],
    'a DTO' => [[['200', 'UserDto']], '\\App\\Http\\Generated\\Data\\UserDto'],
    'a nullable DTO' => [[['200', 'UserDto', 0, true]], '?\\App\\Http\\Generated\\Data\\UserDto'],
    'a list' => [[['200', 'UserDto', 1]], 'array'],
    'a nullable list' => [[['200', 'UserDto', 1, true]], '?array'],
    'a union and the JSON response' => [
        [['200', 'UserDto'], ['202', 'JobDto']],
        '\\App\\Http\\Generated\\Data\\UserDto|\\App\\Http\\Generated\\Data\\JobDto|\\Illuminate\\Http\\JsonResponse',
    ],
    'a DTO beside a list and a nullable root' => [
        [['200', 'UserDto', 0, true], ['201', 'UserDto', 1, true]],
        '\\App\\Http\\Generated\\Data\\UserDto|array|\\Illuminate\\Http\\JsonResponse|null',
    ],
    'a DTO beside an answer with no body' => [
        [['200', 'UserDto'], ['204', null]],
        '\\App\\Http\\Generated\\Data\\UserDto|\\Symfony\\Component\\HttpFoundation\\Response',
    ],
]);
