<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Enum;

/**
 * Whether a token may be used.
 *
 * A token that is disabled or expired does not usually get far enough to report itself as
 * one - the request is refused before the endpoint runs. The value matters for the case that
 * does come back: a verification that succeeded and still says the credential is not usable.
 */
enum TokenStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Expired = 'expired';
}
