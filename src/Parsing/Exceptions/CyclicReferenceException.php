<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * A `$ref` chain points only at other references and closes back on itself.
 *
 * This is the one document fault the parser cannot survive: it recurses past
 * its own cycle checks and exhausts memory, taking the process down instead of
 * raising. Detecting it is therefore ours, and it has to happen before the
 * document is handed over.
 *
 * @see docs/OPENAPI-SUPPORT.md — "Parser caveats"
 */
final class CyclicReferenceException extends RuntimeException implements SpecException
{
    /**
     * @param  non-empty-list<string>  $chain  the pointers followed, ending where it started
     */
    public static function chain(array $chain): self
    {
        return new self(sprintf(
            'The reference %s closes a cycle: %s. '.
            'A chain of references that never reaches content cannot be resolved — '.
            'one of these must point at a schema rather than at another reference. '.
            '(A schema that refers back to itself, such as a tree, is a different thing and is fine.)',
            $chain[0],
            implode(' -> ', $chain)
        ));
    }
}
