<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Authentication;

use Psr\Http\Message\RequestInterface;

/**
 * How a request proves who it is.
 *
 * There is one implementation, because this package supports one kind of credential: an API
 * token, sent as `Authorization: Bearer <token>`. Cloudflare also accepts a legacy Global
 * API Key over `X-Auth-Email` and `X-Auth-Key`, which is deliberately not here - it cannot
 * be scoped to a zone, cannot be verified, and grants everything the account can do to
 * anything that gets hold of it.
 *
 * The interface exists anyway, for the three things that will want to sit behind it: that
 * legacy key if it is ever genuinely needed, a token resolved per tenant at request time
 * rather than at construction, and a token read from a secret store that rotates.
 */
interface Authentication
{
    public function applyTo(RequestInterface $request): RequestInterface;

    /**
     * What this credential is, for a log line or an exception message.
     *
     * MUST NOT INCLUDE THE TOKEN, or any part of it long enough to be useful. A credential
     * ends up in an error message far more often than anyone intends, and this method is the
     * reason a token does not.
     */
    public function describe(): string;
}
