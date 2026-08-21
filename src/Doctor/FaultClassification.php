<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CircularRemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\MissingVendoredReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\ParserFailedException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceFetchException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use LogicException;

/**
 * Where a fault the reading pipeline collected belongs in the doctor's
 * report: which section names it, whether it is the spec author's to fix or
 * this package's, and — for a package limit — which support level it
 * corresponds to in docs/guide/openapi-support.md.
 *
 * **One place this mapping is made, deliberately.** Every section that turns
 * a `SpecException` into a `Finding` — References, Document validity, and the
 * `Rejected` half of Support findings — reads {@see self::sectionOf()} rather
 * than repeating its own `match` on the exception's class, which is what
 * three separate lists could otherwise drift out of sync on the day a
 * tenth — well, eleventh — exception is added.
 *
 * **`sectionOf()` has no `default` arm on purpose.** An exception this method
 * does not recognize throws `UnhandledMatchError` rather than silently
 * landing in the wrong section, or in none at all — a loud failure in a test
 * that pins every case beats a finding that quietly never appears anywhere in
 * a report, which is exactly the outcome rule 2 exists to forbid.
 *
 * The rule behind every `classOf()` branch: a document fault is one only its
 * author can fix — the document itself is broken, whatever this package
 * decided to build. Everything else is a stance *this* package takes on an
 * otherwise-correct document, which is what makes it a package limit.
 */
final readonly class FaultClassification
{
    public static function sectionOf(SpecException $fault): string
    {
        return match ($fault::class) {
            UnreadableDocumentException::class,
            UnsupportedVersionException::class,
            InvalidDocumentException::class,
            ParserFailedException::class => 'Document validity',

            CyclicReferenceException::class,
            RemoteReferenceException::class,
            MissingVendoredReferenceException::class,
            CircularRemoteReferenceException::class,
            RemoteReferenceFetchException::class => 'References',

            RejectedConstructException::class => 'Support findings',

            // No fault of any other type should ever reach this method: every
            // caller passes exceptions taken straight out of
            // `ReadOutcome::$faults`, which the reading pipeline only ever
            // populates with the ten classes above. A fault of a different
            // type reaching here is this package's own bug, not a document
            // fault or a package limit — so it fails loudly rather than
            // landing in a section nobody chose for it.
            default => throw new LogicException(sprintf(
                '%s does not know which doctor section %s belongs in.',
                self::class,
                $fault::class,
            )),
        };
    }

    public static function classOf(SpecException $fault): FindingClass
    {
        return match ($fault::class) {
            UnreadableDocumentException::class,
            InvalidDocumentException::class,
            CyclicReferenceException::class,
            ParserFailedException::class => FindingClass::DocumentFault,
            default => FindingClass::PackageLimit,
        };
    }

    public static function levelOf(SpecException $fault): ?SupportLevel
    {
        return match ($fault::class) {
            UnsupportedVersionException::class,
            RejectedConstructException::class,
            RemoteReferenceException::class,
            MissingVendoredReferenceException::class,
            CircularRemoteReferenceException::class,
            RemoteReferenceFetchException::class => SupportLevel::Rejected,
            default => null,
        };
    }

    public static function fromFault(SpecException $fault, string $pointer = ''): Finding
    {
        return new Finding(
            self::classOf($fault),
            self::sectionOf($fault),
            self::levelOf($fault),
            $pointer,
            $fault->getMessage(),
        );
    }
}
