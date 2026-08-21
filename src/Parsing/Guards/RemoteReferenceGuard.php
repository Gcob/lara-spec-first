<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Generation\ProjectRelativePath;
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
    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param  list<string>  $allowedHosts  hosts a project has declared it trusts,
     *                                      lowercased once here since DNS is
     *                                      case-insensitive and a contract may
     *                                      not spell a host the way this list does.
     *                                      Reading it here rather than reaching for
     *                                      Laravel's config keeps this class a plain
     *                                      object that a unit test can construct.
     * @param  ?string  $vendorRoot  absolute path vendored copies are read from and
     *                               written to. Only required once `$allowedHosts`
     *                               is non-empty — an empty allowlist never reaches it.
     */
    public function __construct(
        array $allowedHosts = [],
        private ?string $vendorRoot = null,
        private RemoteReferenceFetcher $fetcher = new RemoteReferenceFetcher,
    ) {
        $this->allowedHosts = array_map(strtolower(...), $allowedHosts);
    }

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
        // One URL => already-vendored marker, for the life of this call only:
        // a specification naming the same reference from a dozen positions
        // fetches and writes it once rather than a dozen times, and a
        // transitive graph where two vendored documents both reference a
        // third does not multiply the work either.
        $vendored = [];

        return $this->walk($node, $baseDir, $updateRefs, [], $vendored);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $chain  URLs currently being resolved, root to here
     * @param  array<string, true>  $vendored
     * @return array<array-key, mixed>
     */
    private function walk(array $node, string $baseDir, bool $updateRefs, array $chain, array &$vendored): array
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && self::reachesOverTheNetwork($value)) {
                $node[$key] = $this->vendor($value, $baseDir, $updateRefs, $chain, $vendored);

                continue;
            }

            if (is_array($value)) {
                $node[$key] = $this->walk($value, $baseDir, $updateRefs, $chain, $vendored);
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
     * @param  array<string, true>  $vendored
     *
     * @throws RemoteReferenceException
     * @throws MissingVendoredReferenceException
     * @throws RemoteReferenceFetchException
     * @throws CircularRemoteReferenceException
     */
    private function vendor(string $reference, string $baseDir, bool $updateRefs, array $chain, array &$vendored): string
    {
        [$url, $fragment] = self::splitFragment($reference);

        // Lowercased for the comparison only, not for the URL that gets
        // fetched or persisted: DNS does not care about case, and refusing
        // `Schemas.Example.COM` because the allowlist spells it
        // `schemas.example.com` would tell a developer to add a host that is
        // already there.
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

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

        // Already handled earlier in this same `resolve()` call: the file is
        // on disk (or was refused/refreshed already), its own references have
        // already been walked, and re-decoding and re-walking it again would
        // only repeat work whose result cannot have changed since.
        if (isset($vendored[$url])) {
            return $relative.$fragment;
        }

        // DECISION: `--update-refs` refetches unconditionally here, including
        // a reference that was already vendored — the one-flag choice
        // `docs/guide/code-generation.md` argues for over a separate "add" and
        // "refresh" pair. The consequence lives at this `if`, not only in that
        // document: adding one new reference to a specification re-downloads
        // every other one already vendored, and whatever drifted upstream in
        // the meantime lands in the same commit as the addition. Accepted
        // because both are already deliberate, developer-run acts — nothing
        // here runs unattended — and because "which references are actually
        // new" is exactly the question a lockfile would exist to answer, which
        // `docs/guide/remote-references.md` already declines to keep one for.
        if ($updateRefs) {
            $this->fetchAndStore($url, $vendoredPath);
        } elseif (! is_file($vendoredPath)) {
            // Named relative to the project root rather than to `$baseDir`:
            // this is a message a person reads, and "the path a person reads"
            // and "the path a rewritten `$ref` needs" are different questions
            // that happen to share a value everywhere else in this class.
            throw MissingVendoredReferenceException::notVendored($reference, ProjectRelativePath::from($vendoredPath));
        }

        $decoded = DocumentDecoder::decode($vendoredPath);
        $nextChain = [...$chain, $url];
        $resolved = $this->walk($decoded, dirname($vendoredPath), $updateRefs, $nextChain, $vendored);

        // DECISION: the root specification is rewritten in memory only, but a
        // vendored document that itself named a reference is rewritten on
        // disk. The two look inconsistent side by side, but they answer
        // different questions. The root is read fresh on every build, so
        // rewriting it in memory is already enough — nothing is ever asked to
        // re-read the developer's own file with URLs still in it. A vendored
        // document is different: it is read from disk on every future build,
        // with or without `--update-refs`, and if its own remote references
        // were left as URLs, `cebe\openapi\` would try to fetch them itself
        // the next time anything opens this file — the exact hole vendoring
        // exists to close, one hop later. Only re-written when it actually
        // named something further to vendor: an ordinary vendored document is
        // left exactly as fetched, so the diff a reviewer sees stays
        // upstream's, not ours.
        if ($resolved !== $decoded) {
            $this->persist($vendoredPath, $resolved);
        }

        $vendored[$url] = true;

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
            self::forget($temp);

            throw RemoteReferenceFetchException::failed($url, $failure->getMessage());
        }

        if (! rename($temp, $destination)) {
            // A failed `rename()` must not leave the temp file behind: this
            // directory is committed in full, and a stray `*.fetch-*` beside
            // it is one `git add .` away from becoming part of the repository
            // it was never meant to enter.
            self::forget($temp);

            throw RemoteReferenceFetchException::failed(
                $url,
                sprintf('fetched successfully but could not commit it to "%s"', $destination)
            );
        }
    }

    /**
     * Re-encode a vendored document that itself named a reference, so what is
     * committed already points at its local siblings.
     *
     * **Matches the format it was fetched as.** `persist()` used to always
     * write YAML, which round-trips correctly — `cebe\openapi\` sniffs a
     * leading `{` rather than trusting a file's extension — but silently broke
     * the promise this package makes about the diff: a `.json` vendored copy
     * would come back reformatted as YAML end to end, when only its own
     * `$ref` values actually changed.
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws RemoteReferenceFetchException
     */
    private function persist(string $path, array $data): void
    {
        $wasJson = is_file($path) && DocumentDecoder::looksLikeJson((string) file_get_contents($path));

        $encoded = $wasJson
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : Yaml::dump($data, 10, 2);

        if ($encoded === false) {
            throw RemoteReferenceFetchException::notWritten($path, 'could not encode the rewritten document');
        }

        $temp = $path.'.'.uniqid('rewrite-', true);

        if (file_put_contents($temp, $encoded) === false) {
            throw RemoteReferenceFetchException::notWritten($path, sprintf('could not write "%s"', $temp));
        }

        if (! rename($temp, $path)) {
            self::forget($temp);

            throw RemoteReferenceFetchException::notWritten($path, sprintf('could not commit the rewrite to "%s"', $path));
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
     * Best-effort cleanup of a temp file this class wrote — never allowed to
     * mask whatever the caller is already failing over.
     */
    private static function forget(string $temp): void
    {
        if (is_file($temp)) {
            unlink($temp);
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
