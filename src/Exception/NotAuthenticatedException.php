<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * The token was missing, malformed, expired or revoked.
 *
 * THREE ROUTES REACH HERE, because Cloudflare refuses a credential in more than one way and
 * the status alone does not separate them. Measured on 2026-09-13:
 *
 *   401, code 1000   a token of the right shape and the wrong value - the ordinary case
 *   400, code 6003   a token Cloudflare would not parse at all: a placeholder left in a
 *                    config file, a truncated value, a stray `Bearer ` prefix. It is refused
 *                    before authentication runs, which is why it wears a 400
 *   2xx, code 1000   the same rejection inside an envelope claiming success
 *
 * The second is the one worth knowing about, because it is the likeliest failure in practice
 * and the only one whose status suggests the request was at fault rather than the credential.
 * A consumer catching this type at startup gets all three.
 *
 * Distinct from NotPermittedException, which means the token is real and lacks a permission.
 * The distinction is worth keeping because the fixes are different and neither message says
 * which: one is a new token, the other is a permission added to the token you have.
 */
final class NotAuthenticatedException extends ApiException
{
}
