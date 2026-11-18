<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Why a response schema has no DTO, when the schema itself is at fault.
 *
 * **Only a fault of the schema's own is a cause here.** A keyword a DTO cannot type (`oneOf`, a union of types, no type
 * at all), a recursion left `mixed` and a status with no body are not faults of the schema: they are what the contract
 * says, and they carry no record of this kind.
 *
 * @internal Not public API.
 */
enum ResponseFallbackCause
{
    /** Another schema claims the class name this one derives, and outranks it or ties with it. */
    case NameTaken;

    /** The schema's name leaves no usable class name. */
    case NameUnusable;

    /** Two keys of the schema derive one property name. */
    case KeysCollide;

    /** The branches of the schema's `allOf` disagree, so there is no one schema to read. */
    case AllOfDisagrees;
}
