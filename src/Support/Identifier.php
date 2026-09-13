<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Support;

use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;

/**
 * An id on its way into a URL path.
 *
 * EVERY IDENTIFIER ON THIS API IS AN OPAQUE STRING - a 32-character hex tag for a zone, an
 * account, a DNS record or a token - so there is nothing in the value itself to check against.
 * What this does check is the one case that turns a mistake into a wrong answer rather than an
 * error.
 *
 * AN EMPTY ID ADDRESSES THE COLLECTION. `zones/` . '' is `zones`, which is the LIST path, and a
 * GET against it succeeds: 200, and the first page of every zone the token can see. Read back
 * as one zone that is whichever zone happened to sort first, and anything that edits it
 * afterwards edits the wrong domain. The same shape applies to a record id, where the path
 * collapses onto the zone's whole record list. Nothing downstream can tell the difference, so
 * it is caught here, before the request.
 *
 * Lives in Support rather than on an endpoint because four endpoints need it for four kinds of
 * id, and only one of them is a zone.
 */
final class Identifier
{
    /**
     * @param  string  $of  what kind of id this is, for the message - `zone`, `account`,
     *                      `DNS record`
     */
    public static function for(string $id, string $of): string
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s id is required. An empty one addresses the collection instead, which '
                    . 'answers with a 200 and the wrong thing rather than an error.',
                $of
            ));
        }

        return rawurlencode($id);
    }
}
