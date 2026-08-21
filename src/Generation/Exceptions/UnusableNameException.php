<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Contract\PathTemplate;
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

    /**
     * Two `x-controller` values that reduce to one generated parent.
     *
     * Its own message rather than the collision above, because the fix is a
     * different one: these are names a developer chose, and the advice to change
     * an `operationId` would send them looking for a key neither operation has.
     *
     * @see docs/guide/controllers.md — "Two classes, found by name rather than by a scan"
     */
    public static function parentClaimedTwice(string $shortName, string $first, string $second): self
    {
        return new self(sprintf(
            '`x-controller` names "%s" on one operation and "%s" on another, and both generate the '.
            'same parent "%s" in the generated tree. Two operations cannot share one generated '.
            'parent, so give one of them a class whose short name differs.',
            $first,
            $second,
            $shortName
        ));
    }

    /**
     * A custom controller declared inside the generated tree.
     *
     * @see docs/guide/code-generation.md — "Where your classes go"
     */
    public static function customControllerInsideGeneratedTree(
        string $identity,
        string $custom,
        string $namespace,
    ): self {
        return new self(sprintf(
            '`x-controller` on "%s" names "%s", which is inside the generated namespace `%s`. The '.
            'generated parent takes that same short name there, so the class would extend itself — '.
            'and a build rewrites everything under that namespace, so your work would not survive '.
            'one. Name a class in your application instead.',
            $identity,
            $custom,
            $namespace
        ));
    }

    /**
     * A path parameter the generated method cannot declare.
     *
     * `routeAction` takes one parameter per path parameter, named the way the
     * document names it, so a name that is legal in a route and illegal as a PHP
     * variable has to be refused rather than written into a file that will not
     * parse.
     *
     * One path naming the same parameter twice needs no factory here: a
     * {@see PathTemplate} refuses that where the
     * path is read, before anything asks what could be generated from it.
     *
     * @see docs/guide/controllers.md — "One controller per operation, one method named routeAction"
     */
    public static function parameterNotAVariable(string $identity, string $parameter): self
    {
        return new self(sprintf(
            'The operation "%s" has the path parameter `{%s}`, which cannot be a PHP variable — so '.
            'the generated `routeAction` could not declare it. Rename it in the specification to '.
            'something starting with a letter or an underscore, and not `this`.',
            $identity,
            $parameter
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
