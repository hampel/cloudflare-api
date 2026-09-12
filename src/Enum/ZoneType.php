<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Enum;

/**
 * How much of the domain Cloudflare is responsible for.
 */
enum ZoneType: string
{
    /** Cloudflare hosts the whole zone and is authoritative for it. The ordinary case. */
    case Full = 'full';

    /**
     * CNAME setup: the zone lives elsewhere and only named hostnames are pointed at
     * Cloudflare. The DNS record endpoints manage far less than the domain actually serves.
     */
    case Partial = 'partial';

    /** Cloudflare transfers the zone in from a primary elsewhere. Records are not writable here. */
    case Secondary = 'secondary';

    /** An internal zone, resolvable only inside the account's private network. */
    case Internal = 'internal';
}
