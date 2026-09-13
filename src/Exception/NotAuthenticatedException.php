<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * The token was missing, malformed, expired or revoked.
 *
 * FOUR ROUTES REACH HERE, because Cloudflare refuses a credential in more than one way and
 * the status alone does not separate them. Measured on 2026-09-13:
 *
 *   401, code 1000   a token of the right shape and the wrong value - the ordinary case
 *   400, code 6003   a token Cloudflare would not parse at all: a placeholder left in a
 *                    config file, a truncated value, a stray `Bearer ` prefix. It is refused
 *                    before authentication runs, which is why it wears a 400
 *   403, code 9109   a token used from outside its IP address filter, recognised by the message
 *                    "Cannot use the access token from location". The same code also means an
 *                    unknown zone id, which is why the message is read at all
 *   2xx, code 1000   the same rejection inside an envelope claiming success
 *
 * The second and third are the ones worth knowing about, because their status points away from
 * the credential. The third is also invisible to Client::verify(), which ignores the IP filter
 * and reports such a token as active. A consumer catching this type at startup gets all four,
 * provided the startup check makes one real call after verifying.
 *
 * Distinct from NotPermittedException, which means the token is real and lacks a permission.
 * The distinction is worth keeping because the fixes are different and neither message says
 * which: one is a new token, the other is a permission added to the token you have.
 */
final class NotAuthenticatedException extends ApiException
{
}
