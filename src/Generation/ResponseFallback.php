<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * One place where a response lost its type because a schema was at fault: a status with no DTO, or a value typed
 * `mixed`.
 *
 * **A record beside the prose.** The generated file says why in words for its reader, and a program cannot tell that
 * sentence from a finding about a keyword. This is what a program reads, so the doctor can leave out what the build
 * handled and say only what it did not.
 *
 * @internal Not public API.
 */
final readonly class ResponseFallback
{
    /**
     * @param  string  $where  the pointer of the status's schema or of the property whose type was lost
     * @param  string  $position  where the schema that gave way is written
     * @param  ResponseFallbackCause  $cause  what was wrong with it
     */
    public function __construct(
        public string $where,
        public string $position,
        public ResponseFallbackCause $cause,
    ) {}
}
