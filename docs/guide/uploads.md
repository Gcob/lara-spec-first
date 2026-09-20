---
title: File Uploads
audience: Users
covers: >
    What the package does with a `multipart/form-data` body and what it refuses to decide about it: why one operation
    declares one body media type and what two of them cost, which part of a schema says a property is a file in 3.0 and
    in 3.1, which Laravel rules a part becomes and the two no contract can ask for, why nothing is stored until a
    project configures a disk, the trait that decides what lands in the column and why it is a trait with no interface
    beside it, why an upload is not a driver, and what a base64 part inside a JSON body gets instead.
read_before: >
    Implementing the multipart half of the `FormRequest` emitter, or the storage seam a generated CRUD default calls.
tags: [openapi, code-generation, laravel, decisions, scope]
---

# File Uploads

> **In brief**
>
> - **Not built yet.** Nothing reads a multipart body today; this is the design Phase 2 will follow.
> - A part is a file when the schema says so, in either OpenAPI spelling, and it becomes `file`, `mimetypes` and a size
>   in kilobytes.
> - One operation declares one body media type. Two of them, carrying different shapes, is a build error naming both.
> - Nothing stores anything until a project configures a disk. Until it does, an upload operation answers `501`.
> - This is not a driver, and the reason is that OpenAPI already names an upload the same way in every specification.

An upload is the one body shape where the contract stops short of what the code has to decide. The specification says a
part is a file and what it may contain; it never says where the file goes. This document owns both halves: what the
build does with the part, and what it deliberately leaves to the project.

