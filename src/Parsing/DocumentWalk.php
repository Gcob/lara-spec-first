<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Support\Path;

/**
 * A second descent through the raw document, running beside the parser's.
 *
 * The extractor walks the object graph `cebe\openapi\` resolved; this walks the
 * decoded arrays the author actually wrote, where every `$ref` is still a
 * `$ref`. Both take the same steps — `properties/tag`, `items`, `allOf/0` — so
 * at every node the extractor knows not only what the schema *is* but where it
 * was *written*, file included.
 *
 * **Why not ask the parser.** `getDocumentPosition()` answers with the first
 * site that referenced a node, and for a schema written in another file that
 * site is somewhere under `/paths/` in the root document. Everything read out
 * of that answer is wrong for a multi-file contract: a refusal names a pointer
 * the named file has nothing at, a recursion marker cuts one level too late
 * because the parser's copy is not the same object as the original, and a
 * schema's own name is unrecoverable. One wrong position, three symptoms, so
 * one correction.
 *
 * **What makes the parallel walk tractable.** In practice one kind of target
 * has to be followed: a file. {@see Guards\RemoteReferenceGuard} rewrites
 * every allowed URL into a relative path to its vendored copy before the
 * parser — or this class — sees the document. It runs on the root document and
 * on the vendored files, so the one shape it cannot reach is a URL written
 * inside a hand-written sibling file, which the parser would fetch itself long
 * before this walk met it. A URL reaching here anyway is read as a path,
 * resolves to nothing, and the walk answers with the position it already had
 * rather than with a wrong one. And the reference graph has
 * already been checked: {@see Guards\ReferenceCycleDetector} refuses pure
 * cycles and the parser refuses missing targets, so following an alias chain
 * terminates.
 *
 * Not `readonly`, unlike most of this namespace: each external file is decoded
 * once and kept, and the cache is the whole reason a walk over a contract split
 * across a dozen files is not a dozen re-reads per schema. One instance per
 * extraction, created by {@see OperationExtractor::extract()} and discarded
 * with it, so nothing about one document survives into the next.
 *
 * @internal Not public API — a detail of how the extractor tracks where it is.
 *
 * @see docs/guide/openapi-support.md — "Where a schema is reported from"
 */
final class DocumentWalk
{
    /**
     * Decoded documents by absolute path, `null` for one that could not be
     * read.
     *
     * A failure is cached as deliberately as a success: a file the walk cannot
     * open is not a fault of its own — the parser is resolving the same
     * references and will report it — and retrying it at every node of a large
     * schema would be a stat call per property.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $decoded = [];

    /**
     * Normalized on the way in, so a position's file compares equal to it
     * however the path arrived.
     */
    private readonly string $rootFile;

    /**
     * @param  string  $rootPath  the document the read started from, as given
     * @param  array<string, mixed>  $rootDocument  its decoded form, already in hand
     */
    public function __construct(string $rootPath, array $rootDocument)
    {
        $this->rootFile = self::normalize($rootPath);
        $this->decoded[$this->rootFile] = $rootDocument;
    }

    /**
     * The root document itself, resolved.
     */
    public function root(): SchemaPosition
    {
        return $this->resolve(new SchemaPosition($this->rootFile, ''));
    }

    /**
     * One step down, into the key a resolved position holds.
     *
     * The step is taken first and the references are followed after, which is
     * the order that makes a chain of aliases collapse to the place the schema
     * is really written: `#/components/schemas/Alias` pointing at `NewUser`
     * gives back `NewUser`'s position, so two spellings of one schema are one
     * position rather than two.
     */
    public function child(SchemaPosition $parent, string|int $segment): SchemaPosition
    {
        return $this->resolve(new SchemaPosition(
            $parent->file,
            $parent->pointer.'/'.DocumentPointer::escape((string) $segment),
        ));
    }

    /**
     * The raw node written at a position, or null when nothing readable is
     * there.
     *
     * @return array<array-key, mixed>|null
     */
    public function rawAt(SchemaPosition $position): ?array
    {
        $document = $this->document($position->file);

        return $document === null ? null : DocumentPointer::nodeAt($position->pointer, $document);
    }

