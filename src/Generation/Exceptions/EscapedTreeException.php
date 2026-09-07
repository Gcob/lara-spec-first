<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use LogicException;

/**
 * A file the build was about to write outside the directory it owns.
 *
 * A `LogicException` because it can only ever be our own bug: the paths reaching
 * the writer are built by this package, never by a consumer, so there is no
 * configuration that produces this and nothing a user can do about it. It exists
 * so that the invariant is enforced at the boundary rather than trusted across
 * it — a rule nothing checks is a rule until the first refactor.
 *
 * @see docs/guide/code-generation/index.md — "The invariant: a build never destroys human work"
 */
final class EscapedTreeException extends LogicException implements SpecException
{
    public static function relativePath(string $relative): self
    {
        return new self(sprintf(
            'The build refused to write "%s": a generated path must stay inside the generated tree, '.
            'and this one is absolute or climbs out of it. This is a defect in this package rather '.
            'than in your configuration.',
            $relative
        ));
    }
}
