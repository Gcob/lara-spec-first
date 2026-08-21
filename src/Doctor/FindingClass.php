<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * The distinction a diagnostic report never blurs: whose problem a finding is.
 *
 * @see docs/guide/doctor.md — "Two kinds of finding, never mixed"
 */
enum FindingClass: string
{
    /**
     * The document is not valid OpenAPI, or is internally inconsistent —
     * a schema violation, an unresolvable `$ref`, two operations claiming one
     * endpoint. The spec author fixes it. There is no other option.
     */
    case DocumentFault = 'document_fault';

    /**
     * The document is correct. This package does not honor the construct it
     * describes. Ours to fix, eventually — a roadmap item, not a defect in
     * their contract.
     */
    case PackageLimit = 'package_limit';
}
