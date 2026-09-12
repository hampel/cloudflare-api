<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * No such thing - HTTP 404.
 *
 * Raised for a DNS record that is not there - `404 {"code": 81044}` - and for a path the API
 * does not have. The endpoint helpers that treat absence as an ordinary answer catch this and
 * return null instead.
 *
 * A ZONE THAT IS NOT THERE DOES NOT REACH HERE. Cloudflare reports an unknown zone id as
 * `403 {"code": 9109, "message": "Invalid zone identifier"}`, so it arrives as
 * NotPermittedException - which is correct of it, since a 404 would confirm to a credential
 * which zone ids exist, and is worth knowing before concluding a zone was deleted. Measured
 * on 2026-09-12. Zones::find() absorbs that specific code; see it for why only that
 * one.
 *
 * A MALFORMED ID IS A THIRD ANSWER AGAIN: `400 {"code": 7000, "message": "No route for that
 * URI"}`, because the path never matched a route at all. That raises ValidationException and
 * is not treated as absence anywhere, a malformed id being a bug rather than a thing that is
 * missing.
 */
final class NotFoundException extends ApiException
{
}
