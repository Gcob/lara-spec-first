<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use InvalidArgumentException;

/**
 * A generated name PHP cannot carry, or two operations claiming one.
 *
 * Both are refusals rather than guesses. Emitting a file whose class name does
 * not parse would turn a contract problem into a syntax error three steps away
 * from its cause, and letting one generated class serve two operations would
 * mean the second silently wins.
 *
 * @see docs/guide/code-generation.md — "Naming, and the rename problem"
 */
final class UnusableNameException extends InvalidArgumentException implements SpecException
{
    public static function forOperation(string $identity, string $candidate): self
    {
        return new self(sprintf(
            'The operation "%s" would generate the class name "%s", which is not a usable PHP '.
            'identifier. Give the operation an `operationId` this package can turn into a class '.
            'name, or rename its path.',
            $identity,
            $candidate
        ));
    }

    public static function emptyOperationId(string $identity, string $operationId): self
    {
        return new self(sprintf(
            'The operation "%s" declares the `operationId` "%s", which leaves nothing behind once '.
            'it is turned into a class name. Every operation whose id reduces to nothing would '.
            'claim the same class, so this is refused rather than guessed at.',
            $identity,
            $operationId
        ));
    }

    public static function claimedTwice(string $shortName, string $first, string $second): self
    {
        return new self(sprintf(
            'The operations "%s" and "%s" both generate the class name "%s". Two operations cannot '.
            'share one generated controller, so give at least one of them an `operationId` that '.
            'does not collide.',
            $first,
            $second,
            $shortName
        ));
    }
}
