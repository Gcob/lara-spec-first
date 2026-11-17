<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Which way a generated DTO is read or written, since the same schema type is not converted the same way in each.
 *
 * **A request casts and a response does not.** A multipart body delivers `"42"` where the schema said `integer`, and
 * validation has already accepted it, so the request casts. A response is built from what the application holds, which
 * nothing validated, so a wrong type is a `TypeError` naming the property rather than a silent `0`.
 *
 * **A response is written twice**: `toArray()` is a PHP array at every depth, and `jsonSerialize()` hands the encoder
 * nested DTOs as they are, so each one decides for itself whether it is an object, and every map as an object, because
 * an empty PHP array or one keyed `0` and `1` is a JSON list to an encoder that is not told otherwise.
 *
 * @internal Not public API: the arithmetic of one emitter.
 */
enum DtoMode
{
    /** An input DTO: `from()` casts, `toArray()` writes what the request carried. */
    case Request;

    /** A response DTO's `from()` and `toArray()`: nothing cast, a date written as Laravel's `toJSON()` does. */
    case Response;

    /** A response DTO's `jsonSerialize()`: nested DTOs are left for the encoder, and a map is an object. */
    case Json;

    /**
     * Whether `from()` casts a scalar to the type its schema states.
     */
    public function casts(): bool
    {
        return $this === self::Request;
    }
}
