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
     * @param  string|null  $controller  the fully-qualified name `x-controller` gives
     *                                   this operation's custom controller, or null
     *                                   when the document names none. Read rather
     *                                   than resolved: whether a class of that name
     *                                   exists is a question for the build, and the
     *                                   contract's answer does not depend on it
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
        public ?string $controller = null,
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

    /**
     * How to name this operation to a person.
     *
     * Separate from {@see self::identity()} on purpose, and the difference is not
     * cosmetic. Identity exists to be *compared*, so it normalizes parameter
     * names away; a diagnostic exists to be *read*, and `get /users/{}` sends a
     * reader looking for a path their document does not contain.
     *
     * Every message this package puts in front of a human uses this. Every
     * comparison uses identity. Mixing them is how a good error message becomes
     * a confusing one.
     */
    public function label(): string
    {
        return $this->method->value.' '.$this->path->template;
    }
}
