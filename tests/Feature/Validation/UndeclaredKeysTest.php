<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Validation\UndeclaredKeys;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/*
 * The rule a generated request puts on the wildcard of a map that sits beside declared keys: the value rules judge
 * every key but the declared ones, so a declared key keeps exactly its own rules whatever the order of the rule array.
 * Run through a real validator, since what matters is what Laravel does with a compiled rule under a wildcard.
 *
 * @see docs/guide/code-generation/request-validation.md — "A map's values are validated one by one"
 */

/**
 * The messages `$data` fails with against `$rules`, by attribute, and the data a validator that passed holds.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $rules
 * @return array{errors: list<string>, validated: array<string, mixed>|null}
 */
function judgedByUndeclaredKeys(array $data, array $rules): array
{
    $validator = Validator::make($data, $rules);

    if ($validator->fails()) {
        $failed = array_keys($validator->errors()->toArray());
        sort($failed);

        return ['errors' => $failed, 'validated' => null];
    }

    return ['errors' => [], 'validated' => $validator->validated()];
}

it('judges an undeclared key by the value rules and a declared key by its own rules, whatever the order', function (bool $guardFirst): void {
    $own = ['meta.a' => ['sometimes', 'string', 'max:3']];
    $guard = ['meta.*' => new UndeclaredKeys([1 => ['a']], ['integer:strict'])];
    $rules = ['meta' => ['sometimes', 'array'], ...($guardFirst ? [...$guard, ...$own] : [...$own, ...$guard])];

    $answer = judgedByUndeclaredKeys(['meta' => ['a' => 'xyzw', 'b' => 1, 'c' => 'y']], $rules);

    // `a` is refused for its own `max:3` and `c` for the value schema's `integer`, and `a` is never an integer: the
    // declared key does not get the value rules, and `b` passes. Without the guard `a` fails `integer` too.
    expect($answer['errors'])->toBe(['meta.a', 'meta.c']);
})->with(['the guard before the declared key' => [true], 'the guard after it' => [false]]);

it('names only the failed rule of a declared key, never the value rule', function (): void {
    $validator = Validator::make(
        ['meta' => ['a' => 'xyzw', 'b' => 1]],
        [
            'meta' => ['array'],
            'meta.a' => ['string', 'max:3'],
            'meta.*' => new UndeclaredKeys([1 => ['a']], ['integer:strict']),
        ],
    );

    expect($validator->errors()->get('meta.a'))->toBe(['The meta.a field must not be greater than 3 characters.']);
});

it('keeps the declared and the undeclared keys in validated()', function (): void {
    $answer = judgedByUndeclaredKeys(
        ['meta' => ['a' => 'x', 'b' => 1, 'c' => 2]],
        [
            'meta' => ['array'],
            'meta.a' => ['sometimes', 'string'],
            'meta.*' => new UndeclaredKeys([1 => ['a']], ['integer:strict']),
        ],
    );

    expect($answer['validated'])->toBe(['meta' => ['a' => 'x', 'b' => 1, 'c' => 2]]);
});

it('spares the declared key of each level of a value that is a map itself', function (): void {
    // `meta.*.*` is the values of the values: the first step spares `id`, the second one `n`.
    $guard = static fn (): UndeclaredKeys => new UndeclaredKeys([1 => ['id'], 2 => ['n']], ['integer:strict']);
    $rules = [
        'meta' => ['array'],
        'meta.id' => ['string'],
        'meta.*' => new UndeclaredKeys([1 => ['id']], ['array']),
        'meta.*.n' => ['string'],
        'meta.*.*' => $guard(),
    ];

    $answer = judgedByUndeclaredKeys(['meta' => ['id' => 'x', 'v' => ['n' => 'ok', 'k' => 1, 'bad' => 'z']]], $rules);

    expect($answer['errors'])->toBe(['meta.v.bad']);
});

it('judges the object values of a map beside a declared object by their own children only for the undeclared keys', function (): void {
    $rules = [
        'meta' => ['array'],
        'meta.a' => ['array'],
        'meta.*' => new UndeclaredKeys([1 => ['a']], ['array', 'required_array_keys:n']),
        'meta.*.n' => new UndeclaredKeys([1 => ['a']], ['integer:strict']),
    ];

    $answer = judgedByUndeclaredKeys(['meta' => ['a' => ['n' => 'str'], 'b' => ['n' => 'z'], 'c' => []]], $rules);

    expect($answer['errors'])->toBe(['meta.b.n', 'meta.c']);
});

