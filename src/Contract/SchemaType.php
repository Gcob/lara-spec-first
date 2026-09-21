<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One of the seven types a JSON Schema instance can have.
 *
 * The values are JSON Schema's own spelling rather than PHP's, because that is
 * what the document wrote and what every message about it has to name back.
 *
 * `Null` is a type here rather than a flag beside one, which is the position
 * this package takes on nullability: 3.0's `nullable: true` and 3.1's
 * `type: [string, "null"]` both end up as this case inside {@see Schema::$types},
 * and nothing downstream has a second axis to forget about.
 *
 * @see docs/guide/openapi-support.md — "The normal form a schema takes"
 */
enum SchemaType: string
{
    case String = 'string';
    case Number = 'number';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Array = 'array';
    case Object = 'object';
    case Null = 'null';

    /**
     * Every value a document may write, for use in error messages.
     *
     * @return non-empty-list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
