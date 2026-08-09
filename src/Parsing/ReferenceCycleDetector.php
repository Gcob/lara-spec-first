<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;

/**
 * Rejects `$ref` chains that never reach content.
 *
 * The distinction this class exists to make:
 *
 *   A recursive *schema* refers back to an ancestor through content — a tree, a
 *   comment thread, nested categories. It resolves, and it is ordinary API
 *   modelling.
 *
 *   A pure *reference cycle* is a chain of Reference Objects pointing only at
 *   one another. There is no schema at the end of it, so it can never resolve.
 *
 * The second is the only document fault that takes the parser down rather than
 * raising, so it has to be caught here, on the decoded array, before the parser
 * is handed anything.
 *
 * Stateless on purpose: it is injected into readonly collaborators and reused
 * across documents, so nothing about one document may survive into the next.
 *
 * @see docs/OPENAPI-SUPPORT.md — "Parser caveats"
 */
final readonly class ReferenceCycleDetector
{
    /**
     * @param  array<string, mixed>  $document
     *
     * @throws CyclicReferenceException
     */
    public function assertNoCycles(array $document): void
    {
        $references = $this->collect($document, '');

        foreach (array_keys($references) as $start) {
            $this->follow($start, $references);
        }
    }

    /**
     * Every node carrying a local `$ref`, keyed by its own JSON pointer.
     *
     * Keys are written the same way targets are, `#` included, so following a
     * chain is a plain lookup rather than a conversion.
     *
     * References into another file or over the network are skipped: resolving
     * them needs the vendored copies, which is a later concern. A cycle that
     * only closes across files is therefore out of reach here, and that limit
     * is deliberate rather than forgotten.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, string> pointer of the reference => pointer it targets
     */
    private function collect(array $node, string $pointer): array
    {
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            // A Reference Object carries nothing else worth walking: 3.1 allows
            // `summary` and `description` beside it, and neither can hold a ref.
            return str_starts_with($node['$ref'], '#/')
                ? ['#'.$pointer => $node['$ref']]
                : [];
        }

        $references = [];

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $references += $this->collect($child, $pointer.'/'.self::escape((string) $key));
            }
        }

        return $references;
    }

    /**
     * Follow one chain until it reaches content, or closes on itself.
     *
     * @param  array<string, string>  $references
     *
     * @throws CyclicReferenceException
     */
    private function follow(string $start, array $references): void
    {
        $chain = [$start];
        $seen = [$start => true];
        $current = $start;

        while (isset($references[$current])) {
            $target = $references[$current];
            $chain[] = $target;

            if (isset($seen[$target])) {
                throw CyclicReferenceException::chain($chain);
            }

            $seen[$target] = true;
            $current = $target;
        }
    }

    /**
     * JSON Pointer escaping, so that a path segment containing `/` or `~` — a
     * URL template such as `/users/{id}`, for instance — still round-trips.
     */
    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
