<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One operation of the contract, normalized and free of any OpenAPI version.
 *
 * Everything the parser had to say about this operation that the package honors
 * ends up here, in our own types — which is what lets a future version bring a
 * different parser without anything downstream noticing.
 *
 * @see docs/internals/contract-artifact.md
 */
final readonly class Operation
{
    /**
     * @param  int  $index  where the specification writes it, counting from zero.
     *                      Not decoration: this package has decided that the
     *                      document's own order settles which route wins when two
     *                      match, so the position is part of the contract and has
     *                      to survive normalization rather than be recovered from
     *                      however a file happens to be serialized.
     * @param  string|null  $operationId  as written, or null when the document omits
     *                                    it — deriving a name is the generator's
     *                                    job, not the contract's
     */
    public function __construct(
        public int $index,
        public HttpMethod $method,
        public PathTemplate $path,
        public ?string $operationId,
    ) {}

    /**
     * What addresses this operation, independently of what anything is named.
     *
     * The method plus the [normalized path](PathTemplate::class): renaming a path
     * parameter or an `operationId` leaves this untouched, which is what makes a
     * rename tellable from a deletion.
     */
    public function identity(): string
    {
        return $this->method->value.' '.$this->path->normalized;
    }
}
