<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Generation\DeepObjectArrays;
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
    'any' => [['lara-spec-first' => ['query' => ['deep_object_arrays' => 'any']]], DeepObjectArrays::Any],
]);

it('refuses a value of query.deep_object_arrays that is no reading, naming the key', function (mixed $value): void {
    expect(fn () => RuleSetOptions::fromConfiguration(new Repository(['lara-spec-first' => ['query' => ['deep_object_arrays' => $value]]])))
        ->toThrow(UnusableSettingException::class, 'lara-spec-first.query.deep_object_arrays');
})->with([
    'a typo' => ['bracket'],
    'an integer' => [5],
    'a list' => [['brackets']],
    'the empty string' => [''],
    // Read by a later change; until then it is refused like any value the package has no reading for.
    'comma' => ['comma'],
]);
