<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * A scaffold could not be created, and the failure is named rather than returned.
 *
 * The same reasoning as the generated tree's write failures: a permission problem
 * that surfaces as a PHP warning and a half-finished run is worse than a message
 * naming the path.
 */
final class UnwritableScaffoldException extends RuntimeException implements SpecException
{
    public static function directory(string $directory): self
    {
        return new self(sprintf(
            'Cannot create the directory %s for a scaffolded controller.',
            $directory
        ));
    }

    public static function file(string $path): self
    {
        return new self(sprintf('Cannot write the scaffolded controller %s.', $path));
    }

    /**
     * A file appeared between planning and writing.
     *
     * **The one refusal this class exists for**, rather than a race worth
     * ignoring: `spec:make` creates files a developer owns, so overwriting one is
     * the single thing it must never do — and the check that prevents it has to
     * be the one immediately before the write, not the one in the plan.
     */
    public static function alreadyThere(string $path): self
    {
        return new self(sprintf(
            'The file %s appeared after this run planned to create it, so nothing was written. '.
            'A file you own is never overwritten.',
            $path
        ));
    }
}
