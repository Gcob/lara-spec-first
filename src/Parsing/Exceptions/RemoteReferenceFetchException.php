<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * `--update-refs` tried to fetch or vendor a reference and could not.
 *
 * Covers three distinct failures behind one class, because a developer running
 * `--update-refs` reacts to all three the same way — fix the network, the host,
 * or the document, then run it again: a non-2xx response, a transport failure
 * (DNS, TLS, timeout), and a response that does not decode as YAML or JSON.
 *
 * @see docs/guide/remote-references.md
 */
final class RemoteReferenceFetchException extends RuntimeException implements SpecException
{
    public static function failed(string $url, string $reason): self
    {
        return new self(sprintf(
            'Fetching "%s" for `--update-refs` failed: %s. Nothing was vendored or overwritten.',
            $url,
            $reason
        ));
    }
}
