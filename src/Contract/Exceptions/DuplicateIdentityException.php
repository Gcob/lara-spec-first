<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use InvalidArgumentException;

/**
 * Two operations handed to one contract address the same endpoint.
 *
 * The reading pipeline refuses this before it gets here, so in practice this is
 * reached only by a caller assembling a contract itself. It exists anyway
 * because the alternative is a keyed collection quietly keeping the last of the
 * two — and an endpoint disappearing without a word is the failure this package
 * refuses everywhere else, including for the parser's own handling of
 * `components.pathItems`.
 */
final class DuplicateIdentityException extends InvalidArgumentException implements SpecException
{
    public static function forIdentity(string $identity): self
    {
        return new self(sprintf(
            'Two operations address `%s`. A contract keyed by identity cannot hold both, and dropping '.
            'one silently is not something this package will do.',
            $identity
        ));
    }
}
