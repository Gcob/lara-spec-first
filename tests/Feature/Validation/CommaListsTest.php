<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Validation\CommaLists;

/*
 * The split a generated request makes, under `query.deep_object_arrays: comma`, of an array a client wrote as one string.
 * Plain arrays in and out: what Laravel does with the result is the kernel test's.
 */
it('splits a comma list', function (array $query, array $lists, array $expected): void {
    /** @var list<array{steps: list<string>, spared?: array<int, list<string>>}> $lists */
    expect(CommaLists::split($query, $lists))->toBe($expected);
})->with([
    'two items' => [
        ['filter' => ['ids' => '1,2']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1', '2']]],
    ],
    'one item' => [
        ['filter' => ['ids' => '1']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1']]],
    ],
    'the empty string, which is how a comma list writes an empty list' => [
        ['filter' => ['ids' => '']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => []]],
    ],
    'null, which is what the empty string becomes under ConvertEmptyStringsToNull' => [
        ['filter' => ['ids' => null]],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => []]],
    ],
    'a list written with brackets is left as sent' => [
        ['filter' => ['ids' => ['1', '2']]],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1', '2']]],
    ],
    'a list written with brackets, with a comma in an item, is left as sent' => [
        ['filter' => ['ids' => ['1,2']]],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1,2']]],
    ],
    'a space is kept' => [
        ['filter' => ['ids' => '1, 2']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1', ' 2']]],
    ],
    'a name that is not asked about' => [
        ['filter' => ['ids' => '1,2', 'q' => 'a,b'], 'page' => '1,2'],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1', '2'], 'q' => 'a,b'], 'page' => '1,2'],
    ],
    'a missing list' => [
        ['filter' => ['q' => 'a,b']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['q' => 'a,b']],
    ],
    'a missing object' => [
        ['page' => '2'],
        [['steps' => ['filter', 'ids']]],
        ['page' => '2'],
    ],
    'an object that is a string' => [
        ['filter' => '1,2'],
        [['steps' => ['filter', 'ids']]],
        ['filter' => '1,2'],
    ],
    'a name with a dot is one step, not a nesting' => [
        ['filter' => ['a.b' => '1,2', 'a' => ['b' => '3,4']]],
        [['steps' => ['filter', 'a.b']]],
        ['filter' => ['a.b' => ['1', '2'], 'a' => ['b' => '3,4']]],
    ],
    'every value of a map' => [
        ['filter' => ['x' => '1,2', 'y' => '3']],
        [['steps' => ['filter', '*']]],
        ['filter' => ['x' => ['1', '2'], 'y' => ['3']]],
    ],
    'every value of a map, but the declared name' => [
        ['meta' => ['id' => 'a,b', 'n' => '1,2']],
        [['steps' => ['meta', '*'], 'spared' => [1 => ['id']]]],
        ['meta' => ['id' => 'a,b', 'n' => ['1', '2']]],
    ],
    'a declared name that is an integer as an array key' => [
        ['meta' => ['2024' => 'a,b', 'n' => '1,2']],
        [['steps' => ['meta', '*'], 'spared' => [1 => ['2024']]]],
        ['meta' => [2024 => 'a,b', 'n' => ['1', '2']]],
    ],
    'the list of every object of a list' => [
        ['filter' => ['rows' => [['tags' => 'p,q'], ['tags' => 'r']]]],
        [['steps' => ['filter', 'rows', '*', 'tags']]],
        ['filter' => ['rows' => [['tags' => ['p', 'q']], ['tags' => ['r']]]]],
    ],
    'an array of arrays, each written as a string' => [
        ['filter' => ['m' => ['1,2', '3']]],
        [['steps' => ['filter', 'm', '*']]],
        ['filter' => ['m' => [['1', '2'], ['3']]]],
    ],
    'an array of arrays, written as one string, is split once' => [
        ['filter' => ['m' => '1,2']],
        [['steps' => ['filter', 'm']], ['steps' => ['filter', 'm', '*']]],
        ['filter' => ['m' => ['1', '2']]],
    ],
    'an array of arrays, whichever order the lists are written in' => [
        ['filter' => ['m' => ['1,2', '3']]],
        [['steps' => ['filter', 'm', '*']], ['steps' => ['filter', 'm']]],
        ['filter' => ['m' => [['1', '2'], ['3']]]],
    ],
    // An empty item is `null`, as `?filter[ids][]=` is under ConvertEmptyStringsToNull: Laravel skips a rule that is not
    // implicit on a value that trims to the empty string, so the item rules would judge `""` as no value at all.
    'an empty item in the middle' => [
        ['filter' => ['ids' => '1,,2']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1', null, '2']]],
    ],
    'an empty item at the end' => [
        ['filter' => ['ids' => '1,']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => ['1', null]]],
    ],
    'a comma alone' => [
        ['filter' => ['ids' => ',']],
        [['steps' => ['filter', 'ids']]],
        ['filter' => ['ids' => [null, null]]],
    ],
    'an item of spaces' => [
        ['filter' => ['tags' => 'ab, ,cd']],
        [['steps' => ['filter', 'tags']]],
        ['filter' => ['tags' => ['ab', null, 'cd']]],
    ],
]);
