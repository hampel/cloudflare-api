<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Result;

use Hampel\Cloudflare\Api\Enum\TokenStatus;
use Hampel\Cloudflare\Api\Support\Cast;

/**
 * The answer to "does this token work" - from one request.
 *
 * `GET /user/tokens/verify` is the cheapest call on the API and needs no permission of its
 * own, so it succeeds for any token that is valid at all. That makes it the right check at
 * startup: every other endpoint conflates "your token is wrong" with "your token may not do
 * this", and this one cannot, because there is nothing here to be refused for.
 *
 * REACHING THIS OBJECT MEANS THE TOKEN IS REAL. A bad token does not produce one of these
 * saying so - it raises NotAuthenticatedException, because "the credential is wrong" is a
 * failure and returning a valid-looking object for it invites a caller who forgot to check a
 * boolean to carry on as though everything were fine.
 *
 *     try {
 *         $token = $cloudflare->verify();
 *     } catch (NotAuthenticatedException) {
 *         // missing, malformed, revoked - Cloudflare does not say which
 *     }
 *
 *     $token->isActive();     // real, and usable right now
 *
 * IT DOES NOT TELL YOU WHAT THE TOKEN MAY DO. Cloudflare publishes a token's permissions
 * nowhere - not here, not in a response header, not anywhere on the API this credential can
 * reach. So there is no equivalent of a scope check, and a startup routine that wants to
 * know whether it can manage DNS has to try: list the zones, and handle the 403. Accounts
 * and Zones both degrade gracefully for exactly this reason.
 */
final class TokenVerification implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly ?TokenStatus $status,
        /** When the token stops being accepted. Null for one with no expiry, which is the default. */
        public readonly ?\DateTimeImmutable $expiresOn,
        /** When the token starts being accepted. Null unless one was set. */
        public readonly ?\DateTimeImmutable $notBefore,
        public readonly ResponseMeta $meta,
        public readonly string $rawStatus = '',
    ) {
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function fromArray(array $result, ResponseMeta $meta): self
    {
        $status = Cast::string($result['status'] ?? null) ?? '';

        return new self(
            Cast::string($result['id'] ?? null) ?? '',
            TokenStatus::tryFrom($status),
            Cast::datetime($result['expires_on'] ?? null),
            Cast::datetime($result['not_before'] ?? null),
            $meta,
            $status,
        );
    }

    /**
     * Whether the token is usable right now.
     *
     * A verification that SUCCEEDS and reports a status other than active is the case this
     * exists for - rare, because a disabled or expired token is usually refused before the
     * endpoint runs, and worth handling because it is indistinguishable from success anywhere
     * else.
     */
    public function isActive(): bool
    {
        return $this->status === TokenStatus::Active;
    }

    /**
     * Whether the token has an expiry at all. Most do not.
     */
    public function expires(): bool
    {
        return $this->expiresOn !== null;
    }

    /**
     * Whether the token expires within this many days - the check worth running on a schedule
     * rather than discovering the morning it stops working.
     *
     * False for a token with no expiry, which is not "expiring soon" by any reading.
     */
    public function expiresWithinDays(int $days): bool
    {
        if ($this->expiresOn === null) {
            return false;
        }

        $threshold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $days . ' days');

        return $threshold !== false && $this->expiresOn <= $threshold;
    }

    /**
     * One line naming the token and its state, for a startup log or a diagnostic command.
     * Carries no part of the token's value - the id is a public identifier, not the secret.
     */
    public function summary(): string
    {
        return sprintf(
            'Cloudflare API token %s, status: %s, %s',
            $this->id === '' ? '(unidentified)' : $this->id,
            $this->status->value ?? ($this->rawStatus === '' ? 'unknown' : $this->rawStatus),
            $this->expiresOn === null
                ? 'no expiry'
                : 'expires ' . $this->expiresOn->format('Y-m-d H:i:s \U\T\C')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value ?? $this->rawStatus,
            'expires_on' => $this->expiresOn?->format(\DateTimeInterface::ATOM),
            'not_before' => $this->notBefore?->format(\DateTimeInterface::ATOM),
            'meta' => $this->meta->toArray(),
        ];
    }
}
