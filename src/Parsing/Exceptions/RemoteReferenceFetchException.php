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
 * or the document, then run it again: a non-2xx response (including a
 * redirect, which this package refuses to follow), a transport failure (DNS,
 * TLS, timeout), and a response that does not decode as YAML or JSON.
 *
 * @see docs/guide/remote-references.md
 */
final class RemoteReferenceFetchException extends RuntimeException implements SpecException
{
    /**
     * The fetch itself never happened, or its result could not be trusted —
     * "nothing was written for this reference" rather than "nothing was
     * vendored or overwritten", because the claim has to hold in the
     * transitive case too: an outer document can already be committed by the
     * time an inner reference fails this way.
     */
    public static function failed(string $url, string $reason): self
    {
        return new self(sprintf(
            'Fetching "%s" for `--update-refs` failed: %s. Nothing was written for this reference.',
            $url,
            $reason
        ));
    }

    /**
     * The fetch succeeded, but writing what came back did not — a distinct
     * factory rather than reusing `failed()`, because naming a filesystem
     * path as if it were the URL that failed sends the reader looking at the
     * network for what is actually a disk problem.
     */
    public static function notWritten(string $path, string $reason): self
    {
        return new self(sprintf(
            'Writing the vendored copy at "%s" failed: %s.',
            $path,
            $reason
        ));
    }
}
