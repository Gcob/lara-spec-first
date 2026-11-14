<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;

/**
 * The class name a generated `FormRequest` takes.
 *
 * **One source fewer than a controller's**, and the missing one is deliberate:
 * `x-controller` names a controller and nothing else, so reading it here would
 * make one declaration rename two classes and make a request's name depend on a
 * key that is about something else. What is left is the `operationId`, or the
 * method and path when the document writes none — the same two sources, and the
 * same derivation, {@see ControllerName} uses.
 *
 * **There is no `x-request`, and adding one would be inventing a second
 * extension for a class whose every line is already the contract's.** A
 * generated request is `final`, so nothing imports it except the signature of
 * the `routeAction` it is spliced into, and a name nobody may extend is a name
 * nobody needs to choose.
 *
 * @see docs/guide/code-generation/request-validation.md — "The generated request is final"
 */
final readonly class RequestName
{
    private function __construct(
        public string $shortName,
    ) {}

    /**
     * The name for an operation, or null when the operation has nothing to
     * validate.
     *
     * Null rather than a name nothing will be emitted under: no body and no
     * `query` parameter means no class at all, because a `rules()` returning an
     * empty array is a file telling its reader something untrue.
     *
     * @throws UnusableNameException a name PHP cannot carry
     *
     * @see docs/guide/code-generation/request-validation.md — "Nothing to validate, no class"
     */
    public static function for(Operation $operation): ?self
    {
        if (! self::validatesAnything($operation)) {
            return null;
        }

        $declared = $operation->operationId;

        if ($declared === null) {
            return new self(self::assertUsable(
                DerivedName::fromMethodAndPath($operation).'Request',
                $operation,
            ));
        }

        $studly = DerivedName::fromOperationId($declared);

        // Checked before the suffix for the reason ControllerName checks it
        // there: an `operationId` of `---` would otherwise become the valid,
        // meaningless `Request`, which every other such operation also becomes.
        if ($studly === null) {
            throw UnusableNameException::emptyOperationId($operation->label(), $declared);
        }

        return new self(self::assertUsable($studly.'Request', $operation));
    }

    /**
     * Whether the contract states anything about this operation's input that
     * a rule set can be built from.
     *
     * A body the extractor could read no schema from is already null here. A
     * body declared only in media types this package does not read is not
     * null, and it counts as nothing to validate: the class would carry a
     * `rules()` returning an empty array, which is the file this rule exists to
     * avoid. The build reports that body on its own line instead.
     */
    public static function validatesAnything(Operation $operation): bool
    {
        return $operation->queryParameters !== [] || self::hasReadableBody($operation);
    }

    /**
     * Whether the body declares at least one media type a rule set reads.
     */
    public static function hasReadableBody(Operation $operation): bool
    {
        $body = $operation->requestBody;

        return $body !== null
            && array_intersect($body->mediaTypes(), RuleSetBuilder::READ_MEDIA_TYPES) !== [];
    }

    /**
     * The name without its `Request` suffix, which is what an input DTO derived
     * from the same operation is named after.
     *
     * One derivation for both, so `CreateUserRequest` and `CreateUserInputDto`
     * cannot drift apart: they answer one endpoint and read as the same word.
     */
    public function stem(): string
    {
        return substr($this->shortName, 0, -strlen('Request'));
    }

    /**
     * @param  string  $namespace  the configured generated root namespace
     */
    public function fullyQualifiedName(string $namespace): string
    {
        return $namespace.'\\Requests\\'.$this->shortName;
    }

    public function relativePath(): string
    {
        return 'Requests/'.$this->shortName.'.php';
    }

    /**
     * @throws UnusableNameException
     */
    private static function assertUsable(string $candidate, Operation $operation): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $candidate) !== 1) {
            throw UnusableNameException::forOperation($operation->label(), $candidate);
        }

        return $candidate;
    }
}
