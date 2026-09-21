<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;

// One case per schema caveat, partitioned by the caveat rather than by the
// document that happens to show it — so coverage here can be argued rather than
// counted, which is what separates this suite from the unit tests beside it.
//
// The four caveats are the ones openapi-support.md#parser-caveats records, and
// they are what makes a normal form necessary in the first place: `type` may be
// a string or a list, nullability has two spellings, `exclusiveMinimum` carries
// two meanings under one name, and a keyword the parser does not model comes
// back raw. VersionEquivalenceTest.php asserts that the two versions agree;
// this file asserts *what* they agree on, which a symmetric bug in both
// strategies would otherwise hide.
//
// @see docs/guide/openapi-support.md — "The normal form a schema takes"
// @see AGENTS.md — "Automated tests are required"

/**
 * The body schema of the one operation a fixture declares a body for.
 */
function bodySchemaOf(string $fixture, string $mediaType = 'application/json'): Schema
{
    $operations = array_values(array_filter(
        extractFixture($fixture),
        static fn (Operation $operation): bool => $operation->requestBody !== null
    ));

    expect($operations)->not->toBeEmpty();

    $body = $operations[0]->requestBody;
    assert($body !== null);

    return $body->content[$mediaType];
}

// Caveat one: the parser declares `type` a string and does not enforce it, so a
// 3.1 list arrives unchecked. Both spellings become a list, and a caller that
// wanted to branch on the document version has nothing to branch on.
it('reads a type as a list whichever version wrote it', function (string $fixture): void {
    $schema = bodySchemaOf($fixture);

    expect($schema->types)->toBe([SchemaType::Object])
        ->and($schema->properties['tags']->types)->toBe([SchemaType::Array])
        ->and($schema->properties['tags']->items?->types)->toBe([SchemaType::String]);
})->with(['equivalence/same-contract-3.0.yaml', 'equivalence/same-contract-3.1.yaml']);

// Caveat two: `nullable: true` and a `"null"` member of the type union are one
// idea, and the normal form is the union. Reading it back is a question about
// the type list rather than about a second field.
it('spells nullability as a member of the type list', function (string $fixture): void {
    $name = bodySchemaOf($fixture)->properties['name'];

    expect($name->types)->toBe([SchemaType::String, SchemaType::Null])
        ->and($name->isNullable())->toBeTrue()
        ->and($name->soleType())->toBe(SchemaType::String);
})->with(['equivalence/same-contract-3.0.yaml', 'equivalence/same-contract-3.1.yaml']);

// Caveat three: 3.0 writes a boolean modifying the bound beside it, 3.1 writes
// the bound itself. The normal form is 3.1's, so the 3.0 document's `minimum`
// has moved rather than being kept alongside — keeping both would state one
// bound twice and let a generator emit `gte` and `gt` for one constraint.
it('normalizes an exclusive bound to the number that stands on its own', function (string $fixture): void {
    $age = bodySchemaOf($fixture)->properties['age'];

    expect($age->exclusiveMinimum)->toBe(0.0)
        ->and($age->minimum)->toBeNull();
})->with(['equivalence/same-contract-3.0.yaml', 'equivalence/same-contract-3.1.yaml']);

// Caveat four, first half: `const` and the 3.1 `examples` list are raw — the
// parser hands them back as plain values — and both are honored, because they
// carry data rather than a schema. A `const` is a one-value `enum` and is read
// as one.
it('honors the raw keywords that carry data', function (string $fixture): void {
    $schema = bodySchemaOf($fixture);

    expect($schema->properties['role']->enum)->toBe(['admin'])
        ->and($schema->examples)->toBe([['name' => 'Ada']]);
})->with(['equivalence/same-contract-3.0.yaml', 'equivalence/same-contract-3.1.yaml']);

// Caveat four, second half, and the dangerous one: a raw keyword carrying a
// *schema* is ignored, so nothing may reach the normal form from it. The test
// is a negative one on purpose — what would go wrong is a value appearing where
// the matrix says nothing should, and no positive assertion would notice.
it('carries nothing from a raw keyword the matrix ignores', function (): void {
    $pair = bodySchemaOf('schema-raw-keywords.yaml')->properties['pair'];

    expect($pair->types)->toBe([SchemaType::Array])
        ->and($pair->minItems)->toBe(2)
        ->and($pair->items)->toBeNull()
        ->and($pair->properties)->toBe([])
        ->and($pair->allOf)->toBe([]);
});

// The file part, which is the one place a raw keyword has to be *read* rather
// than ignored: `format: binary` at 3.0 and `contentMediaType` at 3.1 name one
// notion, and uploads.md is written against that one notion.
it('reads a file part from either spelling', function (string $fixture): void {
    $avatar = bodySchemaOf($fixture, 'multipart/form-data')->properties['avatar'];

    expect($avatar->isFilePart)->toBeTrue()
        ->and($avatar->format)->toBeNull();
})->with(['file-part-3.0.yaml', 'file-part-3.1.yaml']);

// A self-referential schema is a supported contract and an infinite object
// graph. The walk has to terminate, and it has to say where it stopped rather
// than truncating in silence — which is the difference between a value that is
// incomplete and one that is wrong.
it('cuts a recursive schema and names where it cut', function (): void {
    $children = bodySchemaOf('recursive-schema-in-body.yaml')->properties['children'];

    expect($children->items?->recursesTo)->toBe('#/components/schemas/Node')
        ->and($children->items?->types)->toBe([]);
});

// A keyword written with `null` as its value is written, not absent, and both
// of these say something a generator reads: `const: null` constrains the value
// to null, and an example of null is a declared example rather than a missing
// one. They are here rather than in a strategy unit test because what lost them
// was the walk deciding what to hand over, which a test working on a keyword
// map it built itself cannot see.
it('keeps a keyword the document wrote as null', function (): void {
    $properties = bodySchemaOf('null-valued-keywords.yaml')->properties;

    expect($properties['unset']->enum)->toBe([null])
        ->and($properties['nickname']->examples)->toBe([null]);
});

// The other half, and the one that makes the assertion above mean something: a
// schema that writes neither keyword is distinguishable from one that writes
// them as null.
it('still says nothing for a keyword the document left out', function (): void {
    $nickname = bodySchemaOf('null-valued-keywords.yaml')->properties['nickname'];

    expect($nickname->enum)->toBeNull()
        ->and($nickname->properties)->toBe([]);
});

// An absent `additionalProperties` is not a document allowing unknown fields,
// it is a document that said nothing. The parser defaults the keyword to true
// and cannot tell the two apart; reading what was written rather than what the
// parser answers is what keeps the difference.
it('separates an unstated additionalProperties from a written one', function (): void {
    expect(bodySchemaOf('null-valued-keywords.yaml')->additionalProperties)->toBeNull()
        ->and(bodySchemaOf('schema-raw-keywords.yaml')->additionalProperties)->toBeNull();
});
