<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Enum;

/**
 * Where a zone is in its life on Cloudflare.
 *
 * ONLY `Active` MEANS CLOUDFLARE IS ANSWERING FOR THE DOMAIN. A `Pending` zone accepts every
 * DNS change the API can make and serves none of them, because the registrar's nameservers
 * still point elsewhere - so a script that creates records against a pending zone succeeds
 * completely and changes nothing that resolves. That is the failure this enum is here to
 * make visible.
 */
enum ZoneStatus: string
{
    /** Cloudflare is setting the zone up. Transient. */
    case Initializing = 'initializing';

    /** Waiting for the domain's nameservers to be pointed at Cloudflare. Nothing is served. */
    case Pending = 'pending';

    /** Cloudflare is authoritative and answering. */
    case Active = 'active';

    /** The nameservers have been pointed away again. Nothing is served. */
    case Moved = 'moved';
}
