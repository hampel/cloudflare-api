<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * A 2xx whose body is not the JSON envelope this API always sends.
 *
 * This is not pedantry about content types, it is the failure mode that matters most in a
 * client whose answers are mostly lists. A maintenance page, an error document from a proxy,
 * a captive portal and a truncated response are all a 200 with something other than JSON in
 * it - and decoded permissively they become an empty array, which reaches the caller as
 * "this zone has no records". Deleting records against that answer is the accident this type
 * exists to prevent.
 *
 * Cloudflare makes the point sharper than most: it is itself the thing that serves error
 * pages for the rest of the internet, so an HTML body arriving where JSON was expected is
 * an ordinary event here rather than a remote possibility.
 *
 * It extends ApiException so an existing `catch (ApiException)` sees it, even though nothing
 * was rejected. Connection::raw() exists for the endpoints - the BIND export - whose success
 * really is not JSON, so reaching this type always means something went wrong.
 */
final class MalformedResponseException extends ApiException
{
    public static function forResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        string $body,
    ): self {
        $excerpt = trim(substr($body, 0, 200));

        return new self(
            sprintf(
                'Cloudflare answered %s %s with HTTP %d but the body is not JSON (Content-Type: %s)%s',
                $method,
                $uri,
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type') ?: 'none',
                $excerpt === '' ? '; the body was empty' : ': ' . $excerpt
            ),
            $response->getStatusCode(),
            [],
            $body
        );
    }
}