it('counts the step of a key below a list and below an escaped dot', function (string $key, array $data, array $failed): void {
    $rules = [$key => new UndeclaredKeys([str_contains($key, 'items') ? 3 : 1 => ['c']], ['integer:strict'])];

    expect(judgedByUndeclaredKeys($data, $rules)['errors'])->toBe($failed);
})->with([
    'below a list' => ['items.*.meta.*', ['items' => [['meta' => ['c' => 'x', 'b' => 'bad']]]], ['items.0.meta.b']],
    'below an escaped dot' => ['a\.b.*', ['a.b' => ['c' => 'x', 'd' => 'bad']], ['a.b.d']],
]);

it('spares a declared integer-like key written as a string, and not one written as an int', function (): void {
    $data = ['meta' => ['2024' => 'x', '1042' => 'bad']];

    expect(judgedByUndeclaredKeys($data, ['meta' => ['array'], 'meta.2024' => ['string'], 'meta.*' => new UndeclaredKeys([1 => ['2024']], ['integer:strict'])])['errors'])
        ->toBe(['meta.1042']);
});

it('judges every value rule, an enumeration among them, of an undeclared key', function (): void {
    $rules = ['meta' => ['array'], 'meta.*' => new UndeclaredKeys([1 => ['id']], ['string', Rule::in(['a', 'b'])])];

    expect(judgedByUndeclaredKeys(['meta' => ['id' => 'zzz', 'k' => 'a']], $rules)['errors'])->toBe([])
        ->and(judgedByUndeclaredKeys(['meta' => ['id' => 'zzz', 'k' => 'c']], $rules)['errors'])->toBe(['meta.k']);
});

it('raises nothing when the map is absent or not a map', function (mixed $meta): void {
    $data = $meta === '__absent' ? [] : ['meta' => $meta];
    $rules = ['meta' => ['sometimes', 'nullable', 'array'], 'meta.*' => new UndeclaredKeys([1 => ['id']], ['integer:strict'])];

    expect(judgedByUndeclaredKeys($data, $rules)['errors'])->toBe($meta === 'x' ? ['meta'] : []);
})->with([
    'absent' => ['__absent'],
    'an empty map' => [[]],
    'null' => [null],
    'a scalar, which its own rule refuses' => ['x'],
]);

it('judges the keys a root guard does not spare, beside a key of its own', function (): void {
    $rules = [
        'page' => ['sometimes', 'integer'],
        '*' => new UndeclaredKeys([0 => ['name', 'page']], ['integer:strict']),
    ];

    $answer = judgedByUndeclaredKeys(['name' => 'x', 'a' => 1, 'b' => 'bad', 'page' => 'abc'], $rules + ['name' => ['string']]);

    expect($answer['errors'])->toBe(['b', 'page']);
});

it('is the key\'s value, never an element of a list of rules', function (): void {
    // Laravel compiles a `CompilableRules` object under a wildcard only when it is the value itself.
    expect(fn () => Validator::make(
        ['meta' => ['a' => 1]],
        ['meta' => ['array'], 'meta.*' => [new UndeclaredKeys([1 => ['id']], ['integer:strict'])]],
    )->passes())->toThrow(BadMethodCallException::class);
});

// A spared key compiles to no rule at all: `validated()` keeps every data key that has a rule entry, so an empty entry
// would let a child of a declared object through that nothing judged (here `n`, which only the value schema names).
it('keeps a child of a declared key out of validated() when only the guarded rules name it', function (): void {
    $answer = judgedByUndeclaredKeys(
        ['meta' => ['a' => ['m' => 'ok', 'n' => ['evil' => 1], 'z' => 'z'], 'b' => ['n' => 1]]],
        [
            'meta' => ['array'],
            'meta.a' => ['array'],
            'meta.a.m' => ['string'],
            'meta.*' => new UndeclaredKeys([1 => ['a']], ['array']),
            'meta.*.n' => new UndeclaredKeys([1 => ['a']], ['integer:strict']),
        ],
    );

    expect($answer['validated'])->toBe(['meta' => ['a' => ['m' => 'ok'], 'b' => ['n' => 1]]]);
});
