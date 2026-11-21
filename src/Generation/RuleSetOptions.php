<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Illuminate\Contracts\Config\Repository;

/**
 * What the application's configuration changes about the rules a request is built with.
 *
 * Read once per command and handed to every rule set, so `spec:build`, `spec:make` and `spec:doctor` read the contract
 * the same way: the doctor compares the generated tree with what a build would write, so a doctor that read another
 * value would call every request drifted. One object rather than one argument per setting, so a second setting is a
 * property and not another parameter on every call site.
 *
 * @internal Not public API, the settings of one build.
 */
final readonly class RuleSetOptions
{
    private const string DEEP_OBJECT_ARRAYS = 'lara-spec-first.query.deep_object_arrays';

    private const string PATCH = 'lara-spec-first.requests.patch';

    public function __construct(
        public DeepObjectArrays $deepObjectArrays = DeepObjectArrays::Brackets,
        public PatchReading $patchReading = PatchReading::Partial,
    ) {}

    /**
     * A missing or `null` value is the default. Any other value the package has no reading for is refused, because a
     * typo would otherwise quietly mean the default.
     *
     * @throws UnusableSettingException
     */
    public static function fromConfiguration(Repository $config): self
    {
        return new self(
            self::reading($config, self::DEEP_OBJECT_ARRAYS, DeepObjectArrays::class, DeepObjectArrays::Brackets, 'one of "brackets", "comma" or "any"'),
            self::reading($config, self::PATCH, PatchReading::class, PatchReading::Partial, 'one of "partial" or "as_written"'),
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  T  $default
     * @return T
     *
     * @throws UnusableSettingException
     */
    private static function reading(Repository $config, string $key, string $enum, \BackedEnum $default, string $expected): \BackedEnum
    {
        $value = $config->get($key);

        if ($value === null) {
            return $default;
        }

        $reading = is_string($value) ? $enum::tryFrom($value) : null;

        if ($reading === null) {
            throw UnusableSettingException::setting($key, $expected);
        }

        return $reading;
    }
}
