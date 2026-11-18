<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * One success status an operation declares, and the choice of which one answers what `routeAction` returned.
 *
 * **Written into the generated parent's `callAction()`, one per success status, so a child never has to.** Laravel
 * calls `callAction()` when a controller has it, and what `routeAction` returned is what it is handed, so a child that
 * overrides `routeAction` and never calls `parent::` still gets the status the contract declares. The choice itself is
 * here rather than in every generated file, so it is one piece of code with one set of tests.
 *
 * **A status applies only to a value the contract can describe:** an instance of one of the operation's DTO classes
 * (compared by class, because a `Model` is `JsonSerializable` too and a recently created one must keep the `201`
 * Laravel gives it), an array, a scalar or `null`. A response object, a `Responsable`, a PSR-7 response or any other
 * object is Laravel's to turn into a response, and it is handed back untouched, status included.
 *
 * **Several statuses are told apart by what was returned.** A DTO answers the first status whose body is that class. A
 * list answers the first list status whose items are the class of its first item; an empty list, or a first item no
 * status matches, answers the first list status; with no list status Laravel's own answer stands. `null` answers the
 * first status that may be `null`, as JSON `null`, or Laravel's own answer when none may.
 *
 * **`null` is the literal, never `{}`:** `new JsonResponse(null)` writes an empty object, which is not what a contract
 * that says `null` means.
 *
 * @internal Not public API: what a generated controller calls.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */
final readonly class SuccessStatus
{
    /**
     * @param  int  $code  the number sent: a `2XX` range or a lone `default` is `200`, the one number a controller can
     *                     choose for them
     * @param  class-string|null  $dto  the DTO the body is, or the DTO of each item for a list, or null when the status
     *                                  has none
     * @param  int  $depth  how many lists the DTO is inside: 0 for the body itself
     * @param  bool  $nullable  whether the body itself may be `null`
     * @param  bool  $json  whether the status has a JSON body, which decides how a `null` is written
     */
    public function __construct(
        public int $code,
        public ?string $dto = null,
        public int $depth = 0,
        public bool $nullable = false,
        public bool $json = true,
    ) {}

    /**
     * What `routeAction` returned, with the status of the contract on it, or as it was when nothing here applies.
     *
     * @param  self  ...$statuses  the success statuses of the operation, in the order its contract declares them
     */
    public static function answer(mixed $returned, self ...$statuses): mixed
    {
        $chosen = self::chosen($returned, array_values($statuses));

        return $chosen?->send($returned) ?? $returned;
    }

    /**
     * @param  list<self>  $statuses
     */
    private static function chosen(mixed $returned, array $statuses): ?self
    {
        if ($statuses === []) {
            return null;
        }

        $only = count($statuses) === 1 ? $statuses[0] : null;

        return match (true) {
            $returned === null => $only ?? self::first($statuses, static fn (self $status): bool => $status->nullable),
            is_array($returned) => $only ?? self::listStatus($returned, $statuses),
            is_scalar($returned) => $only,
            is_object($returned) && self::isPlanned($returned, $statuses) => $only ?? self::first(
                $statuses,
                static fn (self $status): bool => $status->depth === 0 && $status->dto !== null && $returned instanceof $status->dto,
            ),
            default => null,
        };
    }

    /**
     * The first list status whose items are the class of the list's first item, or the first list status.
     *
     * @param  array<array-key, mixed>  $returned
     * @param  list<self>  $statuses
     */
    private static function listStatus(array $returned, array $statuses): ?self
    {
        $lists = array_values(array_filter(
            $statuses,
            static fn (self $status): bool => $status->depth >= 1 && $status->dto !== null,
        ));

        $item = $returned;

        while (is_array($item) && $item !== []) {
            $item = reset($item);
        }

        $matching = is_object($item)
            ? self::first($lists, static fn (self $status): bool => $status->dto !== null && $item instanceof $status->dto)
            : null;

        return $matching ?? $lists[0] ?? null;
    }

    /**
     * Whether the object is a body one of the statuses declares, which is the only kind of object a status applies to.
     *
     * @param  list<self>  $statuses
     */
    private static function isPlanned(object $returned, array $statuses): bool
    {
        foreach ($statuses as $status) {
            if ($status->dto !== null && $returned instanceof $status->dto) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<self>  $statuses
     * @param  callable(self): bool  $matches
     */
    private static function first(array $statuses, callable $matches): ?self
    {
        foreach ($statuses as $status) {
            if ($matches($status)) {
                return $status;
            }
        }

        return null;
    }

    private function send(mixed $returned): SymfonyResponse
    {
        if ($returned === null) {
            return $this->json ? JsonResponse::fromJsonString('null', $this->code) : new Response('', $this->code);
        }

        // Laravel's own conversion, so a DTO, an array and a scalar are written exactly as they would have been, and
        // only the number differs.
        return Router::toResponse(request(), $returned)->setStatusCode($this->code);
    }
}
