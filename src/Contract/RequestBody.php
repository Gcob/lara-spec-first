<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * What an operation accepts as a body, normalized.
 *
 * **The media types stay separate rather than being reduced to one here.** Two
 * of them over one schema are not a conflict and produce one rule set, while
 * two over two different schemas are a build error naming both — and that
 * refusal belongs where the rule set is generated, not where the contract is
 * read. The contract's job is to say what the document states.
 *
 * `Generation\RuleSetBuilder` is where both halves of that now happen: which
 * media types are read, and the refusal. Named rather than linked, because
 * `Contract\` may not import `Generation\` and a docblock reference is exactly
 * what a formatter turns into an import.
 *
 * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
 */
final readonly class RequestBody
{
    /**
     * @param  array<string, Schema>  $content  keyed by media type, in the order
     *                                          the document writes them
     * @param  bool  $required  whether the whole payload may be absent, which is
     *                          never the same question as whether a property
     *                          inside it is required
     */
    public function __construct(
        public array $content,
        public bool $required = false,
    ) {}

    /**
     * Every media type the operation declares a body for.
     *
     * @return list<string>
     */
    public function mediaTypes(): array
    {
        return array_keys($this->content);
    }
}
