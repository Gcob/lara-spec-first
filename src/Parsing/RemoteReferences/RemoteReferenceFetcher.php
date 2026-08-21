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
 * @internal Not public API — a detail of how vendoring fetches a reference.
 */
final readonly class RemoteReferenceFetcher
{
    public function __construct(private Factory $http = new Factory) {}

    /**
     * @throws RemoteReferenceFetchException the request failed, or the server
     *                                       did not answer with success
     */
    public function fetch(string $url): string
    {
        try {
            $response = $this->http->get($url);
        } catch (Throwable $failure) {
            throw RemoteReferenceFetchException::failed($url, $failure->getMessage());
        }

        if ($response->failed()) {
            throw RemoteReferenceFetchException::failed(
                $url,
                sprintf('the server responded %d', $response->status())
            );
        }

        return $response->body();
    }
}
