<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Generation\RouteActionSignature;

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
