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
     * @param  bool  $list  whether the body is a list of that DTO
     * @param  string|null  $reason  why there is no DTO, as the end of a sentence ("it declares no body")
     */
    private function __construct(
        public string $status,
        public ?string $dto,
        public bool $list,
        public ?string $reason,
    ) {}

    public static function of(string $status, string $dto, bool $list): self
    {
        return new self($status, $dto, $list, null);
    }

    public static function without(string $status, string $reason): self
    {
        return new self($status, null, false, $reason);
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
            'The `%s` response is %s`%s`, in `Data/%s.php`.',
            CommentText::safe($this->status),
            $this->list ? 'a list of ' : '',
            $this->dto,
            $this->dto,
        );
    }
}
