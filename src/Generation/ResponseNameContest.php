<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Who keeps a response DTO's class name when more than one schema derives it.
 *
 * **Decided from the claims alone, so the answer does not depend on the order the document is written in.** Each schema
 * that would be a DTO claims the class name it derives, with a rank: an input DTO outranks every response schema, and a
 * component (a schema the author named) outranks one written inline. One claimant at the top rank keeps the name, and
 * every other claimant gives way. Two or more different claimants at the top rank are equals, so they all give way:
 * picking one would pick by order. Two inline schemas that say the same thing are one claimant, and share the class.
 *
 * **A claim is made whether or not the claimant ends up with a DTO**, so a component whose keys collide still keeps an
 * inline schema off its name: a class carrying that name would describe something else.
 *
 * @internal Not public API.
 */
final class ResponseNameContest
{
    /** A schema written inline, whose class name is derived from where it sits. */
    public const INLINE = 1;

    /** A schema the author named. */
    public const COMPONENT = 2;

    /** The input DTO of a request, which a response schema never outranks. */
    public const INPUT_DTO = 3;

    /**
     * @var array<string, array<string, array{rank: int, position: string, name: string|null, gaveWay: bool}>>
     */
    private array $claims = [];

    /**
     * Claim a class name for a schema, once: the same claimant meeting its own name again changes nothing.
     *
     * @param  string  $identity  what makes two claimants one: where a named schema is written, what an inline one says
     * @param  string|null  $name  the schema's own name, for a component
     */
    public function claim(string $class, string $identity, int $rank, string $position, ?string $name = null): void
    {
        $this->claims[$class][$identity] ??= ['rank' => $rank, 'position' => $position, 'name' => $name, 'gaveWay' => false];
    }

    /**
     * Say that a claimant has no DTO for a fault of its own, which a schema it keeps off the name then says.
     */
    public function gaveWay(string $class, string $identity): void
    {
        if (isset($this->claims[$class][$identity])) {
            $this->claims[$class][$identity]['gaveWay'] = true;
        }
    }

    /**
     * Why a claimant does not keep its class name, in the words of the file that says so, or null when it keeps it.
     *
     * @param  string  $subject  how the claimant is named when it is not one of the equals, such as "its schema"
     */
    public function explain(string $class, string $identity, string $subject): ?string
    {
        $claims = $this->claims[$class] ?? [];
        $holders = self::topRanked($claims);

        if (! isset($claims[$identity]) || (count($holders) === 1 && isset($holders[$identity]))) {
            return null;
        }

        $top = array_values($holders)[0]['rank'];

        if (isset($holders[$identity])) {
            return $this->tie($class, $holders);
        }

        $names = implode(' and ', array_map($this->describe(...), $this->sorted($holders)));

        if ($top === self::INPUT_DTO) {
            return sprintf(
                '%s derives the class name `%s`, which is the input DTO of the request schema written at `%s`. Rename one of the schemas',
                $subject,
                $class,
                CommentText::safe(array_values($holders)[0]['position']),
            );
        }

        $gone = count($holders) > 1
            ? ', and none of them gets a DTO'
            : (array_values($holders)[0]['gaveWay'] ? ', and that schema gets no DTO either' : '');

        return sprintf(
            '%s derives the class name `%s`, which %s %s%s. Give it a component name of its own to type it',
            $subject,
            $class,
            $names,
            count($holders) > 1 ? 'claim' : 'claims',
            $gone,
        );
    }

    /**
     * The claimants at the highest rank: one keeps the name, and more than one are equals.
     *
     * @param  array<string, array{rank: int, position: string, name: string|null, gaveWay: bool}>  $claims
     * @return array<string, array{rank: int, position: string, name: string|null, gaveWay: bool}>
     */
    private static function topRanked(array $claims): array
    {
        $top = 0;

        foreach ($claims as $claim) {
            $top = max($top, $claim['rank']);
        }

        return array_filter($claims, static fn (array $claim): bool => $claim['rank'] === $top);
    }

    /**
     * What the equals say when none of them keeps the name: all of them, in one order whichever is asked.
     *
     * @param  array<string, array{rank: int, position: string, name: string|null, gaveWay: bool}>  $holders
     */
    private function tie(string $class, array $holders): string
    {
        $described = array_map($this->describe(...), $this->sorted($holders));
        $last = array_pop($described);
        $both = count($holders) === 2;

        return sprintf(
            '%s and %s %s derive the class name `%s`, so %s has it. Rename %s',
            implode(', ', $described),
            $last,
            $both ? 'both' : 'all',
            $class,
            $both ? 'neither' : 'none of them',
            $both ? 'one of them' : 'all but one of them',
        );
    }

    /**
     * The claimants by position, so what is said does not depend on which was met first.
     *
     * @param  array<string, array{rank: int, position: string, name: string|null, gaveWay: bool}>  $claims
     * @return list<array{rank: int, position: string, name: string|null, gaveWay: bool}>
     */
    private function sorted(array $claims): array
    {
        $list = array_values($claims);
        usort($list, static fn (array $a, array $b): int => strcmp($a['position'], $b['position']));

        return $list;
    }

    /**
     * @param  array{rank: int, position: string, name: string|null, gaveWay: bool}  $claim
     */
    private function describe(array $claim): string
    {
        $position = CommentText::safe($claim['position']);

        return $claim['name'] === null
            ? sprintf('the inline schema written at `%s`', $position)
            : sprintf('the schema `%s` (`%s`)', CommentText::safe($claim['name']), $position);
    }
}
