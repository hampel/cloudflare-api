<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The request never got an answer: DNS, TLS, a timeout, a refused connection.
 *
 * Distinct from every other exception here, all of which mean Cloudflare replied and the
 * reply was not a success. A retry is reasonable for this one and frequently is not for the
 * others.
 */
final class RequestException extends CloudflareException
{
    public static function for(string $method, string $uri, ClientExceptionInterface $previous): self
    {
        return new self(
            sprintf('Could not reach the Cloudflare API for %s %s: %s', $method, $uri, $previous->getMessage()),
            0,
            $previous
        );
    }
}
