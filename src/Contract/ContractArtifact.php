<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

use Gcob\LaraSpecFirst\Contract\Exceptions\DuplicateIdentityException;

/**
 * The normalized, resolved, version-neutral contract every comparison in this
 * package goes through — never a specification document, never generated code.
 *
 * Keyed by identity, which is what lets a future consumer (`spec:doctor`,
 * breaking-change detection) ask "does this endpoint still exist" without
 * scanning a list. {@see Operation::$index} carries the document's own order
 * separately, because that order is semantic — it decides which route wins —
 * while the order operations() returns is not.
 *
 * @see docs/CONTRACT-ARTIFACT.md
 */
final readonly class ContractArtifact
{
    /**
     * Bumped whenever the serialized shape in {@see self::toArray()} changes,
     * so a package upgrade can tell an old committed artifact from a current
     * one rather than misread it.
     */
    public const string FORMAT_VERSION = '1';

    /**
     * @param  array<string, Operation>  $operations  keyed by identity, ordered by path then method
     */
    private function __construct(private array $operations) {}

    /**
     * @param  list<Operation>  $operations  in document order
     *
     * @throws DuplicateIdentityException two operations addressing one endpoint
     */
    public static function fromOperations(array $operations): self
    {
        usort($operations, static fn (Operation $a, Operation $b): int => [
            $a->path->normalized, self::methodRank($a->method),
        ] <=> [
            $b->path->normalized, self::methodRank($b->method),
        ]);

        $byIdentity = [];
        foreach ($operations as $operation) {
            $identity = $operation->identity();

            if (isset($byIdentity[$identity])) {
                throw DuplicateIdentityException::forIdentity($identity);
            }

            $byIdentity[$identity] = $operation;
        }

        return new self($byIdentity);
    }

    /**
     * Every operation, grouped by path and then by method — the order a human
     * reviewing a diff reads a specification in. Not the order that decides
     * which route wins; that is {@see Operation::$index}.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return array_values($this->operations);
    }

    /**
     * The operation addressed by an identity, or null if the contract does not
     * have one — the lookup {@see Operation::identity()} exists to answer.
     */
    public function operation(string $identity): ?Operation
    {
        return $this->operations[$identity] ?? null;
    }

    /**
     * The committed shape: a format version, and every operation in the order
     * {@see self::operations()} returns, with the document position it carries
     * recorded as a plain field rather than the array's own order.
     *
     * @return array{formatVersion: string, operations: list<array{index: int, method: string, path: string, operationId: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'operations' => array_map(
                static fn (Operation $operation): array => [
                    'index' => $operation->index,
                    'method' => $operation->method->value,
                    'path' => $operation->path->template,
                    'operationId' => $operation->operationId,
                ],
                $this->operations(),
            ),
        ];
    }

    /**
     * Where a method sits in the order a Path Item writes its verbs in — the
     * same order {@see HttpMethod::cases()} declares, so the canonical order
     * groups `get`/`put`/`post`/... consistently rather than alphabetically.
     */
    private static function methodRank(HttpMethod $method): int
    {
        /** @var int $rank always found: $method is one of HttpMethod::cases() */
        $rank = array_search($method, HttpMethod::cases(), true);

        return $rank;
    }
}
