<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One operation of the contract, normalized and free of any OpenAPI version.
 *
 * Everything the parser had to say about this operation that the package honors
 * ends up here, in our own types — which is what lets a future version bring a
 * different parser without anything downstream noticing.
 */
final readonly class Operation
{
    /**
     * @param  int  $index  where the specification writes it, counting from zero.
     *                      Semantic, not decoration: the document's order settles
     *                      which route wins when two match.
     * @param  string|null  $operationId  as written, or null when the document omits
     *                                    it — deriving a name is the generator's job
     * @param  list<string>  $tags  in the order the document writes them
     * @param  Audience  $audience  effective, not as written: an absent `x-audience`
     *                              is already resolved to its default here
     * @param  Lifecycle|null  $lifecycle  effective in the same sense; null is the
     *                                     absence of a claim
     * @param  string|null  $sunset  as `x-sunset` states it, unparsed — whether the
     *                               date is valid and whether it has passed are
     *                               doctor rules
     * @param  list<SecurityRequirement>|null  $security  null when the operation says
     *                                                    nothing and inherits the
     *                                                    document's, empty when it
     *                                                    explicitly requires nothing
     * @param  string|null  $controller  the fully-qualified name `x-controller` gives
     *                                   this operation's custom controller, or null
     *                                   when the document names none. Read rather
     *                                   than resolved: whether a class of that name
     *                                   exists is a question for the build, and the
     *                                   contract's answer does not depend on it
     * @param  RequestBody|null  $requestBody  what the operation accepts, or null
     *                                         when it declares no body this
     *                                         package can read one schema from
     * @param  list<QueryParameter>  $queryParameters  the `query` parameters
     *                                                 only, merged with the Path
     *                                                 Item's and in the order
     *                                                 they are written. The
     *                                                 three other locations are
     *                                                 [not carried](../../docs/guide/code-generation/request-validation.md#one-rule-set-body-and-query)
     * @param  list<Response>  $responses  in the order the document writes them,
     *                                     each carrying the status it answers,
     *                                     `2XX` and `default` included. A
     *                                     declared status with no body is
     *                                     present and empty rather than absent
     */
    public function __construct(
        public int $index,
        public HttpMethod $method,
        public PathTemplate $path,
        public ?string $operationId,
        public array $tags = [],
        public Audience $audience = Audience::Public,
        public ?Lifecycle $lifecycle = Lifecycle::Beta,
        public bool $deprecated = false,
        public ?string $sunset = null,
        public ?array $security = null,
        public ?string $controller = null,
        public ?RequestBody $requestBody = null,
        public array $queryParameters = [],
        public array $responses = [],
    ) {}

    /**
     * What addresses this operation, independently of what anything is named.
     *
     * **DECISION: this still normalizes a path parameter's name away, even though
     * the comparison it was built for — reporting a renamed parameter as a rename
     * rather than as a deletion and an addition — is gone now that `x-controller`
     * is the only name a contract diff has to track.** It survives because two
     * unrelated consumers still need "same method and path, regardless of what a
     * parameter is called": `Parsing\OperationExtractor` uses it to catch two
     * operations that would collide at the router — `/users/{id}` and
     * `/users/{slug}` are one route no matter what either specification author
     * called the placeholder — and `Scaffolding\ExtensionInsertion` uses it to
     * find, in a freshly re-read copy, the one operation an edit was made to.
     * Neither is rename detection; both would break if a future reader
     * "simplified" this by comparing `$path->template` instead. (Named rather
     * than linked with `@see`, on purpose: `Contract\` is not allowed to import
     * either namespace, and a docblock reference is exactly what would add one.)
     *
     * @see docs/guide/code-generation/generated-file-anatomy.md — "Identity is the path and method"
     */
    public function identity(): string
    {
        return $this->method->value.' '.$this->path->normalized;
    }

    /**
     * The same operation, with `x-controller` read as this value.
     *
     * **It lives here rather than beside its one caller, and the reason is a
     * scar.** `Scaffolding\ExtensionInsertion` used to build the copy itself by
     * naming every property, and when this class grew three of them the copy
     * quietly stopped carrying any of them: what caught it was that the
     * insertion check compares two whole operations with `==`, not anybody
     * reading the constructor. Next to the properties, a new one is adjacent to
     * the line that has to carry it, and `OperationTest` fails when it is not.
     *
     * @see tests/Unit/Contract/OperationTest.php — the check that this copies everything
     */
    public function withController(string $controller): self
    {
        return new self(
            index: $this->index,
            method: $this->method,
            path: $this->path,
            operationId: $this->operationId,
            tags: $this->tags,
            audience: $this->audience,
            lifecycle: $this->lifecycle,
            deprecated: $this->deprecated,
            sunset: $this->sunset,
            security: $this->security,
            controller: $controller,
            requestBody: $this->requestBody,
            queryParameters: $this->queryParameters,
            responses: $this->responses,
        );
    }

    /**
     * The response this operation declares for one status, or null.
     *
     * A lookup rather than an array key, because {@see Response} carries its own
     * status: matching is exact and on the string the document wrote, so `2XX`
     * and `default` are asked for by name like any other. Whether one of them
     * stands in for a status nobody wrote is a question about serving a
     * response, and this is not where it is answered.
     *
     * **A scan rather than a map, and deliberately so.** An operation declares
     * four to six responses, so the loop costs nothing, and the map this would
     * otherwise become is the exact mistake {@see Response} argues against: PHP
     * turns a numeric string key into an integer, and `200` would come back as
     * an int where `2XX` came back as a string.
     */
    public function response(string $status): ?Response
    {
        foreach ($this->responses as $response) {
            if ($response->status === $status) {
                return $response;
            }
        }

        return null;
    }

    /**
     * How to name this operation to a person.
     *
     * Separate from {@see self::identity()} on purpose, and the difference is not
     * cosmetic. Identity exists to be *compared*, so it normalizes parameter
     * names away; a diagnostic exists to be *read*, and `get /users/{}` sends a
     * reader looking for a path their document does not contain.
     *
     * Every message this package puts in front of a human uses this. Every
     * comparison uses identity. Mixing them is how a good error message becomes
     * a confusing one.
     */
    public function label(): string
    {
        return $this->method->value.' '.$this->path->template;
    }
}
