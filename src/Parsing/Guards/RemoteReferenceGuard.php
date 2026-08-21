<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Parsing\DocumentDecoder;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CircularRemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\MissingVendoredReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceFetchException;
use Gcob\LaraSpecFirst\Parsing\RelativeFilePath;
use Gcob\LaraSpecFirst\Parsing\RemoteReferences\RemoteReferenceFetcher;
use Gcob\LaraSpecFirst\Parsing\RemoteReferences\VendoredReferencePath;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Resolves a `$ref` that would otherwise reach over the network.
 *
 * A disallowed host is refused exactly as before. An allowed one is vendored: a
 * committed copy under `vendor_path`, fetched only when `--update-refs` asks for
 * it, and the reference rewritten to point at that local copy — which is why
 * `cebe\openapi\` never dials out. See
 * [remote-references.md](../../../docs/guide/remote-references.md#how-a-vendored-copy-stays-invisible-to-the-parser)
 * for the mechanism that makes the rewrite invisible to the parser.
 *
 * Runs on the raw document, before the parser sees anything — the same
 * position this class always occupied — and now also on every document it
 * vendors along the way, so a reference inside a fetched document is vendored
 * too, one host allowlist check per hop.
 *
 * @internal Not public API — a step of the read pipeline.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 * @see docs/guide/remote-references.md
 */
final readonly class RemoteReferenceGuard
{
    /**
     * @param  list<string>  $allowedHosts  hosts a project has declared it trusts.
     *                                      Reading it here rather than reaching for
     *                                      Laravel's config keeps this class a plain
     *                                      object that a unit test can construct.
     * @param  ?string  $vendorRoot  absolute path vendored copies are read from and
     *                               written to. Only required once `$allowedHosts`
     *                               is non-empty — an empty allowlist never reaches it.
     */
    public function __construct(
        private array $allowedHosts = [],
        private ?string $vendorRoot = null,
        private RemoteReferenceFetcher $fetcher = new RemoteReferenceFetcher,
    ) {}

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed> the same document, every allowed remote
     *                                 `$ref` rewritten to point at its vendored copy
     *
     * @throws RemoteReferenceException a host is not allowed
     * @throws MissingVendoredReferenceException an allowed reference has no vendored
     *                                           copy and `$updateRefs` is false
     * @throws RemoteReferenceFetchException fetching or decoding a reference failed
     * @throws CircularRemoteReferenceException a chain of vendored references closes
     *                                          back on a URL already being fetched
     */
    public function resolve(array $node, string $baseDir, bool $updateRefs = false): array
    {
        return $this->walk($node, $baseDir, $updateRefs, []);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $chain  URLs currently being resolved, root to here
     * @return array<array-key, mixed>
     */
    private function walk(array $node, string $baseDir, bool $updateRefs, array $chain): array
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && self::reachesOverTheNetwork($value)) {
                $node[$key] = $this->vendor($value, $baseDir, $updateRefs, $chain);

                continue;
            }

            if (is_array($value)) {
                $node[$key] = $this->walk($value, $baseDir, $updateRefs, $chain);
            }
        }

        return $node;
    }

    /**
     * Vendor one remote reference and return what should replace it: a path to
     * the local copy, relative to the document that named it, with the
     * original fragment reattached.
     *
     * @param  list<string>  $chain
     *
     * @throws RemoteReferenceException
     * @throws MissingVendoredReferenceException
     * @throws RemoteReferenceFetchException
     * @throws CircularRemoteReferenceException
     */
    private function vendor(string $reference, string $baseDir, bool $updateRefs, array $chain): string
    {
        [$url, $fragment] = self::splitFragment($reference);
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        if (! in_array($host, $this->allowedHosts, true)) {
            throw RemoteReferenceException::notAllowed($reference);
        }

        if (in_array($url, $chain, true)) {
            throw CircularRemoteReferenceException::chain([...$chain, $url]);
        }

        if ($this->vendorRoot === null) {
            throw UnusableSettingException::setting(
                'lara-spec-first.remote_references.vendor_path',
                'a non-empty path'
            );
        }

        $vendoredPath = VendoredReferencePath::forUrl($this->vendorRoot, $url);
        $relative = RelativeFilePath::from($baseDir, $vendoredPath);

        if ($updateRefs) {
            $this->fetchAndStore($url, $vendoredPath);
        } elseif (! is_file($vendoredPath)) {
            throw MissingVendoredReferenceException::notVendored($reference, $relative);
        }

        $decoded = DocumentDecoder::decode($vendoredPath);
        $nextChain = [...$chain, $url];
        $resolved = $this->walk($decoded, dirname($vendoredPath), $updateRefs, $nextChain);

        // Only re-written when the fetched document itself named a remote
        // reference: an ordinary vendored document, with nothing further to
        // vendor, is left exactly as fetched — the diff a reviewer sees stays
        // upstream's, not ours.
        if ($resolved !== $decoded) {
            $this->persist($vendoredPath, $resolved);
        }

        return $relative.$fragment;
    }

    /**
     * @throws RemoteReferenceFetchException
     */
    private function fetchAndStore(string $url, string $destination): void
    {
        $body = $this->fetcher->fetch($url);

        self::ensureDirectoryFor($url, $destination);

        $temp = $destination.'.'.uniqid('fetch-', true);

        // Checked rather than trusted: `file_put_contents` reports failure by
        // returning false, and a disk that is full or a permission this
        // process does not have must not read back as "fetched", the way
        // `GeneratedTree` already treats its own writes.
        if (file_put_contents($temp, $body) === false) {
            throw RemoteReferenceFetchException::failed($url, sprintf('could not write "%s"', $temp));
        }

        try {
            DocumentDecoder::decode($temp);
        } catch (Throwable $failure) {
            unlink($temp);

            throw RemoteReferenceFetchException::failed($url, $failure->getMessage());
        }

        if (! rename($temp, $destination)) {
            throw RemoteReferenceFetchException::failed(
                $url,
                sprintf('fetched successfully but could not commit it to "%s"', $destination)
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws RemoteReferenceFetchException
     */
    private function persist(string $path, array $data): void
    {
        $temp = $path.'.'.uniqid('rewrite-', true);

        if (file_put_contents($temp, Yaml::dump($data, 10, 2)) === false) {
            throw RemoteReferenceFetchException::failed($path, sprintf('could not write "%s"', $temp));
        }

        if (! rename($temp, $path)) {
            throw RemoteReferenceFetchException::failed($path, sprintf('could not commit the rewrite to "%s"', $path));
        }
    }

    /**
     * @throws RemoteReferenceFetchException
     */
    private static function ensureDirectoryFor(string $url, string $file): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            // Checked again after a failed `mkdir`: another process racing to
            // vendor the same reference may have created it in between, which
            // is success arriving late rather than a real failure.
            throw RemoteReferenceFetchException::failed($url, sprintf('could not create directory "%s"', $directory));
        }
    }

    /**
     * @return array{0: string, 1: string} the URL, and any `#fragment` — kept
     *                                     apart because only the URL is fetched
     */
    private static function splitFragment(string $reference): array
    {
        $hash = strpos($reference, '#');

        return $hash === false
            ? [$reference, '']
            : [substr($reference, 0, $hash), substr($reference, $hash)];
    }

    /**
     * A reference with a scheme leaves the filesystem.
     *
     * Matched on the scheme rather than on a list of protocols: `https` is the
     * one anybody writes, but the parser hands the string to a stream wrapper,
     * and PHP has more of those than a denylist would ever keep up with.
     */
    private static function reachesOverTheNetwork(string $reference): bool
    {
        return preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $reference) === 1;
    }
}
