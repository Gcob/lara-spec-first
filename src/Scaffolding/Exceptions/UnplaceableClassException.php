<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * The contract names a class in a namespace this project does not map.
 *
 * A refusal rather than a guess, and the guess is the tempting part: a path could
 * always be invented from the namespace by convention. It would produce a file
 * that compiles, that the autoloader never finds, and whose route therefore
 * answers with a class-not-found — for a reason nothing in the project states.
 *
 * @see docs/guide/code-generation/scaffolding.md — "Where your classes go"
 */
final class UnplaceableClassException extends RuntimeException implements SpecException
{
    public static function forClass(string $identity, string $class): self
    {
        return new self(sprintf(
            '`x-controller` on "%s" names "%s", and no PSR-4 prefix in this project maps that '.
            'namespace — so there is nowhere to put the file where the autoloader would find it. '.
            'Point `x-controller` at a namespace your composer.json maps, or map this one.',
            $identity,
            $class
        ));
    }
}
