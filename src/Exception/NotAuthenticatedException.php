<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * The token was missing, malformed, expired or revoked - HTTP 401, or error code 1000.
 *
 * Both routes reach here deliberately. 401 is the ordinary one; the code matters because
 * Cloudflare does not answer every bad credential with the same status, and a body saying
 * `{"code": 1000, "message": "Invalid API Token"}` means the same thing whatever it arrived
 * with.
 *
 * Distinct from NotPermittedException, which means the token is real and lacks a permission.
 * The distinction is worth keeping because the fixes are different and neither message says
 * which: one is a new token, the other is a permission added to the token you have.
 */
final class NotAuthenticatedException extends ApiException
{
}
