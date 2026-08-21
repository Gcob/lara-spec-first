<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Support;

/**
 * The one answer to "is this path absolute".
 *
 * Held in one place because it was answered in three, and one of the three was
 * wrong: a check for a leading separator alone treats `C:\specs\api.yaml` as
 * relative and joins it under the application root, producing a path nothing is
 * at. A concept with three implementations has the number of behaviours it has
 * copies.
 *
 * @internal Not public API. A consumer has no reason to ask this.
 */
final readonly class Path
{
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            // A Windows drive letter, which is the case a leading-separator check
            // silently gets wrong rather than loudly failing on.
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
