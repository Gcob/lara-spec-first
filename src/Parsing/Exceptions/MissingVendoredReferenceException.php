<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * An allowed remote reference has no vendored copy, and the build was not asked
 * to fetch one.
 *
 * The build is frozen by default: a missing vendored copy is an error naming the
 * flag to run, never an implicit fetch. Once a copy exists, this exception never
 * fires again for that reference — resolution reads the committed file instead.
 *
 * @see docs/guide/remote-references.md — "A remote reference is a dependency, not a cache entry"
 * @see docs/guide/code-generation/index.md — "Remote references during a build: frozen by default"
 */
final class MissingVendoredReferenceException extends RuntimeException implements SpecException
{
    public static function notVendored(string $reference, string $expectedPath): self
    {
        return new self(sprintf(
            'The document refers to "%s", which is allowed but has never been vendored. '.
            'Expected a committed copy at "%s". Run `php artisan spec:build --update-refs` to fetch '.
            'it and commit the copy — the build never reaches the network on its own.',
            $reference,
            $expectedPath
        ));
    }
}
