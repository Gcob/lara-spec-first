<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Http\SuccessStatus;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\TeamDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\UserDto;
use GuzzleHttp\Psr7\Response as PsrResponse;

/*
 * What `callAction()` leaves alone. A status is sent only for a value the contract can describe, and everything else is
 * Laravel's to turn into a response, so each of these comes back as the very value that went in. What is sent, with the
 * router doing the sending, is asserted through the router in tests/Feature/Generation/GeneratedStatusTest.php.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */

// A status says nothing about a PSR-7 response, a `JsonSerializable` that is not one of the operation's DTOs, or a
// DTO of another operation: Laravel converts or sends each as it is, and its own status stands.
it('hands back untouched an object that is not one of the declared DTOs', function (object $returned): void {
    expect(SuccessStatus::answer($returned, new SuccessStatus(201, UserDto::class)))->toBe($returned);
})->with([
    'a PSR-7 response' => [new PsrResponse(202)],
    'a JsonSerializable that is not a DTO' => [new ArrayObject(['id' => 1])],
    'a DTO of another operation' => [new TeamDto('core')],
]);

it('hands back what several statuses cannot tell apart', function (mixed $returned): void {
    $statuses = [new SuccessStatus(200, UserDto::class), new SuccessStatus(202, TeamDto::class)];

    expect(SuccessStatus::answer($returned, ...$statuses))->toBe($returned);
})->with([
    'a scalar' => ['created'],
    'an array when no status is a list' => [[1, 2]],
    'a null when no status may be null' => [null],
]);

it('hands back what an operation with no status has no status for', function (): void {
    expect(SuccessStatus::answer('created'))->toBe('created');
});
