<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * A chain of vendored remote references closes back on a URL it already fetched.
 *
 * The existing cycle detector only ever inspects the root document, once,
 * before any fetching starts — it cannot see a cycle that only exists across
 * documents this package fetches itself. Vendoring recurses into every fetched
 * document looking for further remote references, so it is the one place such a
 * cycle could otherwise recurse forever instead of raising.
 *
 * @see CyclicReferenceException the local-reference equivalent this mirrors
 */
final class CircularRemoteReferenceException extends RuntimeException implements SpecException
{
    /**
     * @param  non-empty-list<string>  $chain  the URLs followed, ending where it started
     */
    public static function chain(array $chain): self
    {
        return new self(sprintf(
            'The remote reference %s closes a cycle: %s. A vendored document cannot refer back, '.
            'directly or transitively, to a URL already being fetched in the same chain.',
            $chain[0],
            implode(' -> ', $chain)
        ));
    }
}
