<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

/**
 * The request conflicts with what is already there - HTTP 409.
 *
 * On DNS this is most often a record that would duplicate an existing one, or a name whose
 * type cannot coexist with what is already at that name - a CNAME beside anything else, for
 * instance, which is a rule of DNS rather than of Cloudflare.
 *
 * Worth its own type because it is the one 4xx that a retry cannot fix and a correction to
 * the request usually can: the state on the far side is not wrong, it is occupied.
 */
final class ConflictException extends ApiException
{
}
