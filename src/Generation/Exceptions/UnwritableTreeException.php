<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * The build could not write where it was told to.
 *
 * A `RuntimeException` where its neighbour {@see EscapedTreeException} is a
 * `LogicException`, and the split is whose problem it is: that one can only be
 * our own bug, this one is the environment — a directory owned by another user, a
 * read-only mount, a full disk.
 *
 * **It exists because the alternative was silence.** PHP's `mkdir` and
 * `file_put_contents` report failure by returning `false` and emitting a warning,
 * and a build that ignores both counts a file as written that is not on disk. It
 * then reports success, exits zero, and leaves an application whose routes file
 * describes a contract nothing serves. That is precisely the shape of failure this
 * package exists to prevent, so a write it cannot perform has to stop the build.
 *
 * @see docs/guide/code-generation.md — "The build command: spec:build"
 */
final class UnwritableTreeException extends RuntimeException implements SpecException
{
    public static function directory(string $path): self
    {
        return new self(sprintf(
            'The build could not create the directory "%s". Check that the generated tree is '.
            'writable by the user running this command: a tree created by another user — root in a '.
            'container, or a deploy step — is the usual cause.',
            $path
        ));
    }

    public static function file(string $path): self
    {
        return new self(sprintf(
            'The build could not write "%s". Check that the file and its directory are writable by '.
            'the user running this command. Nothing further was written, so the generated tree is '.
            'as it was before this run.',
            $path
        ));
    }

    public static function staleFile(string $path): self
    {
        return new self(sprintf(
            'The build could not remove "%s", which the specification no longer describes. Leaving '.
            'it would mean the generated tree still carries a class for an operation that is gone, '.
            'so this stops rather than reporting a build that only partly happened.',
            $path
        ));
    }
}
