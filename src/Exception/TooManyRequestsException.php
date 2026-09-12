<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * Rate limited - HTTP 429.
 *
 * Cloudflare's global limit is 1200 requests per five minutes per user, and exceeding it
 * blocks every call for the next five minutes rather than only the one that went over. That
 * makes pacing worth doing in advance: Result\ResponseMeta carries the `Ratelimit` headers
 * from every response, so a long walk can slow itself down before being stopped.
 *
 * `retryAfter` is documented as present only when a limit was actually exceeded, so unlike
 * some APIs its presence here is meaningful.
 */
final class TooManyRequestsException extends ApiException
{
}
