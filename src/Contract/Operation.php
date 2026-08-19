<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One operation of the contract, normalized and free of any OpenAPI version.
 *
 * Everything the parser had to say about this operation that the package honors
 * ends up here, in our own types — which is what lets a future version bring a
 * different parser without anything downstream noticing.
 */
final readonly class Operation
{
    /**
     * @param  int  $index  where the specification writes it, counting from zero.
     *                      Semantic, not decoration: the document's order settles
     *                      which route wins when two match.
     * @param  string|null  $operationId  as written, or null when the document omits
     *                                    it — deriving a name is the generator's job
     * @param  list<string>  $tags  in the order the document writes them
     * @param  Audience  $audience  effective, not as written: an absent `x-audience`
     *                              is already resolved to its default here
     * @param  Lifecycle|null  $lifecycle  effective in the same sense; null is the
     *                                     absence of a claim
     * @param  string|null  $sunset  as `x-sunset` states it, unparsed — whether the
     *                               date is valid and whether it has passed are
     *                               doctor rules
     * @param  list<SecurityRequirement>|null  $security  null when the operation says
     *                                                    nothing and inherits the
     *                                                    document's, empty when it
     *                                                    explicitly requires nothing
     */
    public function __construct(
        public int $index,
        public HttpMethod $method,
        public PathTemplate $path,
        public ?string $operationId,
        public array $tags = [],
        public Audience $audience = Audience::Public,
        public ?Lifecycle $lifecycle = Lifecycle::Beta,
        public bool $deprecated = false,
        public ?string $sunset = null,
        public ?array $security = null,
    ) {}

    /**
     * What addresses this operation, independently of what anything is named.
     *
     * @see docs/guide/code-generation.md — "Identity is the path and the method, not the name"
     */
    public function identity(): string
    {
        return $this->method->value.' '.$this->path->normalized;
    }
}
