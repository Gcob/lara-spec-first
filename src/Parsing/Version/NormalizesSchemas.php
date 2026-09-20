<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;

/**
 * Everything the two versions normalize identically.
 *
 * The trait exists so that each strategy holds its *disagreements* and nothing
 * else: `minLength` means the same thing at both versions, and a reader
 * comparing the two files should find only the four places where they part
 * company.
 *
 * @see docs/guide/openapi-support.md — "The normal form a schema takes"
 */
trait NormalizesSchemas
{
    /**
     * @param  array<string, mixed>  $keywords  one schema node, flattened by the
     *                                          extractor: every value is either
     *                                          a scalar, a plain array, or a
     *                                          {@see Schema} a nested node was
     *                                          already normalized into
     */
    public function normalizeSchema(array $keywords): Schema
    {
        [$exclusiveMinimum, $minimum] = $this->exclusiveBound($keywords, 'Minimum');
        [$exclusiveMaximum, $maximum] = $this->exclusiveBound($keywords, 'Maximum');

        return new Schema(
            types: $this->types($keywords),
            format: $this->format($keywords),
            properties: $this->schemaMap($keywords, 'properties'),
            required: $this->strings($keywords, 'required'),
            items: ($keywords['items'] ?? null) instanceof Schema ? $keywords['items'] : null,
            allOf: array_values(array_filter(
                is_array($keywords['allOf'] ?? null) ? $keywords['allOf'] : [],
                static fn (mixed $branch): bool => $branch instanceof Schema
            )),
            enum: $this->enum($keywords),
            examples: $this->examples($keywords),
            additionalProperties: is_bool($keywords['additionalProperties'] ?? null)
                ? $keywords['additionalProperties']
                : null,
            minLength: $this->integer($keywords, 'minLength'),
            maxLength: $this->integer($keywords, 'maxLength'),
            pattern: $this->string($keywords, 'pattern'),
            minimum: $minimum,
            maximum: $maximum,
            exclusiveMinimum: $exclusiveMinimum,
            exclusiveMaximum: $exclusiveMaximum,
            multipleOf: $this->number($keywords, 'multipleOf'),
            minItems: $this->integer($keywords, 'minItems'),
            maxItems: $this->integer($keywords, 'maxItems'),
            uniqueItems: ($keywords['uniqueItems'] ?? null) === true,
            readOnly: ($keywords['readOnly'] ?? null) === true,
            writeOnly: ($keywords['writeOnly'] ?? null) === true,
            isFilePart: $this->isFilePart($keywords),
            contentMediaType: $this->string($keywords, 'contentMediaType'),
        );
    }

    /**
     * The type list, which is the one keyword whose *shape* differs rather than
     * only its spelling.
     *
     * @param  array<string, mixed>  $keywords
     * @return list<SchemaType>
     */
    abstract protected function types(array $keywords): array;

    /**
     * The exclusive bound and what is left of the inclusive one beside it.
     *
     * @param  array<string, mixed>  $keywords
     * @param  'Minimum'|'Maximum'  $side
     * @return array{0: float|null, 1: float|null} the exclusive bound, then the inclusive one
     */
    abstract protected function exclusiveBound(array $keywords, string $side): array;

    /**
     * `format` as the version leaves it once the file part has been read out.
     *
     * @param  array<string, mixed>  $keywords
     */
    protected function format(array $keywords): ?string
    {
        return $this->string($keywords, 'format');
    }

    /**
     * Whether this node describes a file rather than a value.
     *
     * @param  array<string, mixed>  $keywords
     */
    abstract protected function isFilePart(array $keywords): bool;

    /**
     * Every type written, whatever the version wrote them as.
     *
     * A value naming no type this package knows is dropped rather than refused:
     * a type the document invented constrains nothing a generator can express,
     * and refusing the contract over it would be this package having an opinion
     * about a keyword it does not read.
     *
     * @param  list<mixed>  $written
     * @return list<SchemaType>
     */
    private function knownTypes(array $written): array
    {
        $types = [];

        foreach ($written as $name) {
            $type = is_string($name) ? SchemaType::tryFrom($name) : null;

            if ($type !== null && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * `enum` as written, with 3.1's `const` folded into it.
     *
     * `const` is a one-value `enum` and is treated as one, which is the matrix's
     * own wording. Both written at once keeps `enum`: `const` is the narrower
     * claim, and a value satisfying it satisfies the list too only if the author
     * wrote the two consistently — which is a contract question rather than a
     * normalization one.
     *
     * @param  array<string, mixed>  $keywords
     * @return list<mixed>|null
     */
    private function enum(array $keywords): ?array
    {
        if (is_array($keywords['enum'] ?? null)) {
            return array_values($keywords['enum']);
        }

        return array_key_exists('const', $keywords) ? [$keywords['const']] : null;
    }

    /**
     * The examples, in the one shape a mocker reads.
     *
     * 3.1's list wins over 3.0's single value when a document writes both: a
     * document carrying the two has migrated halfway, and the newer half is the
     * one stating the intention.
     *
     * @param  array<string, mixed>  $keywords
     * @return list<mixed>
     */
    private function examples(array $keywords): array
    {
        if (is_array($keywords['examples'] ?? null)) {
            return array_values($keywords['examples']);
        }

        return array_key_exists('example', $keywords) ? [$keywords['example']] : [];
    }

    /**
     * @param  array<string, mixed>  $keywords
     * @return array<string, Schema>
     */
    private function schemaMap(array $keywords, string $name): array
    {
        $written = $keywords[$name] ?? null;

        if (! is_array($written)) {
            return [];
        }

        $map = [];

        foreach ($written as $key => $value) {
            if ($value instanceof Schema) {
                $map[(string) $key] = $value;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $keywords
     * @return list<string>
     */
    private function strings(array $keywords, string $name): array
    {
        $written = $keywords[$name] ?? null;

        return is_array($written)
            ? array_values(array_filter($written, is_string(...)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $keywords
     */
    private function string(array $keywords, string $name): ?string
    {
        $written = $keywords[$name] ?? null;

        return is_string($written) && $written !== '' ? $written : null;
    }

    /**
     * @param  array<string, mixed>  $keywords
     */
    private function integer(array $keywords, string $name): ?int
    {
        $written = $keywords[$name] ?? null;

        return is_int($written) ? $written : null;
    }

    /**
     * A number, read as a float whatever the document wrote it as.
     *
     * `minimum: 0` and `minimum: 0.0` are one bound, and carrying the two PHP
     * types forward would make two contracts out of one.
     *
     * @param  array<string, mixed>  $keywords
     */
    protected function number(array $keywords, string $name): ?float
    {
        $written = $keywords[$name] ?? null;

        return is_int($written) || is_float($written) ? (float) $written : null;
    }
}
