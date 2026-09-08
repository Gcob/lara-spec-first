<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;
use Illuminate\Support\Str;

/**
 * The class name a generated controller takes, and where it came from.
 *
 * Three sources, and which one applied is a finding worth reporting rather than
 * an implementation detail: `x-controller` is a name chosen in order to name this
 * class, an `operationId` is a name chosen for something else, and a derived one
 * is a name nobody chose. Only the first is extendable, and {@see NameSource}
 * owns that reasoning.
 *
 * **A declared custom controller decides the generated parent's short name.** The
 * two classes share it — the parent inside the generated namespace, the child
 * wherever the document says — so a reader seeing `UserController extends
 * \App\Http\Generated\Controllers\UserController` needs no convention
 * explained to them.
 *
 * **A derived name includes the path's parameter names.** That is a departure
 * from an earlier rule which excluded them so that renaming `{id}` to `{userId}`
 * could not rename a class, and the departure has a reason: a generated
 * controller with no declared custom controller is `final`, so nothing may
 * extend it and no import can depend on it. The cost the old rule was protecting
 * against no longer exists, and what is left is that `GetUsersIdController`
 * tells a reader which endpoint it serves where `GetUsersParamController` does
 * not.
 *
 * Identity stays normalized regardless — that is a different question, asked for
 * rename detection rather than for naming.
 *
 * @see docs/guide/code-generation/generated-file-anatomy.md — "When operationId is absent, derive from method and path"
 */
final readonly class ControllerName
{
    private function __construct(
        public string $shortName,
        public NameSource $source,
        /**
         * The fully-qualified name of the class that extends the generated
         * parent, or null when the contract names none.
         */
        public ?string $customController,
    ) {}

    /**
     * @throws UnusableNameException
     */
    public static function for(Operation $operation): self
    {
        $custom = $operation->controller;

        // Taken as the name it is, rather than run through the studly rule that
        // turns an `operationId` into one: the document wrote a class name, and
        // rewriting it would mean the class a developer typed in their contract
        // and the class the build generates disagreeing about their own name.
        if ($custom !== null) {
            $short = self::shortNameOf($custom);

            return new self(self::assertUsable($short, $operation), NameSource::CustomController, $custom);
        }

        $declared = $operation->operationId;

        if ($declared === null) {
            return new self(
                self::assertUsable(self::derive($operation), $operation),
                NameSource::Derived,
                null,
            );
        }

        $studly = Str::studly($declared);

        // Checked before the suffix, because appending it would hide the problem
        // rather than surface it: an `operationId` of `---` studlies to nothing
        // and would produce the perfectly valid, perfectly meaningless class name
        // `Controller` — which also collides with every other operation whose id
        // does the same.
        if ($studly === '') {
            throw UnusableNameException::emptyOperationId($operation->label(), $declared);
        }

        return new self(
            self::assertUsable($studly.'Controller', $operation),
            NameSource::OperationId,
            null,
        );
    }

    /**
     * Whether anything may extend the class this name belongs to.
     */
    public function isExtendable(): bool
    {
        return $this->source->isExtendable();
    }

    /**
     * The last segment of a fully-qualified name.
     */
    private static function shortNameOf(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * The method and the path, in the order a reader scans them.
     */
    private static function derive(Operation $operation): string
    {
        $segments = array_filter(explode('/', $operation->path->template), static fn (string $s): bool => $s !== '');

        $studly = array_map(
            static fn (string $segment): string => Str::studly(trim($segment, '{}')),
            $segments,
        );

        return Str::studly($operation->method->value).implode('', $studly).'Controller';
    }

    /**
     * Refuse a name PHP cannot carry, rather than emitting a file that will not parse.
     *
     * Studly-casing already drops the separators an `operationId` is likely to
     * use, so what survives here is genuinely unusable: a name that lost every
     * character it had, or one that starts with a digit because the source did.
     *
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
