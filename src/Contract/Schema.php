<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One schema of the contract, normalized and free of any OpenAPI version.
 *
 * Six generators read this: request validation, response DTOs, DTO factories,
 * the Faker mocker, spec-driven test data, and the sanitized public copy of the
 * document. Every one of them would otherwise have had to know which version
 * the document declared, and they would have disagreed about it before long.
 *
 * **Absent and written-as-default are one thing here, and that is deliberate.**
 * The parser cannot tell them apart — see the
 * [parser caveats](../../docs/guide/openapi-support.md#parser-caveats) — so a
 * property typed `?int` says "nothing usable was written" rather than "the
 * document was silent". Nothing is generated from the difference, so nothing
 * needs to carry it.
 *
 * **What it does not carry.** A keyword the support matrix marks `Ignored` has
 * no property here: nothing reads it, and a field nobody reads is a value
 * waiting to be mistaken for a supported one.
 *
 * TODO (#36): reporting those ignored keywords per operation is that card's,
 * and this class is where the collection would hang when it lands.
 *
 * @see docs/guide/openapi-support.md — "The normal form a schema takes"
 */
final readonly class Schema
{
    /**
     * @param  list<SchemaType>  $types  always a list, never a bare string, and
     *                                   empty when the document states no type.
     *                                   3.0's single string and 3.1's union
     *                                   reach this one shape, so no caller
     *                                   branches on the document version
     * @param  array<string, Schema>  $properties  keyed by property name, in the
     *                                             order the document writes them
     * @param  list<string>  $required  as written, and never emptied here: a
     *                                  `PATCH` doing that is a question about a
     *                                  generated rule set rather than about the
     *                                  contract
     * @param  list<Schema>  $allOf  branches left unmerged, since merging them
     *                               into one rule set is generation's work
     * @param  list<mixed>|null  $enum  null when the document states none.
     *                                  3.1's `const` arrives here as the
     *                                  one-value enum it is
     * @param  list<mixed>  $examples  3.1's list wins over 3.0's single
     *                                 `example` when a document writes both,
     *                                 and a lone `example` becomes a list of one
     * @param  bool|null  $additionalProperties  false forbids unknown fields,
     *                                           and null is a schema this
     *                                           package does not read. **True
     *                                           is two things at once**, and
     *                                           nothing can tell them apart:
     *                                           the parser defaults the keyword
     *                                           to true, so a document allowing
     *                                           unknown fields and a document
     *                                           silent about them arrive
     *                                           identical. Only false is a
     *                                           constraint worth generating
     *                                           from, which is why the matrix
     *                                           splits these rows on the value
     *                                           rather than on the keyword
     * @param  float|null  $exclusiveMinimum  the 3.1 spelling, always: a bound
     *                                        standing on its own rather than a
     *                                        boolean modifying a sibling. A 3.0
     *                                        `minimum` carrying
     *                                        `exclusiveMinimum: true` arrives
     *                                        here with the bound moved and
     *                                        `minimum` left null
     * @param  bool  $isFilePart  what `format: binary` says at 3.0 and
     *                            `contentMediaType` at 3.1, read as one notion
     * @param  string|null  $recursesTo  the JSON Pointer of the ancestor this
     *                                   node points back at, on the one node
     *                                   where the walk had to stop. A
     *                                   self-referential schema is a supported
     *                                   contract and an infinite tree, so it is
     *                                   cut here and named rather than walked
     */
    public function __construct(
        public array $types = [],
        public ?string $format = null,
        public array $properties = [],
        public array $required = [],
        public ?Schema $items = null,
        public array $allOf = [],
        public ?array $enum = null,
        public array $examples = [],
        public ?bool $additionalProperties = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?float $minimum = null,
        public ?float $maximum = null,
        public ?float $exclusiveMinimum = null,
        public ?float $exclusiveMaximum = null,
        public ?float $multipleOf = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public bool $uniqueItems = false,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public bool $isFilePart = false,
        public ?string $contentMediaType = null,
        public ?string $recursesTo = null,
    ) {}

    /**
     * The node a walk stops on when a schema points back at one of its own
     * ancestors.
     *
     * It carries the pointer and nothing else on purpose: what is at the other
     * end is the ancestor, already in hand, and copying it here would be the
     * infinite tree this exists to avoid.
     */
    public static function recursion(string $pointer): self
    {
        return new self(recursesTo: $pointer);
    }

    /**
     * Whether the document allows this value to be null.
     *
     * Read from the type list rather than from a flag beside it, which is the
     * whole reason `null` is a type here: one place states it, so there is no
     * second one to disagree.
     */
    public function isNullable(): bool
    {
        return in_array(SchemaType::Null, $this->types, true);
    }

    /**
     * The type this schema states, ignoring nullability.
     *
     * Most generators want exactly this: a union of a type and `null` is one
     * type that may be absent, and asking for it should not mean filtering the
     * list at every call site. A genuine union of two types — which 3.1 allows
     * and this package does not generate from — comes back null, since there is
     * no single answer to give.
     */
    public function soleType(): ?SchemaType
    {
        $stated = array_values(array_filter(
            $this->types,
            static fn (SchemaType $type): bool => $type !== SchemaType::Null
        ));

        return count($stated) === 1 ? $stated[0] : null;
    }
}
