<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * One value a response DTO types `mixed`, or does not carry, for what the contract says.
 *
 * **A record beside the prose**, as {@see ResponseFallback} is for a fault of a schema's own: the generated file says
 * it in words for its reader, and a program cannot tell that sentence from a finding about a keyword. This is what a
 * program reads, so the doctor can name the property instead.
 *
 * @internal Not public API.
 */
final readonly class UntypedResponseValue
{
    /**
     * @param  string  $owner  the class name of the DTO that holds the value
     * @param  string  $position  where the value is written
     * @param  UntypedResponseCause  $cause  why the DTO does not type it
     * @param  array<string, string>  $keywords  each keyword the value's own schema writes that the support matrix
     *                                           marks `Ignored`, with where it is written: the schema's, a branch of
     *                                           its `allOf`, and none of what is written on a property below it
     */
    public function __construct(
        public string $owner,
        public string $position,
        public UntypedResponseCause $cause,
        public array $keywords = [],
    ) {}
}
