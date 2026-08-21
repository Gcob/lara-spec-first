<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\RemoteReferences;

use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;

/**
 * Where a remote reference's vendored copy lives on disk.
 *
 * One directory per host, the URL's path beneath it — self-documenting,
 * greppable, and reviewable, and it must be deterministic: fetching the same
 * URL twice has to land in the same file, or the whole scheme leaks.
 *
 * **Query strings are folded into the filename**, via a short stable hash,
 * rather than supported literally. That is a narrow answer to a case
 * `docs/guide/remote-references.md` leaves `Open` (query strings, very long
 * paths, and case-insensitive filesystems all complicate a path-mirroring
 * layout) — not a general solution, just one that never silently collides two
 * different URLs onto one file.
 *
 * @internal Not public API — a detail of how vendoring lays out its directory.
 *
 * @see docs/guide/remote-references.md — "No lock file: git is the lock"
 */
final readonly class VendoredReferencePath
{
    /**
     * @throws RemoteReferenceException the URL's path carries a `.` or `..`
     *                                  segment, which would otherwise let a
     *                                  reference write outside `$vendorRoot`
     */
    public static function forUrl(string $vendorRoot, string $url): string
    {
        // `parse_url()` returns false for input malformed enough that it
        // cannot be split at all, rather than an array missing a key — a
        // possibility even for a string the scheme regex already matched.
        $parts = parse_url($url);
        $parts = $parts === false ? [] : $parts;

        // The caller only ever passes a reference `RemoteReferenceGuard` already
        // matched against `^[a-zA-Z][a-zA-Z0-9+.-]*://`, so a host is usually
        // present — this is not a second validation of the URL's shape, only a
        // fallback for the case just above.
        $host = (string) ($parts['host'] ?? 'unknown-host');

        // `parse_url()` splits a port into its own field rather than leaving it
        // on `host`, so it has to be folded back in by hand — otherwise
        // `example.com:8443` and `example.com:9000` silently vendor to the same
        // file, which is exactly the collision this layout exists to prevent.
        if (isset($parts['port'])) {
            $host .= '_'.$parts['port'];
        }

        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? null;

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '',
        ));

        // A `.` or `..` segment is refused outright rather than resolved: this
        // package trusts an allowed host to name a document, not to decide
        // where on this filesystem that document gets written. Resolving `..`
        // mathematically and then checking the result stayed under
        // `$vendorRoot` would work too, but refusing the segment closes the
        // door without needing to reason about how many `..` it would take to
        // walk out of it.
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw RemoteReferenceException::unsafePath($url);
            }
        }

        if ($segments === []) {
            $segments = ['index'];
        }

        if ($query !== null && $query !== '') {
            $segments[] = self::withQueryHash(array_pop($segments), $query);
        }

        return implode(DIRECTORY_SEPARATOR, [rtrim($vendorRoot, '/\\'), $host, ...$segments]);
    }

    /**
     * Insert a short hash of the query string before the file extension, or at
     * the end when the last segment has none — so `user.yaml?x=1` and
     * `user.yaml?x=2` vendor to two different, stable filenames instead of one
     * overwriting the other.
     */
    private static function withQueryHash(string $lastSegment, string $query): string
    {
        $hash = substr(sha1($query), 0, 8);
        $dot = strrpos($lastSegment, '.');

        if ($dot === false) {
            return sprintf('%s.%s', $lastSegment, $hash);
        }

        return sprintf('%s.%s%s', substr($lastSegment, 0, $dot), $hash, substr($lastSegment, $dot));
    }
}
