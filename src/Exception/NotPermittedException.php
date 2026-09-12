<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * The token is valid and does not have the standing for this - HTTP 403.
 *
 * Configuration rather than code: the token was created without the permission the endpoint
 * needs (`DNS:Edit` to write a record, `Zone:Read` to list zones), or its zone or account
 * resource list does not include the one being addressed.
 *
 * CLOUDFLARE DOES NOT REPORT A TOKEN'S PERMISSIONS ANYWHERE, on any response - so unlike an
 * API that publishes its scopes in a header, there is no way to check in advance and no way
 * for this package to tell you which permission is missing. Trying is the only test, which
 * is why the endpoints that can degrade gracefully do so rather than making a caller guess.
 */
final class NotPermittedException extends ApiException
{
}
