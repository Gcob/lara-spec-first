<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
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
 * A disallowed host, a missing vendored copy, a fetch that failed, or a
 * circular chain of vendored references are collected as faults rather than
 * thrown — see {@see SpecDocumentReader} for why the whole pipeline reads this
 * way now. An allowed, already-vendored reference is still resolved exactly as
 * before: a committed copy under `vendor_path`, fetched only when
 * `--update-refs` asks for it, and the reference rewritten to point at that
 * local copy — which is why `cebe\openapi\` never dials out. See
 * [remote-references.md](../../../docs/guide/remote-references.md#how-a-vendored-copy-stays-invisible-to-the-parser)
 * for the mechanism that makes the rewrite invisible to the parser.
 *
 * **A faulted reference is neutralized, never left as a network scheme
 * string.** Collecting a fault instead of throwing means the walk keeps going
 * — and if a disallowed `$ref: https://…` were left in place, `cebe\openapi\`
 * would try to resolve it itself the next time anything opens this document,
 * which is exactly the network access the allowlist exists to prevent. So the
 * `$ref` key is removed from the node that carried it, and whatever else that
 * node holds is kept and walked like any other. See {@see self::walk()} for why
 * the key alone rather than the whole Reference Object.
 *
 * **Nothing is written when a document could not be fully resolved.** A
 * vendored copy is only re-persisted when the walk over it collected no fault
 * of its own, and a vendored document that did fault is refused by its parent
 * as well — so the reference pointing into it is neutralized rather than
 * rewritten to a local path. Two properties depend on that pair, and the
 * reasoning lives at {@see self::vendor()}: a failing read leaves the
 * repository untouched, and reading twice reports the same faults twice.
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
     */
    public function resolve(array $node, string $baseDir, bool $updateRefs = false): RemoteResolution
    {
        // One accumulator for the life of this call only — what was already
        // decided about a URL, the faults, and whether anything was removed.
        // See {@see RemoteWalk}: a specification naming the same reference from
        // a dozen positions fetches it once and refuses it once, and a
        // transitive graph where two vendored documents both reference a third
        // does not multiply the work either.
        $state = new RemoteWalk;

        $resolved = $this->walk($node, $baseDir, $updateRefs, [], $state);

        return new RemoteResolution($resolved, $state->faults, $state->neutralized);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $chain  URLs currently being resolved, root to here
     * @return array<array-key, mixed>
     */
    private function walk(array $node, string $baseDir, bool $updateRefs, array $chain, RemoteWalk $state): array
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && self::reachesOverTheNetwork($value)) {
                $rewritten = $this->vendor($value, $baseDir, $updateRefs, $chain, $state);

                if ($rewritten === null) {
                    // DECISION: a refused reference is *removed* — the `$ref`
                    // key, and only it. This is the most surprising thing this
                    // class does, because it means a fault edits the document,
                    // so it is stated here rather than left to be inferred.
                    //
                    // Removal, because leaving it: collecting a fault instead
                    // of throwing means the walk keeps going and the document
                    // still reaches `cebe\openapi\`, which resolves a `$ref`
                    // naming a URL by calling `file_get_contents()` on it. A
                    // refused reference left in place would be fetched by the
                    // parser — the exact access the allowlist exists to refuse,
                    // performed by the code meant to report it.
                    //
                    // The key alone, rather than the whole Reference Object it
                    // sits in, because "the siblings of a `$ref` mean nothing"
                    // is only true at 3.0. At 3.1 a Schema Object is JSON
                    // Schema 2020-12, where `$ref` sits *beside* applicable
                    // keywords, and a Path Item may carry `parameters` next to
                    // its `$ref`: blanking the node would delete local
                    // `properties`, `required` or `parameters` the document
                    // author wrote, and delete them silently. Keeping them
                    // costs a position that becomes invalid in its own right
                    // (a Response Object left with no `description`) surfacing
                    // as a parser fault instead of vanishing, which is the
                    // better of the two — see the 3.1 tests that pin what is
                    // and is not kept.
                    //
                    // What is given up either way: the reference itself. The
                    // schema it pointed at is gone from what the parser sees,
                    // which is why the removal is recorded — see
                    // {@see RemoteWalk::$neutralized} and
                    // {@see \Gcob\LaraSpecFirst\Parsing\ReadOutcome}.
                    unset($node[$key]);
                    $state->neutralized = true;

                    continue;
                }

                $node[$key] = $rewritten;

                continue;
            }

            if (is_array($value)) {
                $node[$key] = $this->walk($value, $baseDir, $updateRefs, $chain, $state);
            }
        }

        return $node;
    }

    /**
     * Vendor one remote reference and return what should replace it: a path to
     * the local copy, relative to the document that named it, with the
     * original fragment reattached — or null, meaning the caller neutralizes
     * the reference instead, with the fault already recorded here.
     *
     * @param  list<string>  $chain
     */
    private function vendor(string $reference, string $baseDir, bool $updateRefs, array $chain, RemoteWalk $state): ?string
    {
        [$url, $fragment] = self::splitFragment($reference);

        $decided = $state->seen[$url] ?? null;

        // Already refused earlier in this same `resolve()` call: the fault is
        // recorded, this position is neutralized like the first one was, and
        // nothing is re-derived. Checked before the allowlist and before the
        // vendor path is even computed, because a refusal may well be the
        // reason neither is usable.
        if ($decided instanceof SpecException) {
            return null;
        }

        // Lowercased for the comparison only, not for the URL that gets
        // fetched or persisted: DNS does not care about case, and refusing
        // `Schemas.Example.COM` because the allowlist spells it
        // `schemas.example.com` would tell a developer to add a host that is
        // already there.
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        if (! in_array($host, $this->allowedHosts, true)) {
            return $state->refuse($url, RemoteReferenceException::notAllowed($reference));
        }

        if (in_array($url, $chain, true)) {
            return $state->refuse($url, CircularRemoteReferenceException::chain([...$chain, $url]));
        }

        // A misconfigured vendor path is not a fault in the document — it is
        // this guard being asked to do something it cannot, regardless of
        // which reference triggered the question. That is closer to
        // `requiredString()` throwing elsewhere in this package than to a
        // document fault the doctor would ever list, so it still throws. The
        // obligation that creates is named in {@see \Gcob\LaraSpecFirst\Parsing\ReadOutcome}:
        // a caller reading a result still needs a `catch`.
        if ($this->vendorRoot === null) {
            throw UnusableSettingException::setting(
                'lara-spec-first.remote_references.vendor_path',
                'a non-empty path'
            );
        }

        $vendoredPath = VendoredReferencePath::forUrl($this->vendorRoot, $url);
        $relative = RelativeFilePath::from($baseDir, $vendoredPath);

        // Already handled earlier in this same `resolve()` call: the file is
        // on disk, its own references have already been walked, and re-decoding
        // and re-walking it again would only repeat work whose result cannot
        // have changed since. The path is still recomputed rather than
        // memoized, because it is relative to *this* document.
        if ($decided === true) {
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
            try {
                $this->fetchAndStore($url, $vendoredPath);
            } catch (RemoteReferenceFetchException $fault) {
                return $state->refuse($url, $fault);
            }
        } elseif (! is_file($vendoredPath)) {
            // Named relative to the project root rather than to `$baseDir`:
            // this is a message a person reads, and "the path a person reads"
            // and "the path a rewritten `$ref` needs" are different questions
            // that happen to share a value everywhere else in this class.
            return $state->refuse($url, MissingVendoredReferenceException::notVendored($reference, ProjectRelativePath::from($vendoredPath)));
        }

        try {
            $decoded = DocumentDecoder::decode($vendoredPath);
        } catch (SpecException $fault) {
            return $state->refuse($url, $fault);
        }

        $faultsBefore = $state->faultCount();
        $resolved = $this->walk($decoded, dirname($vendoredPath), $updateRefs, [...$chain, $url], $state);

        // DECISION: a vendored document the walk could not fully resolve is
        // refused as a whole — not persisted, and not linked to either. Both
        // halves are load-bearing and neither works alone.
        //
        // Not persisted, because the walk above already removed the offending
        // `$ref` from the in-memory copy, and writing that back would erase
        // the evidence of the fault from the file the next read starts from.
        // The realistic case is a fresh clone where a transitive vendored copy
        // was never committed: the first read faults and says to run
        // `--update-refs`, and if the parent file had been rewritten in the
        // meantime, a second plain read would find no remote `$ref` left to
        // complain about, exit `SUCCESS`, and generate a contract quietly
        // missing that schema. So: reading twice on unchanged inputs reports
        // the same faults twice, and a read that fails writes nothing.
        //
        // Not linked to, because keeping the original bytes on disk means that
        // file still names a URL — and the parser resolves a *local* `$ref` by
        // opening the file itself, so a parent rewritten to point at it would
        // hand `cebe\openapi\` the very reference this walk refused, one hop
        // later. Neutralizing the parent reference closes that: nothing the
        // parser sees leads into a document that still holds a live URL.
        //
        // A file `--update-refs` fetched on the way here does stay on disk.
        // That is upstream's own bytes, exactly what the flag was asked to
        // vendor, and the next read walks it again and reports the same fault
        // from it — which is the idempotence above, not a hole in it.
        if ($state->faultCount() > $faultsBefore) {
            return $state->refuseSilently($url, $state->faults[$faultsBefore]);
        }

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
            try {
                $this->persist($vendoredPath, $resolved);
            } catch (RemoteReferenceFetchException $fault) {
                return $state->refuse($url, $fault);
            }
        }

        $state->seen[$url] = true;

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
