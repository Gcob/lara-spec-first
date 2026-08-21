<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

/**
 * Turns the planned controllers into the one file the runtime opens.
 *
 * **Registrations rather than data.** A manifest the package walked at boot would
 * mean the runtime deciding something the build already decided; what this emits
 * is the `Route::` calls themselves, with the controller named as a
 * `[Class::class, 'routeAction']` pair of plain strings. That pair is what keeps
 * `php artisan route:cache` able to produce a file the application can load.
 *
 * **Document order is route order.** Laravel resolves the first route registered,
 * and OpenAPI defines no priority between `/users/me` and `/users/{id}`. Rather
 * than invent a sorting rule and hide it, the order the specification writes is
 * the order emitted here — so putting the specific path above the templated one
 * in the document is what makes it win. The cost is stated plainly: reordering
 * keys in a specification can change routing behavior.
 *
 * @see docs/guide/openapi-support.md — "Route order: the spec file's order is the route order"
 */
final readonly class RoutesEmitter
{
    /**
     * Laravel's router names a method per verb, except for HEAD.
     *
     * `Route::head()` does not exist, because the framework derives HEAD from GET
     * on its own. A specification that declares `head` explicitly still has to be
     * routed rather than dropped, so it goes through `match()` — which is also
     * the honest spelling, since it says the framework was not asked for its
     * usual behavior here.
     */
    private const array VERB_METHODS = [
        'get' => 'get',
        'post' => 'post',
        'put' => 'put',
        'patch' => 'patch',
        'delete' => 'delete',
        'options' => 'options',
    ];

    public function __construct(
        private string $namespace,
        private string $specPath,
    ) {}

    /**
     * @param  list<PlannedController>  $planned  in document order
     */
    public function emit(array $planned): GeneratedFile
    {
        $imports = [];
        $registrations = [];

        foreach ($planned as $controller) {
            $imports[] = $controller->fullyQualifiedName($this->namespace);
            $registrations[] = $this->registration($controller);
        }

        $imports[] = 'Illuminate\\Support\\Facades\\Route';
        sort($imports);

        $body = implode("\n", array_map(static fn (string $i): string => 'use '.$i.';', $imports))
            ."\n\n"
            .implode("\n", $registrations);

        return new GeneratedFile(
            GeneratedRoutesLocator::FILE,
            "<?php\n\ndeclare(strict_types=1);\n\n".$this->docblock(count($planned))."\n\n".$body."\n",
        );
    }

    private function registration(PlannedController $controller): string
    {
        $operation = $controller->operation;
        $verb = $operation->method->value;
        $target = '['.$controller->name->shortName.'::class, \'routeAction\']';

        // `var_export` rather than quoting by hand. A path segment may legally
        // contain an apostrophe or end in a backslash, and either one turns a
        // hand-quoted literal into PHP that does not parse — in a file the
        // service provider loads at boot, so the application and the very command
        // that would repair it both stop working.
        $path = var_export($operation->path->template, true);

        return isset(self::VERB_METHODS[$verb])
            ? sprintf('Route::%s(%s, %s);', self::VERB_METHODS[$verb], $path, $target)
            : sprintf('Route::match([%s], %s, %s);', var_export($verb, true), $path, $target);
    }

    private function docblock(int $count): string
    {
        return implode("\n", [
            '/*',
            ' * '.GeneratedFile::MARKER.'. DO NOT EDIT.',
            ' *',
            ' * Rewritten from scratch on every `php artisan spec:build`. This is the only generated',
            ' * file the runtime opens: the service provider loads it at boot and reads no',
            ' * specification to do it.',
            ' *',
            ' * Provenance',
            ' *   '.CommentText::safe($this->specPath),
            ' *   #/paths',
            ' *',
            ' * Findings',
            ' *   - '.$count.' operation(s), registered in the order the document writes them, because',
            ' *     that order is what decides which of two matching routes answers.',
            ' *   - Every action is a pair of plain strings, which is what `route:cache` requires.',
            ' *',
            ' * Navigation',
            ' *   @see Controllers/ — one class per operation, each naming its own position in the',
            ' *        specification',
            ' */',
        ]);
    }
}
