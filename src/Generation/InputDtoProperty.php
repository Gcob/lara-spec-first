<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * One promoted property of a generated DTO, on the request side or the response side.
 *
 * @internal Not public API: what the planner hands the emitter.
 */
final readonly class InputDtoProperty
{
    /**
     * @param  string  $key  the contract's key, as written, which `from()` reads and `toArray()` writes back
     * @param  string  $name  the PHP property: the key itself when it is an identifier, a derived name when it is not
     * @param  bool  $optional  whether the client may leave it out, which is what makes it `Optional|T`
     * @param  bool  $wholePayload  whether the property is the payload itself rather than one key of it: the keys of a
     *                              map or open-object body are the data, so `from()` hands the whole payload to the
     *                              property and `toArray()` returns it
     */
    public function __construct(
        public string $key,
        public string $name,
        public InputDtoType $type,
        public bool $optional,
        public bool $wholePayload = false,
    ) {}
}
