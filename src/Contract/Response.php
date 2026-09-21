<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One response an operation declares, normalized.
 *
 * **A response with no readable schema still exists here**, and that is the
 * position rather than an accident. A `204` is a promise the contract makes:
 * the operation answers, and it answers with nothing. Dropping it because there
 * was no schema to normalize would leave the contract unable to say a status
 * code was ever declared, and whatever reads it later would have no way to tell
 * "no body" from "no such response".
 *
 * **The status code is carried as the document writes it**, which means `200`,
 * the 3.1 range `2XX`, and `default` all arrive as the strings they are. Which
 * of them answers a given request is a question about serving a response, and
 * answering it here would bake one reader's rule into the thing every reader
 * consults.
 *
 * **It is a property rather than an array key, and PHP is the reason.** A
 * numeric string used as a key becomes an integer, so a map of these would hand
 * `200` back as an int and `2XX` as a string, and a reader comparing strictly
 * against `'200'` would be wrong about half the contract. The same reasoning
 * put the name on {@see QueryParameter} rather than above it.
 *
 * @see docs/guide/openapi-support.md — "The normal form a schema takes"
 */
final readonly class Response
{
    /**
     * @param  array<string, Schema>  $content  keyed by media type, in the order
     *                                          the document writes them, and
     *                                          empty for a response that carries
     *                                          no body or none this package
     *                                          reads a schema from
     */
    public function __construct(
        public string $status,
        public array $content = [],
    ) {}

    /**
     * Every media type this response declares a schema for.
     *
     * @return list<string>
     */
    public function mediaTypes(): array
    {
        return array_keys($this->content);
    }
}
