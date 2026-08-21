<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Generation\CustomControllerLookup;

/**
 * The class name `spec:make` proposes for an operation, and what it refuses.
 *
 * **Two halves of one question**, which is why they share a class: what to suggest
 * when a developer has not chosen a name, and whether the name they chose can be
 * written into the contract at all.
 *
 * **The refusals are checked at the prompt rather than after the edit**, and each of
 * them would otherwise be found later by something else — the reader refuses a name
 * PHP could not carry, and the planner refuses one inside the generated tree or in a
 * namespace nothing maps. Found then, it means a specification already edited and a
 * command that then declines to finish. Found here, it means the prompt asks again.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */
final readonly class CustomControllerName
{
    private CustomControllerLookup $lookup;

    /**
     * @param  string  $baseNamespace  where this project keeps the controllers it owns
     * @param  string  $generatedNamespace  the root a build rewrites, which is the one
     *                                      place a custom controller may not live
     */
    public function __construct(
        private string $baseNamespace,
        private string $generatedNamespace,
        ?CustomControllerLookup $lookup = null,
    ) {
        $this->lookup = $lookup ?? CustomControllerLookup::fromAutoloader();
    }

    /**
     * The name to offer, which is the configured namespace plus the name the build
     * would have generated anyway.
     *
     * Derived rather than asked for, because it is the same value in almost every
     * contract: the `operationId` studly-cased and suffixed, or the method and path
     * when the operation has no `operationId`. A developer types a command instead
     * of a fully-qualified class name, and edits the proposal when theirs differs.
     */
    public function propose(Operation $operation): string
    {
        return rtrim($this->baseNamespace, '\\').'\\'.ControllerName::for($operation)->shortName;
    }

    /**
     * Why this name cannot go into the contract, or null when it can — with one
     * deliberate exception.
     *
     * Written as sentences rather than codes because they are read at a prompt, in
     * the moment somebody is deciding what to type next.
     *
     * **Blank answers null too, and that is not "no reason" — it is a second
     * question this method answers on the side.** This is also the prompt's own
     * live `validate` callback, submitted on every keystroke including the one
     * that clears the field, and blank is how a developer says "leave the
     * specification alone" rather than "here is my class name." Refusing it here
     * would block that submission; the command still checks for it by name — see
     * where `reasonToRefuse()` is called — precisely because this method only
     * answers whether a *name* can go into the contract, and blank is not one.
     */
    public function reasonToRefuse(string $candidate): ?string
    {
        $name = ltrim(trim($candidate), '\\');

        if ($name === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $name) !== 1) {
            return 'Not a class name PHP could carry. Write it the way PHP writes one: '
                .'App\\Http\\Controllers\\UserController';
        }

        $generated = rtrim($this->generatedNamespace, '\\');

        if (str_starts_with($name.'\\', $generated.'\\')) {
            return 'That is inside `'.$generated.'`, which a build rewrites — and the generated parent '
                .'takes the same short name there, so the class would extend itself.';
        }

        if ($this->lookup->pathFor($name) === null) {
            return 'No PSR-4 prefix in this project maps that namespace, so there is nowhere to put '
                .'the file where the autoloader would find it.';
        }

        return null;
    }
}