    /**
     * A position as a message names it: the bare pointer for the root
     * document, exactly as before this walk existed, and a relative path in
     * front of it for anything written elsewhere.
     *
     * The path is relative to the root document rather than to the file that
     * referenced it, so two messages about two files can be compared, and so a
     * reader has one directory to resolve them all against.
     */
    public function pointer(SchemaPosition $position): string
    {
        if ($position->file === $this->rootFile) {
            return '#'.$position->pointer;
        }

        return DocumentPointer::inFile(
            RelativeFilePath::from(dirname($this->rootFile), $position->file),
            $position->pointer,
        );
    }

    /**
     * The name the author gave this schema, or null when they gave it none.
     *
     * Two positions carry a name, and the file holding them is not one of the
     * inputs — which is decision 7 of the card #35 plan, not an omission. A
     * contract split across files is the same contract, so `Pet` is `Pet`
     * whether it is written in the root document or in `other.yaml`, and a
     * generated class keeps its name when a spec is reorganized.
     *
     * - `/components/schemas/{Name}`, in any file: the author named it there.
     * - A whole file, referenced as `./schemas/Pet.yaml`: the file name without
     *   its extension, which is the convention multi-file specs already use.
     *   Only when it reads as a PHP identifier — a file name is incidental in a
     *   way a component key is not, so `pet-v2.yaml` yields nothing and naming
     *   it falls to whatever reads the schema.
     *
     * Nothing else is a name. An inline schema has none, and inventing one from
     * the operation around it is a generator's decision rather than the
     * contract's.
     */
    public function name(SchemaPosition $position): ?string
    {
        if ($position->pointer === '') {
            // The root document is not a schema, so its file name is not a
            // schema name: only a file something referenced *as* a schema is.
            if ($position->file === $this->rootFile) {
                return null;
            }

            $base = pathinfo($position->file, PATHINFO_FILENAME);

            return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base) === 1 ? $base : null;
        }

        $segments = array_map(
            DocumentPointer::unescape(...),
            array_slice(explode('/', $position->pointer), 1),
        );

        return count($segments) === 3 && $segments[0] === 'components' && $segments[1] === 'schemas'
            ? $segments[2]
            : null;
    }

    /**
     * Follow references from a position until one lands somewhere that holds
     * content.
     *
     * The loop keeps its own visited set even though the guards have already
     * refused pure cycles: those run on the root document alone, and a chain
     * that closes across files is out of their reach by their own admission.
     * An unterminated walk here would be a hang rather than a message, which is
     * not a trade worth making for four lines.
     */
    private function resolve(SchemaPosition $position): SchemaPosition
    {
        $seen = [];

        while (! isset($seen[$position->key()])) {
            $seen[$position->key()] = true;

            $node = $this->rawAt($position);

            if ($node === null || ! isset($node['$ref']) || ! is_string($node['$ref'])) {
                return $position;
            }

            $position = $this->follow($position, $node['$ref']);
        }

        return $position;
    }

    /**
     * Where one written reference points, read the way the parser reads it: a
     * fragment alone stays in the current file, and a path is resolved against
     * the directory of the document that wrote it.
     */
    private function follow(SchemaPosition $from, string $reference): SchemaPosition
    {
        [$file, $fragment] = array_pad(explode('#', $reference, 2), 2, '');

        if ($file === '') {
            return new SchemaPosition($from->file, $fragment);
        }

        return new SchemaPosition(
            self::normalize(Path::isAbsolute($file) ? $file : dirname($from->file).'/'.$file),
            $fragment,
        );
    }

    /**
     * One decoded document, read at most once.
     *
     * @return array<string, mixed>|null
     */
    private function document(string $file): ?array
    {
        if (! array_key_exists($file, $this->decoded)) {
            try {
                $this->decoded[$file] = DocumentDecoder::decode($file);
            } catch (UnreadableDocumentException) {
                $this->decoded[$file] = null;
            }
        }

        return $this->decoded[$file];
    }

    /**
     * A path with its `.` and `..` segments resolved, without asking the file
     * system whether it exists.
     *
     * `realpath()` is the obvious call and the wrong one: it answers `false`
     * for a file that is not there, and a position is still worth naming when
     * the reference is broken — that is exactly the document whose diagnostic
     * has to be readable. Resolving the segments textually also keeps two
     * spellings of one file (`./other.yaml` and `../fixtures/other.yaml` from a
     * subdirectory) collapsing to a single key, which is what makes the schema
     * they both name a single schema.
     */
    private static function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return (str_starts_with($normalized, '/') ? '/' : '').implode('/', $segments);
    }
}
