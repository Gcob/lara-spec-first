<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * What one declared status of an operation answers with, as far as a DTO goes: a DTO, a list of them, or the reason
 * there is none.
 *
 * Exists so the generated controller can say, for each status, which type it is or why it has none. The controller is
 * written before the DTOs are, and a reader who lands on it should not have to find that out from another file.
 *
 * @internal Not public API: what {@see ResponseDtoPlanner} hands the controller's findings.
 */
final readonly class StatusResponse
{
    /**
     * @param  string  $status  as the document writes it: `200`, `2XX`, `default`
     * @param  string|null  $dto  the class name, or null when the status has no DTO
     * @param  int  $depth  how many lists the DTO is inside: 0 for the body itself, 1 for a list of it, 2 for a list of
     *                      lists
     * @param  bool  $nullable  whether the body itself may be `null`, which no DTO holds
     * @param  bool  $itemsNullable  whether an item of a list may be `null`
     * @param  string|null  $reason  why there is no DTO, as the end of a sentence ("it declares no body")
     */
    private function __construct(
        public string $status,
        public ?string $dto,
        public int $depth,
        public bool $nullable,
        public bool $itemsNullable,
        public ?string $reason,
    ) {}

    public static function of(string $status, string $dto, int $depth, bool $nullable = false, bool $itemsNullable = false): self
    {
        return new self($status, $dto, $depth, $nullable, $itemsNullable, null);
    }

    public static function without(string $status, string $reason): self
    {
        return new self($status, null, 0, false, false, $reason);
    }

    /**
     * The sentence a generated controller carries for this status.
     */
    public function sentence(): string
    {
        if ($this->dto === null) {
            return sprintf('The `%s` response has no DTO: %s.', CommentText::safe($this->status), $this->reason);
        }

        return sprintf(
            'The `%s` response is %s`%s`, in `Data/%s.php`%s.',
            CommentText::safe($this->status),
            str_repeat('a list of ', min($this->depth, 1)).str_repeat('lists of ', max($this->depth - 1, 0)),
            $this->dto,
            $this->dto,
            $this->nullNote(),
        );
    }

    /**
     * What the sentence says about `null`: the body, the items of a list, or both, and that no DTO holds it.
     */
    private function nullNote(): string
    {
        return match (true) {
            $this->nullable && $this->itemsNullable => ', or `null`, where an item may be `null` too, which no DTO holds',
            $this->nullable => ', or `null`, which no DTO holds',
            $this->itemsNullable => ', where an item may be `null`, which no DTO holds',
            default => '',
        };
    }
}
