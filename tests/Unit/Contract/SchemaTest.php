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

// Where an ignored keyword is written is a fact about the document's layout, so
// two inline copies of one shape stay one shape, at any depth. Anything a rule
// could be built from still tells two schemas apart.
it('compares two schemas by what they state rather than by where an ignored keyword is written', function (): void {
    $copy = static fn (string $at, string $type): Schema => new Schema(
        types: [SchemaType::Object],
        properties: [
            'a' => new Schema(types: [SchemaType::String], ignored: ['default' => $at.'/properties/a/default']),
            'b' => new Schema(types: [SchemaType::Array], items: new Schema(types: [SchemaType::from($type)], ignored: ['not' => $at.'/b/items/not'])),
        ],
        ignored: ['oneOf' => $at.'/oneOf'],
    );

    expect($copy('#/json', 'string')->sameShapeAs($copy('#/form', 'string')))->toBeTrue()
        ->and($copy('#/json', 'string')->sameShapeAs($copy('#/json', 'integer')))->toBeFalse()
        ->and((new Schema(properties: ['a' => new Schema, 'b' => new Schema]))->sameShapeAs(
            new Schema(properties: ['b' => new Schema, 'a' => new Schema]),
        ))->toBeTrue();
});

// `sameShapeAs()` forgives the order a map was written in and the planner of the input DTOs does not, since
// it emits properties in that order. Both leave an ignored keyword's position out, and both are strict about
// values: `[0]` and `[false]` are `==`, but `1.0` is `1`, as JSON Schema compares numbers.
it('keys a shape without its ignored keywords, strictly, and in order unless told otherwise', function (): void {
    $ab = new Schema(properties: ['a' => new Schema(ignored: ['default' => '#/x']), 'b' => new Schema]);
    $ba = new Schema(properties: ['b' => new Schema, 'a' => new Schema(ignored: ['default' => '#/y'])]);

    expect($ab->shapeKey())->toBe((new Schema(properties: ['a' => new Schema, 'b' => new Schema]))->shapeKey())
        ->and($ab->shapeKey())->not->toBe($ba->shapeKey())
        ->and($ab->shapeKey(ordered: false))->toBe($ba->shapeKey(ordered: false))
        ->and((new Schema(enum: [0]))->shapeKey())->not->toBe((new Schema(enum: [false]))->shapeKey())
        ->and((new Schema(enum: [0]))->sameShapeAs(new Schema(enum: [false])))->toBeFalse()
        ->and((new Schema(enum: [1]))->sameShapeAs(new Schema(enum: [1.0])))->toBeTrue()
        ->and((new Schema(enum: [1]))->sameShapeAs(new Schema(enum: [1.5])))->toBeFalse();
});
