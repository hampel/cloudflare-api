<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * A failure this package does not name specifically.
 *
 * Two things reach here. A 4xx with a status the concrete types above do not cover, which
 * means the API has started using one it did not before. And a 2xx whose body said
 * `"success": false` without an error code this package recognises - a real shape on this
 * API, and one that must not be read as success whatever else is unclear about it.
 */
final class ClientException extends ApiException
{
}
