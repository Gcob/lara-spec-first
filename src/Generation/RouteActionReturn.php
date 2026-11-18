<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * What `routeAction` returns, worked out once from what the response plan says each status answers with.
 *
 * **One value for every reader.** The generated parent declares it, a scaffolded child has to declare the same or PHP
 * refuses the override, and the finding in the controller explains it. Three derivations are three chances to disagree,
 * so the type, its docblock, the classes it needs imported and the reasons behind it are all here.
 *
 * **The success statuses decide it, and the error ones never do.** An error leaves a controller as an exception
 * (`abort()`, the handler), Laravel's way, so a `4xx` DTO is not something `routeAction` returns. A DTO or a list of
 * them is a member of the type for each success status that has one; `null` is a member where a body may be `null`.
 *
 * **One response class is admitted, and only where the contract needs it.** Several success statuses need a way to pick
 * the one a request gets, which is a `JsonResponse` carrying its own status. A contract that declares an answer that is
 * not a JSON body (an empty one, another media type, a redirect) needs a `Response`, which is also what `noContent()`
 * and `redirect()` build. `Response` admits a `JsonResponse` too, so the two never appear together. Nothing typed at
 * all keeps `mixed`, which is what the build wrote before a response had a DTO.
 *
 * @internal Not public API: what {@see RouteActionSignature} hands the controller and the scaffold.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */
final readonly class RouteActionReturn
{
    /**
     * @param  string  $type  the native return type, as PHP writes it
     * @param  string|null  $tag  the `@return` type, written only where the native type says less than the contract: a
     *                            list's items, or the DTO an `array` holds
     * @param  list<string>  $imports  every class the type and the tag name, fully qualified and without a leading
     *                                 separator, each once
     * @param  string|null  $admitted  the response class the type admits beside the DTOs, fully qualified, or null
     * @param  list<string>  $answered  the statuses that make the admitted class a `Response`, as the document writes
     *                                  them: the success ones that are not a JSON body, and any `3xx`
     * @param  list<string>  $dtoStatuses  the success statuses that have a DTO, in contract order
     */
    private function __construct(
        public string $type,
        public ?string $tag,
        public array $imports,
        public ?string $admitted,
        public array $answered,
        public array $dtoStatuses,
    ) {}

    /**
     * What `routeAction` returns when nothing the contract declares for a success status has a DTO.
     */
    public static function untyped(): self
    {
        return new self('mixed', null, [], null, [], []);
    }

    /**
     * @param  list<StatusResponse>  $responses  what each declared status answers with, in contract order
     * @param  string  $namespace  the configured generated root namespace, which holds `Data\`
     */
    public static function for(Operation $operation, array $responses, string $namespace): self
    {
        $success = self::successStatuses($responses);
        $typed = array_values(array_filter(
            $success,
            static fn (StatusResponse $status): bool => $status->dto !== null,
        ));

        if ($typed === []) {
            return self::untyped();
        }

        $native = [];
        $tags = [];
        $imports = [];
        $nullable = false;

        foreach ($typed as $status) {
            $dto = (string) $status->dto;
            $imports[] = trim($namespace, '\\').'\\Data\\'.$dto;
            $nullable = $nullable || $status->nullable;

            if ($status->depth === 0) {
                $native[] = $dto;
                $tags[] = $dto;

                continue;
            }

            $native[] = 'array';
            $tags[] = self::listOf($dto, $status->depth, $status->itemsNullable);
        }

        $answered = self::answered($operation, $success);
        $admitted = $answered !== []
            ? SymfonyResponse::class
            : (count($success) > 1 ? JsonResponse::class : null);

        $members = array_values(array_unique($native));
        $tagMembers = array_values(array_unique($tags));

        if ($admitted !== null) {
            $short = self::shortName($admitted);
            $members[] = $short;
            $tagMembers[] = $short;
            $imports[] = $admitted;
        }

        $type = $nullable && count($members) === 1
            ? '?'.$members[0]
            : implode('|', [...$members, ...($nullable ? ['null'] : [])]);

        return new self(
            $type,
            in_array('array', $native, true) ? implode('|', [...$tagMembers, ...($nullable ? ['null'] : [])]) : null,
            array_values(array_unique($imports)),
            $admitted,
            $answered,
            array_map(static fn (StatusResponse $status): string => $status->status, $typed),
        );
    }

    /**
     * Whether the type is anything better than `mixed`.
     */
    public function isTyped(): bool
    {
        return $this->type !== 'mixed';
    }

    /**
     * The statuses that count as success: every `2xx` and `2XX`, or `default` only when none of them is declared.
     *
     * @param  list<StatusResponse>  $responses
     * @return list<StatusResponse>
     */
    private static function successStatuses(array $responses): array
    {
        $success = array_values(array_filter(
            $responses,
            static fn (StatusResponse $status): bool => preg_match('/^2(\d\d|XX)$/i', $status->status) === 1,
        ));

        if ($success !== []) {
            return $success;
        }

        return array_values(array_filter(
            $responses,
            static fn (StatusResponse $status): bool => strtolower($status->status) === 'default',
        ));
    }

    /**
     * The statuses of the contract that only a response object can answer: a success status with no JSON body only, or
     * with a media type that is not JSON beside it, and any `3xx`.
     *
     * Read from the contract rather than from the response plan, which says a status has no DTO and not why: a `201`
     * whose JSON schema is at fault has none and is still JSON, while a `204` has none and is not.
     *
     * @param  list<StatusResponse>  $success
     * @return list<string>
     */
    private static function answered(Operation $operation, array $success): array
    {
        $successStatuses = array_map(static fn (StatusResponse $status): string => $status->status, $success);
        $found = [];

        foreach ($operation->responses as $response) {
            $isRedirect = preg_match('/^3(\d\d|XX)$/i', $response->status) === 1;

            if ($isRedirect || (in_array($response->status, $successStatuses, true) && self::offersNoJsonBody($response))) {
                $found[] = $response->status;
            }
        }

        return $found;
    }

    /**
     * Whether a response declares something a DTO cannot be: no schema at all, or a media type that is not JSON.
     */
    private static function offersNoJsonBody(Response $response): bool
    {
        return count(ResponseDtoPlanner::jsonSchemas($response)) < count($response->content)
            || $response->content === [];
    }

    private static function listOf(string $dto, int $depth, bool $itemsNullable): string
    {
        $type = $itemsNullable ? $dto.'|null' : $dto;

        for ($level = 1; $level <= $depth; $level++) {
            $type = 'list<'.$type.'>';

            // One flag speaks for every level, so a list of lists with nullable items writes `|null` at each level:
            // looser than the contract at worst, never stricter.
            if ($level < $depth && $itemsNullable) {
                $type .= '|null';
            }
        }

        return $type;
    }

    private static function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
