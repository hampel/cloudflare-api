<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Entity;

use Hampel\Cloudflare\Api\Support\Cast;

/**
 * A domain registered through Cloudflare Registrar.
 *
 * Read from `accounts/{account_id}/registrar/registrations`. The older `registrar/domains`
 * endpoint, with a different object, is deprecated and not used here.
 *
 * THE FIELDS ARE MEASURED, NOT SPECIFIED. The published specification leaves this object
 * undefined - its `result` is typed as "object, array or string" - so everything here comes from
 * a live account on 2026-09-14: `domain_name`, `status`, `created_at`, `expires_at`, `auto_renew`,
 * `privacy_mode` and `locked`, the same seven keys on the list and on a single lookup.
 *
 * `status` AND `privacyMode` ARE STRINGS, not enums. The account measured held one value of each -
 * every registration `active`, every one `redaction` - which is not enough to name the set, and an
 * enum invented from a guess would promise exhaustiveness this package cannot keep. isActive()
 * answers the question most callers have.
 */
final class Registration implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  the payload this was built from, so a field added to the
     *                                     API after this release is still reachable
     */
    public function __construct(
        /** Lower-case, as the API stores and matches it. */
        public readonly string $domainName,
        public readonly ?string $status = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?bool $autoRenew = null,
        public readonly ?string $privacyMode = null,
        public readonly ?bool $locked = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['domain_name'] ?? null) ?? '',
            Cast::string($row['status'] ?? null),
            Cast::datetime($row['created_at'] ?? null),
            Cast::datetime($row['expires_at'] ?? null),
            Cast::bool($row['auto_renew'] ?? null),
            Cast::string($row['privacy_mode'] ?? null),
            Cast::bool($row['locked'] ?? null),
            $row,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether auto-renew is off - the registration that lapses unless somebody acts.
     *
     * False when the field was absent, since an unknown setting is not evidence that renewal is
     * off, and a report built on this should not flag what it cannot see.
     */
    public function lapsesWithoutAction(): bool
    {
        return $this->autoRenew === false;
    }

    /**
     * Whether the registration expires within this many days.
     *
     * False when there is no expiry to read, which is not "expiring soon" by any reading.
     */
    public function expiresWithinDays(int $days): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $threshold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $days . ' days');

        return $threshold !== false && $this->expiresAt <= $threshold;
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : ['domain_name' => $this->domainName];
    }
}
