<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * The PHP type one schema becomes on an input DTO, and how a value converts to
 * and from it.
 *
 * **The table in `dto-anatomy.md`, as code.** One schema type is one PHP type and
 * the mapping is fixed, so this class answers the four questions an emitter has
 * about a property without ever reading a schema: what is it declared as, what
 * does the docblock add, how does `from()` build it, and how does `toArray()`
 * write it back.
 *
 * **The request side casts, and the reason is that validation has already run.**
 * A multipart body delivers `"42"` where the schema said `integer`, and Laravel's
 * `integer` rule accepts it, so `(int)` is what lets the typed property accept it
 * too. It cannot turn a bad value into a wrong one, because a bad value never got
 * past the rule set. That is also why this is request-side only: a response DTO
 * has no such guarantee and casts nothing.
 *
 * **Every expression takes the value as a PHP expression.** A nullable value is
 * checked for `null` before anything converts it, because
 * `CarbonImmutable::parse(null)` is the current time and `(int) null` is `0`.
 *
 * @internal Not public API: the arithmetic of one emitter.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */
final readonly class InputDtoType
{
    public const STRING = 'string';

    public const INT = 'int';

    public const FLOAT = 'float';

    public const BOOL = 'bool';

    public const MIXED = 'mixed';

    /** `format: date-time` */
    public const DATE_TIME = 'date-time';

    /** `format: date` */
    public const DATE = 'date';

    /** An object with `properties`, as a DTO of its own. */
    public const DTO = 'dto';

    /** An object with no `properties`: whatever keys it arrives with. */
    public const MAP = 'map';

    public const LIST = 'list';

    /** A file part, which exists on the request side only. */
    public const FILE = 'file';

    /**
     * @param  string  $kind  one of the constants above
     * @param  string|null  $class  the short name of the DTO, for `DTO`
     * @param  self|null  $item  the element type, for `LIST`
     * @param  list<string|int|float|bool>  $literals  the values an enumeration
     *                                                 allows, written in the
     *                                                 docblock as a literal union
     */
    public function __construct(
        public string $kind,
        public bool $nullable = false,
        public ?string $class = null,
        public ?self $item = null,
        public array $literals = [],
    ) {}

    /**
     * The type as PHP declares it, without `Optional` and without `null`.
     */
    public function native(): string
    {
        return match ($this->kind) {
            self::DATE, self::DATE_TIME => 'CarbonImmutable',
            self::DTO => (string) $this->class,
            self::LIST, self::MAP => 'array',
            self::FILE => 'UploadedFile',
            default => $this->kind,
        };
    }

    /**
     * What the docblock says where the declared type says less, or null where
     * it says all of it.
     */
    public function doc(): ?string
    {
        if ($this->literals !== [] && self::safeInDocblock($this->literals)) {
            return implode('|', array_map(
                static fn (string|int|float|bool $value): string => var_export($value, true),
                $this->literals,
            ));
        }

        return match ($this->kind) {
            self::LIST => 'list<'.$this->itemDoc().'>',
            self::MAP => 'array<string, mixed>',
            default => null,
        };
    }

    /**
     * Whether every enumeration value can be written inside a docblock.
     *
     * **An enumeration value is data from the specification, and a docblock is
     * code.** A string holding the sequence that closes a comment would end the
     * docblock early and turn what follows into statements, and a newline would
     * break the line. Such a value is not written: the property keeps its scalar
     * type and loses only the literal union, which is a hint and not a rule.
     *
     * @see docs/guide/code-generation/index.md — "A specification is untrusted data"
     *
     * @param  list<string|int|float|bool>  $literals
     */
    private static function safeInDocblock(array $literals): bool
    {
        foreach ($literals as $literal) {
            if (is_string($literal) && preg_match('~\*/|[\x00-\x1f\x7f]~', $literal) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The files that must be imported for this type to be written.
     *
     * @return list<string>
     */
    public function imports(): array
    {
        return match ($this->kind) {
            self::DATE, self::DATE_TIME => ['Carbon\\CarbonImmutable'],
            self::FILE => ['Illuminate\\Http\\UploadedFile'],
            self::LIST => $this->item?->imports() ?? [],
            default => [],
        };
    }

    /**
     * The expression that builds this type from a payload value.
     */
    public function from(string $value): string
    {
        $built = $this->build($value);

        return $this->nullable && $built !== $value
            ? sprintf('%s === null ? null : %s', $value, $built)
            : $built;
    }

    /**
     * The expression that writes this type back as the contract spells it.
     */
    public function to(string $value): string
    {
        $written = $this->write($value);

        if (! $this->nullable || $written === $value) {
            return $written;
        }

        return match ($this->kind) {
            self::DATE, self::DATE_TIME, self::DTO => $this->nullsafe($value, $written),
            default => sprintf('%s === null ? null : %s', $value, $written),
        };
    }

    /**
     * Whether `from()` does anything to a value of this type.
     */
    public function convertsOnTheWayIn(): bool
    {
        return match ($this->kind) {
            self::INT, self::FLOAT, self::BOOL, self::DATE, self::DATE_TIME, self::DTO => true,
            self::LIST => $this->item?->convertsOnTheWayIn() ?? false,
            default => false,
        };
    }

    /**
     * Whether `toArray()` does anything to a value of this type.
     */
    public function convertsOnTheWayOut(): bool
    {
        return match ($this->kind) {
            self::DATE, self::DATE_TIME, self::DTO => true,
            self::LIST => $this->item?->convertsOnTheWayOut() ?? false,
            default => false,
        };
    }

    private function itemDoc(): string
    {
        $item = $this->item ?? new self(self::MIXED);

        return ($item->doc() ?? $item->native()).($item->nullable && $item->kind !== self::MIXED ? '|null' : '');
    }

    private function build(string $value): string
    {
        return match ($this->kind) {
            self::INT => '(int) '.$value,
            self::FLOAT => '(float) '.$value,
            self::BOOL => '(bool) '.$value,
            self::DATE, self::DATE_TIME => 'CarbonImmutable::parse('.$value.')',
            self::DTO => $this->class.'::from('.$value.')',
            self::LIST => $this->buildList($value),
            default => $value,
        };
    }

    private function buildList(string $value): string
    {
        $callable = $this->item?->fromCallable();

        // `array_values`, because PHPStan cannot see that the payload's array is
        // a list, so `array_map` over it is an `array<int>` and the constructor's
        // `list<int>` would be a claim nothing checks. The validated payload is a
        // list already (the rule set says `list`), so this reorders nothing.
        return $callable === null ? $value : 'array_values(array_map('.$callable.', '.$value.'))';
    }

    /**
     * A callable turning one element into this type, or null when an element
     * goes in as it is.
     */
    private function fromCallable(): ?string
    {
        if (! $this->convertsOnTheWayIn()) {
            return null;
        }

        if (! $this->nullable) {
            switch ($this->kind) {
                case self::INT:
                    return 'intval(...)';
                case self::FLOAT:
                    return 'floatval(...)';
                case self::BOOL:
                    return 'boolval(...)';
                case self::DATE:
                case self::DATE_TIME:
                    return 'CarbonImmutable::parse(...)';
                case self::DTO:
                    return $this->class.'::from(...)';
            }
        }

        return sprintf(
            'static fn (%s $item): %s => %s',
            $this->kind === self::LIST && ! $this->nullable ? 'array' : 'mixed',
            ($this->nullable ? '?' : '').$this->native(),
            $this->from('$item'),
        );
    }

    private function write(string $value): string
    {
        return match ($this->kind) {
            // Microseconds, which is the most Carbon holds: `toRfc3339String()` drops
            // the fraction, and a request may carry one on any date-time it sends.
            self::DATE_TIME => $value.'->format(\'Y-m-d\\TH:i:s.uP\')',
            self::DATE => $value.'->toDateString()',
            self::DTO => $value.'->toArray()',
            self::LIST => $this->writeList($value),
            default => $value,
        };
    }

    private function writeList(string $value): string
    {
        $callable = $this->item?->toCallable();

        return $callable === null ? $value : 'array_map('.$callable.', '.$value.')';
    }

    private function toCallable(): ?string
    {
        if (! $this->convertsOnTheWayOut()) {
            return null;
        }

        return sprintf(
            'static fn (%s%s $item): %s%s => %s',
            $this->nullable ? '?' : '',
            $this->native(),
            $this->nullable ? '?' : '',
            match ($this->kind) {
                self::DATE, self::DATE_TIME => 'string',
                default => 'array',
            },
            $this->to('$item'),
        );
    }

    /**
     * `$value?->method()` for a conversion that is a method call on the value.
     */
    private function nullsafe(string $value, string $written): string
    {
        return $value.'?'.substr($written, strlen($value));
    }
}
