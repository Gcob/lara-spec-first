<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use RuntimeException;

/**
 * A `$ref` chain points only at other references and closes back on itself.
 *
 * This is the one document fault the parser cannot survive that this package
 * can currently detect: it recurses past its own cycle checks and exhausts
 * memory, taking the process down instead of raising. Detecting it is therefore
 * ours, and it has to happen before the document is handed over — which is what
 * {@see ParserUnsafeFault}, the marker this exception carries, tells its
 * readers.
 *
 * @see docs/guide/openapi-support.md — "Parser caveats"
 */
final class CyclicReferenceException extends RuntimeException implements ParserUnsafeFault
{
    /**
     * **The remedy depends on how the chain closes, which is why it is not one
     * sentence.** For a chain of Reference Objects the advice is to make one of
     * them reach a schema. For a chain that runs through a position holding a
     * value, that advice is not actionable and would send a reader to change
     * something that is already a literal: `#/components/schemas/A/example` is
     * not a reference and can never be made to point anywhere. What has to
     * change there is the reference aiming *at* it, so the message says so and
     * names the positions rather than leaving them to be picked out of the
     * chain.
     *
     * @param  non-empty-list<string>  $chain  the pointers followed, ending where it started
     * @param  list<string>  $insideData  the pointers in that chain naming a position
     *                                    that holds a value rather than a reference,
     *                                    in the order the chain reaches them
     */
    public static function chain(array $chain, array $insideData = []): self
    {
        return new self(sprintf(
            'The reference %s closes a cycle: %s. '.
            'A chain of references that never reaches content cannot be resolved — %s',
            $chain[0],
            implode(' -> ', $chain),
            $insideData === []
                ? 'one of these must point at a schema rather than at another reference. '.
                  '(A schema that refers back to itself, such as a tree, is a different thing and is fine.)'
                : sprintf(
                    'and this one closes through data rather than through references: %s %s a position holding a '.
                    'value (an `example`, a `default`, an Example Object\'s `value`), not a schema. The `$ref` '.
                    'written there is a literal and cannot be made to point anywhere, so the reference aiming at '.
                    'it is what has to change.',
                    implode(' and ', $insideData),
                    count($insideData) === 1 ? 'names' : 'name',
                ),
        ));
    }
}
