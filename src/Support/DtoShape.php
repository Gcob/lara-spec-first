<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Support;

use Gcob\LaraSpecFirst\Contract\Schema;

/**
 * What a schema writes that a generated DTO is built from.
 *
 * Held in one place because two readers of the contract ask it and must agree: the planner of the input DTOs, deciding
 * whether the wrapper of a one-branch `allOf` is the schema it wraps, and the reading of a `$ref` with keywords beside
 * it, deciding whether the wrapper takes the alias's name. A question with two copies has the answers it has copies.
 *
 * Not a method of {@see Schema}, which is public: this is how two internal readers split a decision, and a consumer of
 * the contract has no reason to ask it.
 *
 * @internal Not public API.
 */
final readonly class DtoShape
{
    /**
     * Whether this schema writes anything a DTO is built from: a property, a required name, a type, items or an
     * enumeration, and the other keywords that decide which keys a class holds.
     *
     * An `example`, a `description` or a `readOnly` change nothing a DTO holds, and a schema that writes only those is
     * the schema it wraps as far as a class is concerned.
     */
    public static function isAddedBy(Schema $schema): bool
    {
        return $schema->properties !== []
            || $schema->required !== []
            || $schema->dependentRequired !== []
            || $schema->additionalProperties !== null
            || $schema->types !== []
            || $schema->items !== null
            || $schema->enum !== null;
    }
}
