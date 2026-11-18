<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Generation\ResponseFallbackCause;
use RuntimeException;

/**
 * A response schema with a fault of its own, which gives it no DTO.
 *
 * **Never leaves the response planner.** It is thrown where the fault is found, before the schema is registered, and
 * caught where a status is answered or a value is typed: the status then says it has no DTO, and the value is `mixed`.
 * It implements {@see SpecException} so that, were a later change to let it out, a consumer's one `catch` would still
 * see it.
 *
 * @internal Not public API.
 */
final class UntypeableResponseException extends RuntimeException implements SpecException
{
    /**
     * @param  string  $position  where the schema at fault is written
     * @param  string  $reason  what is wrong and how to fix it, as the end of a sentence with no full stop
     */
    public function __construct(
        public readonly ResponseFallbackCause $cause,
        public readonly string $position,
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
