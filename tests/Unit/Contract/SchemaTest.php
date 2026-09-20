<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;

it('says nothing when the document stated no type', function (): void {
    $schema = new Schema;

    expect($schema->types)->toBe([])
        ->and($schema->isNullable())->toBeFalse()
        ->and($schema->soleType())->toBeNull();
});

it('reads nullability out of the type list', function (): void {
    $schema = new Schema(types: [SchemaType::String, SchemaType::Null]);

    expect($schema->isNullable())->toBeTrue();
});

// The distinction that keeps every generator from filtering the list itself: a
// type that may also be null is one type, and a genuine union of two is not an
// answer this package can give.
it('separates a nullable type from a real union', function (array $types, ?SchemaType $expected): void {
    expect((new Schema(types: array_values($types)))->soleType())->toBe($expected);
})->with([
    'one type' => [[SchemaType::String], SchemaType::String],
    'nullable' => [[SchemaType::String, SchemaType::Null], SchemaType::String],
    'null alone' => [[SchemaType::Null], null],
    'a union of two' => [[SchemaType::String, SchemaType::Integer], null],
    'nothing stated' => [[], null],
]);

// The node a walk leaves behind where a schema pointed back at its own
// ancestor. It carries the pointer and nothing else, which is what keeps the
// tree finite.
it('carries only a pointer on a recursion marker', function (): void {
    $marker = Schema::recursion('#/components/schemas/Node');

    expect($marker->recursesTo)->toBe('#/components/schemas/Node')
        ->and($marker->types)->toBe([])
        ->and($marker->properties)->toBe([])
        ->and($marker->items)->toBeNull();
});
