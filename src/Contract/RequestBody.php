<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * What an operation accepts as a body, normalized.
 *
 * **The media types stay separate rather than being reduced to one here.** Two of them over one schema are not a
 * conflict and produce one rule set, while two over two different schemas are a build error naming both — and that
 * refusal belongs where the rule set is generated, not where the contract is read. The contract's job is to say what
 * the document states.
 *
 * `Generation\RuleSetBuilder` is where both halves of that now happen: which media types are read, and the refusal.
 * Named rather than linked, because `Contract\` may not import `Generation\` and a docblock reference is exactly what a
 * formatter turns into an import.
 *
 * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
 */
final readonly class RequestBody
{
    /**
     * @param  array<string, Schema>  $content  keyed by media type, in the order the document writes them
     * @param  bool  $required  whether the whole payload may be absent, which is never the same question as whether a
     *                          property inside it is required
     * @param  array<string, array<string, mixed>>  $encoding  media type, then part name, then the `contentType` the
     *                                                         document wrote for that part, as written: a string, or
     *                                                         whatever else the author put there (a YAML list), which a
     *                                                         reader says it did not read. A part that writes none has
     *                                                         no entry: the parser's invented default is not what the
     *                                                         author said. Splitting the list and normalizing each type
     *                                                         is the reader's job
     * @param  EncodingDefaults  $encodingDefaults  the `contentType` a part gets when `encoding` writes none, as the
     *                                              document's own version gives it. A body built by hand names no
     *                                              version, so it gets the widest table, which reports nothing a
     *                                              version could call a default (a file part with no `type` is the
     *                                              exception: every version gives it `application/octet-stream`)
     */
    public function __construct(
        public array $content,
        public bool $required = false,
        public array $encoding = [],
        public EncodingDefaults $encodingDefaults = new EncodingDefaults,
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
