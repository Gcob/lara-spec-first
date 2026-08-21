<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\RemoteReferences;

use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceFetchException;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * The one place `--update-refs` reaches the network.
 *
 * Wraps `Illuminate\Http\Client\Factory` — available in any application this
 * package can be installed into — rather than the `Http` facade, so this stays
 * a plain object a unit test can construct and `Http::fake()` still intercepts
 * it, since the facade and this factory share the same underlying instance.
 *
 * **Redirects are never followed.** The host on the URL written in the
 * document is the one thing the allowlist checked, and a client that follows a
 * redirect fetches whatever a `3xx` response names instead — an allowed host
 * that answers `302 Location: http://169.254.169.254/…`, or any other host,
 * would have its response committed under the *allowed* host's directory,
 * where a reviewer reading the diff has no way to tell it came from somewhere
 * else. A reference that moved is a reference to rewrite in the document, not
 * one to follow silently.
 *
 * @internal Not public API — a detail of how vendoring fetches a reference.
 */
final readonly class RemoteReferenceFetcher
{
    public function __construct(private Factory $http = new Factory) {}

    /**
     * @throws RemoteReferenceFetchException the request failed, redirected, or
     *                                       the server did not answer with success
     */
    public function fetch(string $url): string
    {
        try {
            $response = $this->http->withoutRedirecting()->get($url);
        } catch (Throwable $failure) {
            throw RemoteReferenceFetchException::failed($url, $failure->getMessage());
        }

        if ($response->redirect()) {
            throw RemoteReferenceFetchException::failed($url, sprintf(
                'the server redirected (%d) to "%s" instead of answering — '.
                'point the reference at that URL directly if it is the one you want',
                $response->status(),
                $response->header('Location') ?: 'an unspecified location',
            ));
        }

        if (! $response->successful()) {
            throw RemoteReferenceFetchException::failed(
                $url,
                sprintf('the server responded %d', $response->status())
            );
        }

        return $response->body();
    }
}
