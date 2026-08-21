<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Scaffolding\Exceptions\NoSuchOperationException;

/**
 * Turns what a developer typed into the operations they meant.
 *
 * **Three forms, and the singular one is the only primitive.** `--tag` and `--all`
 * are a loop over it rather than a second mechanism: nothing this package creates
 * is a grouped file, so there is nothing a bulk invocation could produce that is
 * not simply the singular form run several times.
 *
 * @see docs/guide/code-generation.md — "The build names the command instead of running it"
 */
final readonly class OperationSelector
{
    /**
     * @param  list<Operation>  $operations  in document order
     */
    public function __construct(private array $operations) {}

    /**
     * One operation, by the name a developer would have to hand.
     *
     * **`operationId` first, then the method and path.** An `operationId` is the
     * name its author chose and the obvious thing to type; the label exists
     * because [an operation need not have one](../../docs/guide/code-generation.md)
     * — and an operation with no `operationId` can still declare `x-controller`,
     * so it can still need scaffolding. The method is matched however it was
     * typed, since `GET /users/{id}` is how everyone writes an endpoint and
     * `get /users/{id}` is how a document keys it.
     *
     * @return list<Operation> one operation, as a list, so every caller handles one shape
     *
     * @throws NoSuchOperationException
     */
    public function named(string $name): array
    {
        foreach ($this->operations as $operation) {
            if ($operation->operationId === $name) {
                return [$operation];
            }
        }

        $wanted = strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? $name);

        foreach ($this->operations as $operation) {
            if (strtolower($operation->label()) === $wanted) {
                return [$operation];
            }
        }

        throw NoSuchOperationException::named($name);
    }

    /**
     * Every operation carrying a tag, in document order.
     *
     * **Matched exactly, the way the document writes it.** Tags are the author's
     * own grouping and this package does not own their spelling: matching
     * `users` against `Users` would be inventing a rule that the next contract
     * with both spellings would then be broken by.
     *
     * @return list<Operation>
     *
     * @throws NoSuchOperationException
     */
    public function tagged(string $tag): array
    {
        $selected = array_values(array_filter(
            $this->operations,
            static fn (Operation $operation): bool => in_array($tag, $operation->tags, true),
        ));

        if ($selected === []) {
            throw NoSuchOperationException::tagged($tag);
        }

        return $selected;
    }

    /**
     * Every operation the contract describes.
     *
     * @return list<Operation>
     *
     * @throws NoSuchOperationException
     */
    public function all(): array
    {
        if ($this->operations === []) {
            throw NoSuchOperationException::emptyContract();
        }

        return $this->operations;
    }

    /**
     * How many operations carry each tag, most first, then by name.
     *
     * Lives here because it answers the same question from the other end: what a
     * developer could type. `spec:build` prints it to
     * [name the commands rather than run them](../../docs/guide/code-generation.md#the-build-names-the-command-instead-of-running-it),
     * and a specification with two hundred unimplemented operations needs a
     * grouping rather than two hundred lines.
     *
     * @return array<string, int> tag to the number of operations carrying it
     */
    public function countsByTag(): array
    {
        $counts = [];

        foreach ($this->operations as $operation) {
            foreach ($operation->tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        // Sorted by count and then by name, so the output is stable across runs:
        // two tags of equal size would otherwise swap places with the document's
        // order and produce a diff nobody made.
        uksort($counts, static function (string $first, string $second) use ($counts): int {
            return $counts[$second] <=> $counts[$first] ?: strcmp($first, $second);
        });

        return $counts;
    }

    /**
     * The operations no tag would reach.
     *
     * @return list<Operation>
     */
    public function untagged(): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (Operation $operation): bool => $operation->tags === [],
        ));
    }
}
