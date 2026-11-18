<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Why a response DTO types a value `mixed` for what the contract says.
 *
 * **Not a fault of the schema.** The contract states a union, no type, or a recursion the DTO has no class for, and
 * that is what a person reads in the generated file. A fault of the schema's own is a {@see ResponseFallbackCause}, and
 * never one of these.
 *
 * @internal Not public API.
 */
enum UntypedResponseCause
{
    /** The value states more than one type, no type, or only `null`. */
    case NoSingleType;

    /** The value points back at a schema that is not a single named object, so there is no class to build it into. */
    case UnbuiltRecursion;

    /** The value points back at a schema and writes properties beside the reference, which that class does not carry. */
    case PropertiesBesideReference;

    /** The value points back at a schema that is not an object with properties, so there is no class to read it into. */
    case AncestorNotAnObject;

    /** The values of an `additionalProperties` schema, beside declared properties: not read, though not `mixed`. */
    case ValuesBesideProperties;
}
