<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use InvalidArgumentException;

/**
 * A path OpenAPI allows and Laravel's router cannot match.
 *
 * Refused at build time because the alternatives are both silent. Symfony's
 * route compiler finds placeholders with `#\{(!)?([\w\x80-\xFF]+)\}#`, so
 * `{user-id}` is not recognized as a placeholder at all: it stays literal text
 * and the route matches only a URL containing those characters. No exception, no
 * warning, an endpoint that 404s forever. And a placeholder longer than 32
 * characters throws from that same compiler, which happens while routes are
 * being registered — so the application does not boot, far from the specification
 * that caused it.
 *
 * The package does not convert the name either. A build that refuses and says
 * "rename this in your specification" teaches a real constraint of the platform
 * once, at build time. A build that silently converted would maintain a shadow
 * naming scheme forever, and the developer would still meet it the first time
 * they read a generated signature.
 *
 * @see docs/guide/openapi-support.md — "Parameter names are a naming contract, not a mapping problem"
 */
final class UnroutablePathException extends InvalidArgumentException implements SpecException
{
    /**
     * Symfony's own ceiling, and it is not negotiable in either direction.
     */
    public const int PARAMETER_NAME_LIMIT = 32;

    public static function unsupportedCharacters(string $identity, string $parameter): self
    {
        return new self(sprintf(
            'The operation "%s" has a path parameter named "%s". Laravel matches only letters, '.
            'digits and underscores in a placeholder, so this route would never match and the '.
            'endpoint would answer 404 with nothing reporting it. Rename the parameter in the '.
            'specification.',
            $identity,
            $parameter
        ));
    }

    public static function nameTooLong(string $identity, string $parameter): self
    {
        return new self(sprintf(
            'The operation "%s" has a path parameter named "%s", which is longer than the %d '.
            'characters Laravel\'s route compiler accepts. Registering it would fail while the '.
            'application boots. Shorten the parameter name in the specification.',
            $identity,
            $parameter,
            self::PARAMETER_NAME_LIMIT
        ));
    }
}
