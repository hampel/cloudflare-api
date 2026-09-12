<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * No such thing - HTTP 404.
 *
 * Raised for a record that is not there, a zone the token cannot see, and a path the API
 * does not have. The endpoint helpers that treat absence as an ordinary answer catch this
 * and return null instead.
 *
 * A ZONE ID THAT IS REAL BUT OUTSIDE THE TOKEN'S RESOURCES ALSO ANSWERS 404, not 403 -
 * which is correct of Cloudflare (it should not confirm a zone exists to a credential that
 * may not see it) and is worth knowing before concluding a zone was deleted.
 */
final class NotFoundException extends ApiException
{
}
