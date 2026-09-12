<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Enum;

/**
 * Which kind of CAA statement a record makes. Meaningful on a CAA record and on no other.
 *
 * Cloudflare types this as a plain string in `data.tag` rather than as an enumeration, so an
 * unrecognised value is a 400 from the far end rather than a refusal here. The enum is this
 * package's, to keep the three that exist in front of the caller.
 */
enum CaaTag: string
{
    /** This authority may issue certificates for the domain. */
    case Issue = 'issue';

    /** This authority may issue wildcard certificates for the domain. */
    case IssueWild = 'issuewild';

    /** Where to report a request this policy would have refused - a mailto: or https: URL. */
    case Iodef = 'iodef';
}
