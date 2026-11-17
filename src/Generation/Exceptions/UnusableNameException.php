<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use InvalidArgumentException;

/**
 * A generated name PHP cannot carry, or two operations claiming one.
 *
 * Both are refusals rather than guesses. Emitting a file whose class name does not parse would turn a contract problem
 * into a syntax error three steps away from its cause, and letting one generated class serve two operations would mean
 * the second silently wins.
 *
 * @see docs/guide/code-generation/generated-file-anatomy.md — "Naming, and the rename problem"
 */
final class UnusableNameException extends InvalidArgumentException implements SpecException
{
    public static function forOperation(string $identity, string $candidate): self
    {
        return new self(sprintf(
            'The operation "%s" would generate the class name "%s", which is not a usable PHP '.
            'identifier. Give the operation an `operationId` this package can turn into a class '.
            'name, or rename its path.',
            $identity,
            $candidate
        ));
    }

    public static function emptyOperationId(string $identity, string $operationId): self
    {
        return new self(sprintf(
            'The operation "%s" declares the `operationId` "%s", which leaves nothing behind once '.
            'it is turned into a class name. Every operation whose id reduces to nothing would '.
            'claim the same class, so this is refused rather than guessed at.',
            $identity,
            $operationId
        ));
    }

    /**
     * Two `x-controller` values that reduce to one generated parent.
     *
     * Its own message rather than the collision above, because the fix is a different one: these are names a developer
     * chose, and the advice to change an `operationId` would send them looking for a key neither operation has.
     *
     * @see docs/guide/controllers.md — "Two classes, found by name"
     */
    public static function parentClaimedTwice(string $shortName, string $first, string $second): self
    {
        return new self(sprintf(
            '`x-controller` names "%s" on one operation and "%s" on another, and both generate the '.
            'same parent "%s" in the generated tree. Two operations cannot share one generated '.
            'parent, so give one of them a class whose short name differs.',
            $first,
            $second,
            $shortName
        ));
    }

    /**
     * A custom controller declared inside the generated tree.
     *
     * @see docs/guide/code-generation/scaffolding.md — "Where your classes go"
     */
    public static function customControllerInsideGeneratedTree(
        string $identity,
        string $custom,
        string $namespace,
    ): self {
        return new self(sprintf(
            '`x-controller` on "%s" names "%s", which is inside the generated namespace `%s`. The '.
            'generated parent takes that same short name there, so the class would extend itself — '.
            'and a build rewrites everything under that namespace, so your work would not survive '.
            'one. Name a class in your application instead.',
            $identity,
            $custom,
            $namespace
        ));
    }

    /**
     * A path parameter the generated method cannot declare.
     *
     * `routeAction` takes one parameter per path parameter, named the way the document names it, so a name that is
     * legal in a route and illegal as a PHP variable has to be refused rather than written into a file that will not
     * parse.
     *
     * One path naming the same parameter twice needs no factory here: a {@see PathTemplate} refuses that where the path
     * is read, before anything asks what could be generated from it.
     *
     * @see docs/guide/controllers.md — "One controller, one routeAction"
     */
    public static function parameterNotAVariable(string $identity, string $parameter): self
    {
        return new self(sprintf(
            'The operation "%s" has the path parameter `{%s}`, which cannot be a PHP variable — so '.
            'the generated `routeAction` could not declare it. Rename it in the specification to '.
            'something starting with a letter or an underscore, and not `this`.',
            $identity,
            $parameter
        ));
    }

    /**
     * A path parameter named like the generated request's own parameter.
     *
     * `routeAction` declares the request as `$request` ahead of the path's parameters, so `{request}` on an operation
     * with something to validate would declare one variable twice, which PHP refuses at compile time.
     */
    public static function parameterShadowsRequest(string $identity): self
    {
        return new self(sprintf(
            'The operation "%s" has the path parameter `{request}`, and it also states something to '.
            'validate. The generated `routeAction` declares that request as `$request`, so the two '.
            'would be one variable declared twice. Rename the path parameter in the specification.',
            $identity
        ));
    }

    public static function claimedTwice(string $shortName, string $first, string $second): self
    {
        return new self(sprintf(
            'The operations "%s" and "%s" both generate the class name "%s". Two operations cannot '.
            'share one generated controller, so give at least one of them an `operationId` that '.
            'does not collide.',
            $first,
            $second,
            $shortName
        ));
    }

    /**
     * Two operations whose generated requests would be one class.
     *
     * **Its own factory rather than the one above, because it is reachable without that one firing and the advice
     * differs.** A request's name comes from the `operationId` alone, while a controller's may come from `x-controller`
     * — so two operations sharing an `operationId` and declaring different custom controllers collide here and nowhere
     * else. Telling their author that two operations cannot share one generated *controller* would send them looking at
     * the one thing that is fine.
     */
    public static function requestClaimedTwice(string $shortName, string $first, string $second): self
    {
        return new self(sprintf(
            'The operations "%s" and "%s" both generate the request class "%s". One rule set cannot '.
            'serve two operations, and `x-controller` does not rename a request, so give at least '.
            'one of them an `operationId` that does not collide.',
            $first,
            $second,
            $shortName
        ));
    }

