<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use InvalidArgumentException;

/**
 * `spec:make` was given a name or a tag the contract does not carry.
 *
 * Refused rather than treated as an empty selection, because the two mean opposite
 * things to whoever typed it: a command that scaffolded nothing and said
 * "created 0 files" reads as *there was nothing to do*, when what happened is that
 * the name was wrong.
 */
final class NoSuchOperationException extends InvalidArgumentException implements SpecException
{
    public static function named(string $name): self
    {
        return new self(sprintf(
            'No operation in the specification is named "%s". Name it by its `operationId`, or by '.
            'its method and path — `spec:make "get /users/{id}"`.',
            $name
        ));
    }

    public static function tagged(string $tag): self
    {
        return new self(sprintf(
            'No operation in the specification carries the tag "%s". Tags are matched exactly, the '.
            'way the document writes them.',
            $tag
        ));
    }

    public static function emptyContract(): self
    {
        return new self(
            'The specification describes no operation, so there is nothing to scaffold.'
        );
    }
}
