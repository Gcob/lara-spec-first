<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

/**
 * Rejects `$ref` chains that never reach content.
 *
 * The last check before the OpenAPI parser is handed a document, and the only
 * one whose position is not negotiable.
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
 * @internal Not public API — a step of the read pipeline, reachable only through
 *           SpecDocumentReader.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 * @see docs/guide/openapi-support.md — "Parser caveats"
 */
final readonly class ReferenceCycleDetector
{
    /**
     * Keys whose contents are always data, not specification.
     *
     * `$ref` is an ordinary key name inside a value — an API that itself handles
     * JSON Schema will have one in an `example`. Walking into these would read a
     * literal as a reference and reject a perfectly valid document, which for a
     * `Rejected`-level check is the worst outcome available: a false positive
     * refuses a contract that was fine.
     *
     * `examples` is deliberately absent: it is two different things wearing one
     * name, and only its shape tells them apart. See isOpaque().
     */
    private const OPAQUE_KEYS = ['example', 'default', 'enum', 'const'];

    /**
     * Every pure reference cycle in the document, each reported once.
     *
     * **Does not throw.** A cyclic chain is a document fault like any other this
     * package collects rather than stops at — see
     * {@see Gcob\LaraSpecFirst\Parsing\SpecDocumentReader} for why. What stays
     * non-negotiable is the *position* in the pipeline: this still has to run,
     * and its result still has to be acted on, before the document reaches the
     * parser, because a cyclic chain is the one document fault the parser
     * cannot survive — it exhausts memory instead of raising.
     *
     * @param  array<string, mixed>  $document
     * @return list<CyclicReferenceException>
     */
    public function findCycles(array $document): array
    {
        $references = $this->collect($document, '');
        $visited = [];
        $faults = [];

        foreach (array_keys($references) as $start) {
            if (isset($visited[$start])) {
                continue;
            }

            $fault = $this->follow($start, $references, $visited);

            if ($fault !== null) {
                $faults[] = $fault;
            }
        }

        return $faults;
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
            if (! is_array($child) || self::isOpaque($key, $child)) {
                continue;
            }

            $childPointer = $pointer.'/'.self::escape((string) $key);

            $references += $key === 'examples'
                ? $this->collectExampleObjects($child, $childPointer)
                : $this->collect($child, $childPointer);
        }

        return $references;
    }

    /**
     * Walk a map of Example Objects, which is the one place a key's meaning
     * depends on where it sits rather than on what it is called.
     *
     * The map itself must be followed: an Example Object may be a Reference
     * Object. But every one of those objects carries a `value` that is literal
     * data by definition — often, for an API that deals in JSON Schema, a
     * document with a `$ref` of its own. Following it reads data as a reference
     * and can refuse a valid document.
     *
     * `value` cannot simply join OPAQUE_KEYS: `properties: {value: {...}}` is an
     * ordinary schema and must be followed. Only its position here makes it
     * data, and this is where that position is known. Worth the extra method
     * because 3.1 recommends exactly this long form over the `example` keyword
     * that the name-based list already covers.
     *
     * @param  array<array-key, mixed>  $examples
     * @return array<string, string>
     */
    private function collectExampleObjects(array $examples, string $pointer): array
    {
        $references = [];

        foreach ($examples as $name => $example) {
            if (! is_array($example)) {
                continue;
            }

            unset($example['value']);

            $references += $this->collect($example, $pointer.'/'.self::escape((string) $name));
        }

        return $references;
    }

    /**
     * Whether a key's contents are data rather than specification.
     *
     * `examples` is the whole reason this is a method and not a lookup. The
     * JSON Schema keyword is a **list** of literal values, so a `$ref` inside it
     * is a literal. The OpenAPI field of the same name — on Components, a Media
     * Type Object, a Parameter — is a **map** of Example Objects, and an Example
     * Object may itself be a Reference Object. Treating both as data would hide
     * a real cycle, and hiding one is not the harmless direction here: this is
     * precisely the shape on which the parser exhausts memory instead of
     * raising, so there would be nothing left to catch it downstream.
     *
     * @param  array<array-key, mixed>  $child
     */
    private static function isOpaque(int|string $key, array $child): bool
    {
        if ($key === 'examples') {
            return array_is_list($child);
        }

        return in_array($key, self::OPAQUE_KEYS, true);
    }

    /**
     * Follow one chain until it reaches content, closes on itself, or joins a
     * chain another start already accounted for.
     *
     * **Chain construction is unchanged from before this class stopped
     * throwing** — a chain reported here may still include a non-cyclic prefix
     * leading into the cycle, exactly as a single-fault run always could,
     * depending on which pointer `findCycles()` happened to start from first.
     * That is a pre-existing, harmless imprecision (the claim — "this chain
     * never reaches content" — still holds for the prefix too), not something
     * introduced by collecting more than one fault, and correcting it is a
     * separate concern from the one this method exists to solve here.
     *
     * @param  array<string, string>  $references
     * @param  array<string, true>  $visited  every pointer a previous call already
     *                                        resolved — as safe, or as part of a
     *                                        cycle already reported. Extended with
     *                                        every pointer this call resolves too,
     *                                        so a later start sharing part of this
     *                                        chain neither re-walks it nor reports
     *                                        the same cycle twice.
     */
    private function follow(string $start, array $references, array &$visited): ?CyclicReferenceException
    {
        $chain = [$start];
        $seen = [$start => true];
        $current = $start;

        while (isset($references[$current]) && ! isset($visited[$current])) {
            $target = $references[$current];
            $chain[] = $target;

            if (isset($seen[$target])) {
                self::markVisited($chain, $visited);

                return CyclicReferenceException::chain($chain);
            }

            $seen[$target] = true;
            $current = $target;
        }

        self::markVisited($chain, $visited);

        return null;
    }

    /**
     * @param  list<string>  $chain
     * @param  array<string, true>  $visited
     */
    private static function markVisited(array $chain, array &$visited): void
    {
        foreach ($chain as $pointer) {
            $visited[$pointer] = true;
        }
    }

    /**
     * JSON Pointer escaping, so that a path segment containing `/` or `~` — a
     * URL template such as `/users/{id}`, for instance — still round-trips.
     */
    private static function escape(string $segment): string
    {
        return DocumentPointer::escape($segment);
    }
}