    /**
     * Two different schemas that would generate one input DTO class.
     *
     * **Named by position, and by what each contributes to the class name.** The schema's name is not always what they
     * share: `User` and `UserPartial` are two components with two names, and the partial type of the first is the full
     * type of the second. Naming only the schemas blames them for a collision the generator's suffix made, so each side
     * says which type it is, the base the name came from and the suffix added to it. An inline schema's position is the
     * operation's, which is what a reader edits to resolve it.
     *
     * @param  array{position: string, base: string, origin: string, partial: bool}  $first
     * @param  array{position: string, base: string, origin: string, partial: bool}  $second
     *
     * @see docs/guide/code-generation/dto-anatomy.md — "One class, three members"
     * @see docs/guide/code-generation/request-validation.md — "Two directions, two types"
     */
    public static function inputDtoClaimedTwice(string $shortName, array $first, array $second): self
    {
        $claim = static fn (array $side): string => sprintf(
            'the schema written at "%s" gives the %s type, "%s" (%s) + "%s"',
            $side['position'],
            $side['partial'] ? 'partial' : 'full',
            $side['base'],
            $side['origin'],
            $side['partial'] ? 'PartialInputDto' : 'InputDto',
        );

        return new self(sprintf(
            'The schemas written at "%s" and at "%s" both generate the input DTO "%s", and the two '.
            'are not the same schema: %s, and %s. A class name is a base plus a suffix the '.
            'generator adds ("InputDto" for the full type, "PartialInputDto" for the partial one), so '.
            'two different bases can meet through a suffix, and two schemas can share a base. Rename '.
            'one of the schemas, or give an inline schema a component name of its own.',
            $first['position'],
            $second['position'],
            $shortName,
            $claim($first),
            $claim($second),
        ));
    }

    /**
     * Two different schemas that would generate one response DTO class.
     *
     * **Named by position, and by where each base came from.** A named schema's class is its name with every character
     * that is not a letter, a digit or an underscore dropped, so two spellings can meet. An inline schema's is derived
     * from its operation, its status or its parent and property, which is not the name anyone wrote, so the message
     * says what the name was derived from and that a component name ends the clash, as the input side's does.
     *
     * @param  array{position: string, base: string, origin: string}  $first
     * @param  array{position: string, base: string, origin: string}  $second
     */
    public static function responseDtoClaimedTwice(string $shortName, array $first, array $second): self
    {
        $claim = static fn (array $side): string => sprintf(
            'the schema written at "%s" gives the base "%s" (%s)',
            $side['position'],
            $side['base'],
            $side['origin'],
        );

        return new self(sprintf(
            'The schemas written at "%s" and at "%s" both generate the response DTO "%s", and the two '.
            'are not the same schema: %s, and %s. A class name is a base plus "Dto". The base of a '.
            'named schema is its name with every character that is not a letter, a digit or an '.
            'underscore dropped, and the base of an inline schema is derived from the operation, the '.
            'status or the parent property it is written under, so two schemas can share a base. Rename '.
            'one of the schemas, or give an inline schema a component name of its own.',
            $first['position'],
            $second['position'],
            $shortName,
            $claim($first),
            $claim($second),
        ));
    }

    /**
     * A response schema and a request schema that would generate one DTO class.
     *
     * Only a response schema named like a request's plus `Input` can meet it: a response DTO ends in `Dto`, and an
     * input DTO in `InputDto`.
     */
    public static function responseDtoTakenByInput(string $shortName, string $inputPosition, string $responsePosition): self
    {
        return new self(sprintf(
            'The schema written at "%s" generates the input DTO "%s" for a request, and the schema written at "%s" '.
            'generates a response DTO of the same name. A response DTO takes its name from the schema it describes '.
            'and "Dto", and an input DTO adds "InputDto", so a response schema named "UserInput" meets a request '.
            'schema named "User". Rename one of the schemas.',
            $inputPosition,
            $shortName,
            $responsePosition,
        ));
    }

    /**
     * A schema name that leaves no class name behind.
     */
    public static function schemaNameUnusable(string $name, string $position): self
    {
        return new self(sprintf(
            'The schema "%s", written at "%s", would generate a DTO class name that is not a usable '.
            'PHP identifier. A DTO takes its name from the schema it describes, with every character '.
            'that is not a letter, a digit or an underscore dropped, so rename the schema to something '.
            'that still starts with a letter or an underscore after that.',
            $name,
            $position
        ));
    }

    /**
     * Two keys of one schema that derive one property name.
     */
    public static function propertyClaimedTwice(string $dto, string $property, string $first, string $second): self
    {
        return new self(sprintf(
            'The keys "%s" and "%s" of the schema behind the DTO "%s" both derive the property '.
            'name "$%s". A key that is not a PHP identifier gets a derived name, and two keys '.
            'cannot share one, so rename one of them in the specification.',
            $first,
            $second,
            $dto,
            $property
        ));
    }

    /**
     * One schema read as a multipart body by one operation and as something else by another, with a file part in it.
     */
    public static function inputDtoServesFilesAndStrings(string $shortName, string $position): self
    {
        return new self(sprintf(
            'The schema written at "%s" is sent as `multipart/form-data` by one operation and as '.
            'another media type by another, and it has a file part. That part is an `UploadedFile` '.
            'in the first and a string in the second, so the one input DTO "%s" cannot be both. '.
            'Give the multipart operation a schema of its own.',
            $position,
            $shortName
        ));
    }
}
