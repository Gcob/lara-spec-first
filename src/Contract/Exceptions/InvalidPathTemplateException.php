<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use InvalidArgumentException;

/**
 * A path in the specification is not a usable path template.
 *
 * Only faults that make the path meaningless in its own terms live here —
 * nothing about what Laravel can route. That distinction is the whole reason
 * `Contract\` exists: it describes the contract, not what one framework is able
 * to do with it.
 */
final class InvalidPathTemplateException extends InvalidArgumentException implements SpecException
{
    public static function notAbsolute(string $template): self
    {
        return new self(sprintf('A path must begin with "/", and "%s" does not.', $template));
    }

    public static function unbalanced(string $template): self
    {
        return new self(sprintf(
            'The path "%s" has an unbalanced "{" or "}". Every parameter must be a closed pair.',
            $template
        ));
    }

    public static function emptyParameter(string $template): self
    {
        return new self(sprintf('The path "%s" declares a parameter with no name.', $template));
    }

    public static function duplicateParameter(string $template, string $name): self
    {
        return new self(sprintf(
            'The path "%s" declares "%s" twice. Two parameters of one name cannot both be bound.',
            $template,
            $name
        ));
    }
}
