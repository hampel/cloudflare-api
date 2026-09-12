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
 * for this package to tell you which permission is missing. Trying is the only test, which is
 * why the endpoints that can degrade gracefully do so rather than making a caller guess.
 *
 * THE CODE SEPARATES TWO VERY DIFFERENT 403s, and it is the only thing that does. Measured on
 * 2026-09-12:
 *
 *   9109  "Invalid zone identifier" - the zone id is unknown or belongs to someone else.
 *         Absence, wearing a 403. Zones::find() treats it as such.
 *   10000 "Authentication error" - the token is real and its permissions or resources do not
 *         cover this. A configuration problem, and never absorbed anywhere in this package.
 *
 * Branch with hasCode(); the messages are prose and the status is the same for both.
 */
final class NotPermittedException extends ApiException
{
}
