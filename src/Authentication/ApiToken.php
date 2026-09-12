<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Authentication;

use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * A Cloudflare API token, sent as a bearer credential.
 *
 * THE ONLY CREDENTIAL WORTH USING FOR THIS. A token is scoped - to named permissions, to a
 * list of zones or accounts, optionally to a set of client IPs and an expiry - and it can be
 * revoked on its own. The Global API Key it replaced is none of those things: one key, all
 * permissions, every zone on the account, and rotating it breaks everything that holds it.
 *
 * Create one at https://dash.cloudflare.com/profile/api-tokens. For this package a token
 * needs `Zone:Read` to see zones and `DNS:Edit` to change records - and `DNS:Read` alone if
 * it will only ever read, which is the one worth reaching for first.
 *
 * The token is not printable from here: __toString(), var_dump() and a stack trace all show
 * the description rather than the value. That is deliberate and is the main thing this class
 * does beyond setting a header - a credential in a transcript is a credential that has to be
 * rotated.
 */
final class ApiToken implements Authentication
{
    private readonly string $token;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('A Cloudflare API token is required.');
        }

        // A token pasted out of the dashboard with the surrounding quotes, or with a
        // newline, reaches the API as a credential that is not the one that was copied - and
        // comes back as "Invalid API Token", which sends whoever reads it to the dashboard
        // to make another one rather than to the variable they set.
        if (strpbrk($token, " \t\n\r\0\x0B") !== false) {
            throw new InvalidArgumentException(
                'A Cloudflare API token contains no whitespace; this one does. Check for a '
                    . 'newline or a stray quote in whatever it was read from.'
            );
        }

        $this->token = $token;
    }

    public function applyTo(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer ' . $this->token);
    }

    /**
     * The token's length and its last four characters, which is enough to tell two
     * credentials apart in a log without being enough to use either.
     *
     * Four characters of a forty-character token is the same trade every dashboard makes. A
     * token short enough that four characters would be a meaningful fraction of it is not a
     * real Cloudflare token, but the guard is here rather than assumed.
     */
    public function describe(): string
    {
        $length = strlen($this->token);

        return $length > 12
            ? sprintf('a Cloudflare API token ending %s (%d characters)', substr($this->token, -4), $length)
            : sprintf('a Cloudflare API token of %d characters', $length);
    }

    public function __toString(): string
    {
        return $this->describe();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->describe()];
    }
}
