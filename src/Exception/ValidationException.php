<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * Cloudflare rejected a value in the request - HTTP 400.
 *
 * Much the commonest failure against this API and the one worth catching by name, because it
 * is the only one a caller can usually fix from what came back: each error carries a code, a
 * message, and often a `source.pointer` naming the field. See ApiException::fieldErrors().
 *
 * A 400 here covers more than a malformed value - a TTL outside 60-86400, a proxied record
 * on a type that cannot be proxied, and a `data` object missing a component are all one.
 */
final class ValidationException extends ApiException
{
}
