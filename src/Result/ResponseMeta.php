<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Result;

use Psr\Http\Message\ResponseInterface;

/**
 * What came back beside the body: the rate limit, and the identifier to quote when asking
 * Cloudflare what happened.
 *
 * THIS API PUBLISHES NO PERMISSIONS. An API that reports a credential's scopes in a response
 * header lets a client check what it may do for free, on the first call. Cloudflare does not,
 * on any endpoint - the only way to find out whether a token can do something is to try it.
 * That absence is worth stating in the one class whose job is "what else came back", because
 * looking for the header is otherwise the natural first move.
 *
 * THE RATE LIMIT HEADERS ARE DOCUMENTED RATHER THAN MEASURED. Cloudflare documents
 * `Ratelimit` and `Ratelimit-Policy` in the draft IETF form - `"default";r=50;t=30` - and
 * this parses that. No token was available when this was written, so whether they appear on
 * every response, on some endpoints only, or not at all through a proxy is unverified; the
 * `verify` harness exercise prints the raw headers and settles it on the first real run.
 * Everything here reads a missing header as "unknown", never as "exhausted".
 */
final class ResponseMeta implements \JsonSerializable
{
    private function __construct(
        /** Requests left in the current window, from `Ratelimit`'s `r` parameter. */
        public readonly ?int $rateLimitRemaining,
        /** Seconds until the window resets, from `Ratelimit`'s `t` parameter. A DURATION, not a timestamp. */
        public readonly ?int $rateLimitResetsIn,
        /** The window's total quota, from `Ratelimit-Policy`'s `q` parameter. */
        public readonly ?int $rateLimit,
        /**
         * From `Retry-After`. Cloudflare documents this as sent only when a limit has
         * actually been exceeded, so unlike some APIs its presence is meaningful on its own.
         */
        public readonly ?int $retryAfter,
        /**
         * `CF-Ray` - the identifier for this request through Cloudflare's own network. The
         * thing to quote in a support ticket, and the only handle on a response that did not
         * come from the API at all but from something in front of it.
         */
        public readonly ?string $ray,
        public readonly string $rateLimitHeader,
        public readonly string $rateLimitPolicyHeader,
    ) {
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $limit = $response->getHeaderLine('Ratelimit');
        $policy = $response->getHeaderLine('Ratelimit-Policy');
        $retryAfter = trim($response->getHeaderLine('Retry-After'));

        return new self(
            self::parameter($limit, 'r'),
            self::parameter($limit, 't'),
            self::parameter($policy, 'q'),
            $retryAfter !== '' && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            $response->getHeaderLine('CF-Ray') ?: null,
            $limit,
            $policy,
        );
    }

    /**
     * When the current rate-limit window resets.
     *
     * Computed from a duration and the moment this was read, so it drifts if the object is
     * kept - which it is not meant to be.
     */
    public function rateLimitResetsAt(): ?\DateTimeImmutable
    {
        if ($this->rateLimitResetsIn === null) {
            return null;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $this->rateLimitResetsIn . ' seconds') ?: null;
    }

    /**
     * Whether less than this proportion of the window is left - the check to make before
     * starting a long walk, rather than after being refused one.
     *
     * Unknown reads as "not running out". A missing header is not evidence of exhaustion, and
     * treating it as such would stop a client whose only problem is a proxy that strips them.
     */
    public function isNearingRateLimit(float $fraction = 0.1): bool
    {
        if ($this->rateLimit === null || $this->rateLimitRemaining === null || $this->rateLimit <= 0) {
            return false;
        }

        return $this->rateLimitRemaining / $this->rateLimit < $fraction;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rate_limit' => $this->rateLimit,
            'rate_limit_remaining' => $this->rateLimitRemaining,
            'rate_limit_resets_in' => $this->rateLimitResetsIn,
            'retry_after' => $this->retryAfter,
            'ray' => $this->ray,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * One parameter out of an IETF rate-limit header: `"default";r=50;t=30`.
     *
     * Written by hand rather than with a pattern because the header is a list of
     * semicolon-separated `key=value` pairs after a quoted name, and the parts this package
     * needs are two integers. Anything unrecognised is left alone - the raw header is kept
     * on the object, so a form this does not understand is still readable.
     */
    private static function parameter(string $header, string $name): ?int
    {
        if (trim($header) === '') {
            return null;
        }

        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            $equals = strpos($part, '=');

            if ($equals === false || substr($part, 0, $equals) !== $name) {
                continue;
            }

            $value = trim(substr($part, $equals + 1), " \t\"");

            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
