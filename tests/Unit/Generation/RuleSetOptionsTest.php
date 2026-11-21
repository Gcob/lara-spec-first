<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Generation\DeepObjectArrays;
use Gcob\LaraSpecFirst\Generation\PatchReading;
use Gcob\LaraSpecFirst\Generation\RuleSetOptions;
use Illuminate\Config\Repository;

/*
 * The one setting that reaches the rule set, read the way every command reads it. A missing key or a `null` is the
 * default, and any other value that is not a reading the package has is refused with the key named: a typo would
 * otherwise quietly mean `brackets`.
 *
 * @see docs/guide/code-generation/request-validation.md — "Arrays inside a deepObject"
 */
it('reads query.deep_object_arrays from the configuration', function (array $config, DeepObjectArrays $reading): void {
    expect(RuleSetOptions::fromConfiguration(new Repository($config))->deepObjectArrays)->toBe($reading);
})->with([
    'no key at all' => [[], DeepObjectArrays::Brackets],
    'no query block' => [['lara-spec-first' => ['spec' => ['path' => 'openapi.yaml']]], DeepObjectArrays::Brackets],
    'a null value' => [['lara-spec-first' => ['query' => ['deep_object_arrays' => null]]], DeepObjectArrays::Brackets],
    'brackets' => [['lara-spec-first' => ['query' => ['deep_object_arrays' => 'brackets']]], DeepObjectArrays::Brackets],
    'comma' => [['lara-spec-first' => ['query' => ['deep_object_arrays' => 'comma']]], DeepObjectArrays::Comma],
    'any' => [['lara-spec-first' => ['query' => ['deep_object_arrays' => 'any']]], DeepObjectArrays::Any],
]);

it('refuses a value of query.deep_object_arrays that is no reading, naming the key and the three readings', function (mixed $value): void {
    expect(fn () => RuleSetOptions::fromConfiguration(new Repository(['lara-spec-first' => ['query' => ['deep_object_arrays' => $value]]])))
        ->toThrow(UnusableSettingException::class, 'lara-spec-first.query.deep_object_arrays')
        ->and(fn () => RuleSetOptions::fromConfiguration(new Repository(['lara-spec-first' => ['query' => ['deep_object_arrays' => $value]]])))
        ->toThrow(UnusableSettingException::class, 'one of "brackets", "comma" or "any"');
})->with([
    'a typo' => ['bracket'],
    'an integer' => [5],
    'a list' => [['brackets']],
    'the empty string' => [''],
]);

/*
 * `requests.patch` is the second setting: how a `PATCH` body's required list is read when its media type does not say.
 * It is read from the same object, and a key set beside the other one changes neither reading.
 *
 * @see docs/guide/code-generation/request-validation.md — "A partial update empties the required list"
 */
it('reads requests.patch from the configuration', function (array $config, PatchReading $reading): void {
    expect(RuleSetOptions::fromConfiguration(new Repository($config))->patchReading)->toBe($reading);
})->with([
    'no key at all' => [[], PatchReading::Partial],
    'no requests block' => [['lara-spec-first' => ['query' => ['deep_object_arrays' => 'any']]], PatchReading::Partial],
    'a null value' => [['lara-spec-first' => ['requests' => ['patch' => null]]], PatchReading::Partial],
    'partial' => [['lara-spec-first' => ['requests' => ['patch' => 'partial']]], PatchReading::Partial],
    'as_written' => [['lara-spec-first' => ['requests' => ['patch' => 'as_written']]], PatchReading::AsWritten],
]);

it('reads requests.patch beside query.deep_object_arrays without one changing the other', function (): void {
    $options = RuleSetOptions::fromConfiguration(new Repository(['lara-spec-first' => [
        'query' => ['deep_object_arrays' => 'comma'],
        'requests' => ['patch' => 'as_written'],
    ]]));

    expect($options->deepObjectArrays)->toBe(DeepObjectArrays::Comma)
        ->and($options->patchReading)->toBe(PatchReading::AsWritten);
});

it('refuses a value of requests.patch that is no reading, naming the key and the two readings', function (mixed $value): void {
    $read = fn () => RuleSetOptions::fromConfiguration(new Repository(['lara-spec-first' => ['requests' => ['patch' => $value]]]));

    expect($read)->toThrow(UnusableSettingException::class, 'lara-spec-first.requests.patch')
        ->and($read)->toThrow(UnusableSettingException::class, 'one of "partial" or "as_written"');
})->with([
    'a typo' => ['as-written'],
    'an integer' => [5],
    'a list' => [['partial']],
    'the empty string' => [''],
]);

it('refuses a value of requests.patch even when query.deep_object_arrays is fine', function (): void {
    expect(fn () => RuleSetOptions::fromConfiguration(new Repository(['lara-spec-first' => [
        'query' => ['deep_object_arrays' => 'comma'],
        'requests' => ['patch' => 'sometimes'],
    ]])))->toThrow(UnusableSettingException::class, 'lara-spec-first.requests.patch');
});
