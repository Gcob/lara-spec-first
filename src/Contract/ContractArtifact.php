<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

use JsonException;

/**
 * The normalized contract: resolved, version-neutral, and holding only what the
 * package honors.
 *
 * Every comparison between two versions of a contract goes through here.
 *
 * @see docs/internals/contract-artifact.md
 */
final readonly class ContractArtifact
{
    /**
     * @see docs/internals/contract-artifact.md — "What it holds today"
     */
    public const string FORMAT_VERSION = '0.1';

    /**
     * @see docs/internals/contract-artifact.md — "The file"
     */
    public const string FILENAME = 'generated-spec-artefact.json';

    /**
     * @param  list<Operation>  $operations  in canonical order — see {@see canonically()}
     */
    private function __construct(public array $operations) {}

    /**
     * @param  list<Operation>  $operations  as the document writes them, with unique
     *                                       identities. The extractor refuses a
     *                                       document where two address one endpoint,
     *                                       so by this point they are.
     */
    public static function fromOperations(array $operations): self
    {
        return new self(self::canonically($operations));
    }

    /**
     * The artifact as data, keyed by identity.
     *
     * @return array{artifactVersion: string, operations: array<string, array<string, mixed>>}
     *
     * @see docs/internals/contract-artifact.md — "What it holds today"
     */
    public function toArray(): array
    {
        $operations = [];

        foreach ($this->operations as $operation) {
            $operations[$operation->identity()] = [
                'index' => $operation->index,
                'method' => $operation->method->value,
                'path' => $operation->path->template,
                'operationId' => $operation->operationId,
                'tags' => $operation->tags,
                'audience' => $operation->audience->value,
                'lifecycle' => $operation->lifecycle?->value,
                'deprecated' => $operation->deprecated,
                'sunset' => $operation->sunset,
                'security' => $operation->security,
            ];
        }

        return [
            'artifactVersion' => self::FORMAT_VERSION,
            'operations' => $operations,
        ];
    }

    /**
     * The artifact as the JSON that gets committed.
     *
     * @throws JsonException
     */
    public function toJson(): string
    {
        $data = $this->toArray();

        return json_encode(
            // A contract with no operations is an empty object, not an empty
            // list, and PHP cannot tell the two apart. Without the cast a reader
            // would have to handle both shapes for one field.
            ['artifactVersion' => $data['artifactVersion'], 'operations' => (object) $data['operations']],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )."\n";
    }

    /**
     * Order the operations for serialization: by endpoint, then by method.
     *
     * Deliberately not the document's order, which survives as `index`.
     *
     * @param  list<Operation>  $operations
     * @return list<Operation>
     *
     * @see docs/internals/contract-artifact.md — "What it holds today"
     */
    private static function canonically(array $operations): array
    {
        usort($operations, static fn (Operation $a, Operation $b): int => [$a->path->normalized, $a->method->value]
            <=> [$b->path->normalized, $b->method->value]);

        return $operations;
    }
}