> **None of this is behavior yet.** No multipart body is read, no file rule is generated, and no configuration key names
> a disk. What is written here is the design [Phase 2](../../README.md#phase-2-the-generated-pipeline) will follow,
> alongside [request validation](./code-generation/request-validation.md) and the
> [CRUD defaults](./controllers.md#x-model-turns-on-the-model-layer) it attaches to. Items marked `Open` are undecided,
> and the number beside one links to the card that settles it.

## One operation, one media type

**A request body declares one media type, and an operation declaring two different shapes is a build error naming
both.** Not a precedence rule, and not a media type this package picked for everybody.

The reason is the rule set. One operation generates one `rules()`, and
[nothing rewrites it per request](./code-generation/request-validation.md#nothing-rewrites-the-rule-set), so two media
types carrying two schemas is two rule sets the generated class cannot both hold. Reading one and reporting the other
would serve half the contract under a `200`, which is the outcome [rule 2](./openapi-support.md#the-four-rules) exists
against. The build refuses instead, the same shape as
[a field named twice](./code-generation/request-validation.md#a-name-declared-twice-is-refused).

**Two media types pointing at one schema are not a conflict.** `application/json` and
`application/x-www-form-urlencoded` over the same `$ref` produce one rule set that serves both, because `all()` carries
them identically. What the build refuses is two shapes, not two spellings of one.

**And the media type stays the author's choice, not ours.** Refusing everything but `application/json` would be this
package deciding an API's wire format, and it would push every upload through base64, which is
[the worse path in PHP](#a-base64-part-is-a-string). `multipart/form-data` is what Laravel's whole file stack is built
for, so the package meets the contract where the framework already is.

## A part is a file when the schema says so

**Either OpenAPI spelling names one, and they normalize to one notion.** `format: binary` in 3.0, `contentMediaType` in
3.1, which dropped `format: binary` with the rest of JSON Schema 2020-12's format vocabulary. That difference lives
behind [the version strategy](./openapi-support.md#handling-30-and-31) like every other, which is
[#34](https://github.com/Gcob/lara-spec-first/issues/34)'s to normalize, and it is the one place a keyword
[the parser hands back raw](./openapi-support.md#schemas) has to be read rather than ignored.

**What a part becomes:**

| The contract states                                    | Laravel rule                                                                                 |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| `format: binary`, or `contentMediaType`                | `file`                                                                                       |
| `encoding.<part>.contentType`, else `contentMediaType` | `mimetypes:` with the declared types, `image/*` included, since Laravel matches a wildcard   |
| `maxLength` on a binary part                           | `max:` in kilobytes, the contract's bytes over 1024, rounded down                            |
| The part is in the body root's `required` list         | `required` beside the file rules                                                             |
| The part is not in it                                  | `sometimes`, and nothing about `null` unless the schema itself says the property is nullable |
| `items` whose schema is a binary part                  | The file rules under `part.*`, plus `min` and `max` from `minItems` and `maxItems`           |

**Presence is read the same way as for any other property.** The body root's `required` list decides it, so
[a `PATCH` empties that list](./code-generation/request-validation.md#patch-empties-the-required-list) for a file part
exactly as it does for a string, and the DTO's third state is what carries "the client sent no avatar". Nullability
stays the schema's own axis: a rule set that added `nullable` to every optional part would be
[conflating the two](./code-generation/request-validation.md#a-patch-takes-the-partial-type) and accepting an explicit
`null` the contract never allowed.

**`mimetypes:` and never `mimes:`, and the difference is what each one reads.** `mimetypes:image/png` inspects the
file's actual media type; `mimes:png` matches an extension against a table Laravel keeps. OpenAPI states media types, so
`mimetypes` is a translation and `mimes` would be a guess: the package would have to own a media-type-to-extension
table, pick one extension per type, and turn away a valid file whose name says nothing about its content. The wildcard
case is verified against the framework rather than assumed: `mimetypes:image/*` matches, because Laravel compares the
type's first segment plus `/*` beside the exact values.

**Rounded down, because rounding up accepts a file the contract refused.** A `maxLength` beside a `contentEncoding` is
reported instead of translated: it then counts the characters of an encoded string rather than the bytes of a file, and
reading it as bytes would refuse a quarter of what the contract allows.

**Two rules Laravel has and no contract can ask for:** `image` and `dimensions`. `image` is a looser
`mimetypes:image/*`, so emitting it beside the declared types adds a second answer rather than a stricter one, and
OpenAPI has no vocabulary for a pixel size at all. A contract needing either is describing something
[the rule set cannot carry](./code-generation/request-validation.md#every-constraint-maps-or-reports), and the doctor
says so rather than the build inventing it.

**Several files are two rule keys, the array and its elements**, which is the dot notation the rest of the rule set
already uses:

```php
'photos' => ['required', 'array', 'min:1', 'max:5'],
'photos.*' => ['file', 'mimetypes:image/jpeg,image/png', 'max:2048'],
```

**Watch the two `max` in that example: they measure different things.** On the array it counts files, from `maxItems`;
on an element it counts kilobytes, from `maxLength`. It is one rule name and two units, because Laravel sizes a value by
what the value is, and it is the one place a reader of generated rules can misread a number.

**An empty part is not an absent one.** A client that submits a file field with nothing in it has sent something, so
`sometimes` does not skip the part and `file` refuses it: a 422 naming the part, which is the honest answer. Papering
over it with `nullable` would accept a payload the contract does not describe, and the contract is what the rule set
answers to.

**The validated part reaches the controller as an `UploadedFile`**, on the
[input DTO](./code-generation/request-validation.md#the-payload-arrives-as-a-dto) like every other property, because
`Request::all()` carries files and `validated()` hands them back with everything else.

## Storing is configured, never guessed

**No disk is configured by default, and until one is, an operation whose body carries a file part answers
[501](./code-generation/scaffolding.md#an-unimplemented-operation-answers-501).** Same shape as
[the allowed hosts a remote reference needs](./remote-references.md): the key exists, it is empty, and nothing happens
until a project says what it wants.

That is the honest default because a file is not a column. Which disk, which name, which visibility, whether a
conversion is queued, whether the column holds a path or the id of a row in somebody's media table: none of it is in the
contract, and a build that picked one would be inventing persistence rather than deriving it.

**Configured, the generated CRUD default stores the part and mass-assigns what came back:**

```php
// app/Http/Generated/Controllers/CreateUserController.php
protected function create(CreateUserInputDto $data): UserDto
{
    $attributes = $data->toArray();
    $attributes['avatar'] = $this->storeUpload($data->avatar, 'avatar');

    return UserDtoFactory::from($this->getQuery()->create($attributes));
}
```

**An absent part is not stored**, which falls out of
[the DTO's third state](./code-generation/request-validation.md#an-absent-field-is-a-third-state) rather than needing a
rule of its own: a `PATCH` that sent no avatar leaves both the file and the column alone.

**Open ([#55](https://github.com/Gcob/lara-spec-first/issues/55)):** the key's name and what else sits in its block, a
default visibility among them. It joins the configuration blocks that are inert until Phase 2, and it is public API
surface under [rule 4](./openapi-support.md#the-four-rules) from the first release.

### The trait is the seam

**`storeUpload()` comes from a trait the generated controller uses, and overriding it is how a project decides what
lands in the column.** Its default stores the file on the configured disk and returns the path, which is the answer that
covers a plain `avatar` column and nothing more.

```php
// In the custom child, for a project on a media library.
protected function storeUpload(UploadedFile $file, string $property): mixed
{
    return $this->model->addMedia($file)->toMediaCollection($property)->uuid;
}
```

**A trait with no interface beside it, and the reason is a rule this package already applies.** An interface may only
declare what every implementation can sign, and
[a method receiving a bound value cannot be a contract](./controllers.md#an-interface-and-a-trait-beside-it): the file
arrives as an `UploadedFile`, the property name beside it, and the return type is whatever the project's column holds.
There is nothing narrow enough to declare, so the trait ships the default and nothing pretends to type the rest. It is
the same split as [`InteractsWithModel`](./controllers.md#an-interface-and-a-trait-beside-it), where the shared default
lives in the trait and stays the seam a project overrides most often.

### The doctor checks the column

**The property name is the column name, and that assumption is checked rather than trusted.** `storeUpload()`'s return
value is mass-assigned under the part's own name, so
[the mass-assignment comparison](./controllers.md#writes-by-the-same-default) covers it like every other field: a part
named `avatar` on a model whose `$fillable` has no `avatar` is a finding, reported by name, before anybody wonders why
the upload succeeded and the column stayed empty.

## An upload is not a driver

**[The driver test](./drivers.md#when-a-feature-needs-a-driver) is one question: is there a name that means the same
thing across specifications? For an upload there is, so the package matches it.** `multipart/form-data`,
`format: binary` and `contentMediaType` mean the same thing in every document that uses them, which is precisely the
case `drivers.md` says to match rather than to drive.

**The other half of the mechanism is missing too.** A driver answers where in the document a structure is declared, and
OpenAPI already answers that here. What is genuinely unsettled about an upload is what the application does with the
file, which is not a question about the document at all, and
[the driver mechanism is not a general plugin system](./drivers.md#what-this-document-does-not-cover).

**So the seam is a trait and a config key rather than a driver**, which is the shape this package already uses for
everything whose variability is in the application instead of in the contract. Naming it a driver would promise a
document-reading extension point that has nothing to read, and would put a class registration in front of a developer
who needs one method.

## A base64 part is a string

**A file carried inside a JSON body is a string, validated as one, and nothing decodes it.**
[`contentEncoding` is not acted on](./openapi-support.md#content), so `format: byte` or `contentEncoding: base64` gets
the string rules the schema states and no `file`, no `mimetypes`, no size in kilobytes.

**That is a position rather than a gap, and the cost of the alternative is what settles it.** Base64 inside JSON is a
third larger on the wire, and PHP holds the whole payload in memory to decode it where a multipart upload streams to a
temporary file the framework then hands over. `post_max_size` and the memory limit are what a large base64 body meets,
rather than `upload_max_filesize`. A contract whose API must stay JSON is free to carry one; what it gets from this
package is the string, and turning it into a file is the controller's.

## What this document does not cover

Three things a reader arrives here wanting, each owned elsewhere:

1. **How the rest of the rule set is built.** The merge of body and parameters, the `PUT` and `PATCH` difference and
   every other constraint's mapping are [`request-validation.md`](./code-generation/request-validation.md)'s, and this
   page adds only what a file part changes.
2. **What a CRUD default does with everything that is not a file.** Mass assignment, the seams around it and the `501`
   an operation answers before anybody implements it belong to
   [`controllers.md`](./controllers.md#writes-by-the-same-default).
3. **Serving a stored file back.** A response carrying a URL is a string in a response schema like any other, so it is
   [`response-dtos.md`](./code-generation/response-dtos.md)'s, and nothing here generates a download route.
