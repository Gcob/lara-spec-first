<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;
use Illuminate\Support\Str;

/**
 * The stem of a class name an operation carries no name for.
 *
 * Two generators need it — the controller and the request — and one derivation
 * shared between them is the point: two copies would drift, and the first
 * symptom of a drift is `GetUsersIdController` sitting beside
 * `GetUserRequest` with nothing saying they answer the same endpoint.
 *
 * The suffix is the caller's, since that is the only part that differs.
 *
 * @see docs/guide/code-generation/generated-file-anatomy.md — "Deriving a name without operationId"
 */
final readonly class DerivedName
{
    /**
     * The method and the path, in the order a reader scans them.
     *
     * **A path parameter's name is part of it.** A class derived this way is
     * `final` and nothing may extend it, so no import depends on the name and
     * renaming `{id}` to `{userId}` costs nothing — while `GetUsersIdController`
     * tells a reader which endpoint it serves where `GetUsersParamController`
     * does not.
     */
    public static function fromMethodAndPath(Operation $operation): string
    {
        $segments = array_filter(
            explode('/', $operation->path->template),
            static fn (string $segment): bool => $segment !== '',
        );

        $studly = array_map(
            static fn (string $segment): string => Str::studly(trim($segment, '{}')),
            $segments,
        );

        return Str::studly($operation->method->value).implode('', $studly);
    }

    /**
     * The `operationId` as a class-name stem, or null when nothing survives the
     * studly rule.
     *
     * Null rather than the empty string, because the caller's answer differs:
     * an `operationId` of `---` is a refusal for the controller and a fall back
     * to the derived name would be a silent rename. Both callers decide for
     * themselves; this only reports what is left.
     */
    public static function fromOperationId(string $operationId): ?string
    {
        $studly = Str::studly($operationId);

        return $studly === '' ? null : $studly;
    }
}
