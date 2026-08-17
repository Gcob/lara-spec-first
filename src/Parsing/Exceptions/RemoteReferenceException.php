<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * The document points at a reference this package will not fetch.
 *
 * Not a stopgap: an empty allowlist is the documented default, and "no
 * allowlist means no remote references" is the behaviour that default
 * describes. What is missing is the configuration to widen it, not the rule.
 *
 * @see docs/guide/remote-references.md
 */
final class RemoteReferenceException extends RuntimeException implements SpecException
{
    public static function notAllowed(string $reference): self
    {
        return new self(sprintf(
            'The document refers to "%s", which this package will not fetch. A reference that reaches '.
            'over the network makes a specification file into a network client running with the '.
            'application\'s credentials and network position, so remote references are resolved only '.
            'from an explicitly allowlisted set of hosts — and the allowlist is empty until a project '.
            'declares one. Vendor the document into the repository and refer to the local copy.',
            $reference
        ));
    }
}
