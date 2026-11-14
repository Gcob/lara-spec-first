<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * One promoted property of a generated input DTO.
 *
 * @internal Not public API: what the planner hands the emitter.
 */
final readonly class InputDtoProperty
{
    /**
     * @param  string  $key  the contract's key, as written, which `from()` reads
     *                       and `toArray()` writes back
     * @param  string  $name  the PHP property: the key itself when it is an
     *                        identifier, a derived name when it is not
     * @param  bool  $optional  whether the client may leave it out, which is
     *                          what makes it `Optional|T`
     */
    public function __construct(
        public string $key,
        public string $name,
        public InputDtoType $type,
        public bool $optional,
    ) {}
}
