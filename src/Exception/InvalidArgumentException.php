<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * The caller asked for something that cannot be sent - a page size outside the range the
 * endpoint accepts, a record type that needs a field it was not given, a TTL Cloudflare
 * will refuse.
 *
 * Raised before a request is made, which is the point of it: a mistake caught here costs
 * nothing, and the same mistake caught by the API costs a round trip and comes back as a
 * 400 whose message is about a JSON pointer rather than about what you did.
 */
class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
