<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * Cloudflare failed - HTTP 5xx. Nothing the caller sent is necessarily wrong, and a retry is
 * reasonable in a way it is not for any of the others.
 */
final class ServerException extends ApiException
{
}
