<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Entity;

use Hampel\Cloudflare\Api\Enum\ZoneStatus;
use Hampel\Cloudflare\Api\Enum\ZoneType;
use Hampel\Cloudflare\Api\Support\Cast;

/**
 * A zone - one domain, as Cloudflare holds it.
 *
 * READ-ONLY IN THIS PACKAGE. There is no create, no delete and no settings change: the zone
 * endpoints here list and fetch, and that is all. Adding or removing a zone is a rare act
 * with large consequences, usually done once in the dashboard, and leaving it out means no
 * code path in a consuming application can do it by accident.
 *
 * `id` IS WHAT EVERY OTHER CALL NEEDS and is not the domain name - it is a 32-character hex
 * string. Zones::findByName() is how you get from the name you have to the id you need.
 *
 * CHECK `isActive()` BEFORE WRITING RECORDS AND BELIEVING THEM. A pending zone accepts every
 * DNS change the API can make and serves none of them, because the registrar's nameservers
 * still point somewhere else. Nothing errors. See ZoneStatus.
 */
final class Zone implements \JsonSerializable
{
    /**
     * @param  list<string>  $nameServers  the nameservers Cloudflare has assigned. These are
     *                                     what the domain's registrar must be pointed at, and
     *                                     the zone stays Pending until it is
     * @param  list<string>  $originalNameServers  where the domain pointed before Cloudflare
     * @param  array<string, mixed>  $raw  the payload this was built from, so a field added to
     *                                     the API after this release is still reachable
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?ZoneStatus $status = null,
        public readonly ?ZoneType $type = null,
        public readonly ?bool $paused = null,
        public readonly ?string $accountId = null,
        public readonly ?string $accountName = null,
        public readonly array $nameServers = [],
        public readonly array $originalNameServers = [],
        public readonly ?string $originalRegistrar = null,
        public readonly ?string $planName = null,
        public readonly ?\DateTimeImmutable $createdOn = null,
        public readonly ?\DateTimeImmutable $modifiedOn = null,
        public readonly ?\DateTimeImmutable $activatedOn = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $account = Cast::object($row['account'] ?? null);
        $plan = Cast::object($row['plan'] ?? null);

        return new self(
            Cast::string($row['id'] ?? null) ?? '',
            Cast::string($row['name'] ?? null) ?? '',
            ZoneStatus::tryFrom(Cast::string($row['status'] ?? null) ?? ''),
            ZoneType::tryFrom(Cast::string($row['type'] ?? null) ?? ''),
            Cast::bool($row['paused'] ?? null),
            Cast::string($account['id'] ?? null),
            Cast::string($account['name'] ?? null),
            Cast::strings($row['name_servers'] ?? null),
            Cast::strings($row['original_name_servers'] ?? null),
            Cast::string($row['original_registrar'] ?? null),
            Cast::string($plan['name'] ?? null),
            Cast::datetime($row['created_on'] ?? null),
            Cast::datetime($row['modified_on'] ?? null),
            Cast::datetime($row['activated_on'] ?? null),
            $row,
        );
    }

    /**
     * Whether Cloudflare is actually answering for this domain.
     *
     * The question to ask before trusting a DNS change to have taken effect - see the note on
     * this class.
     */
    public function isActive(): bool
    {
        return $this->status === ZoneStatus::Active;
    }

    /**
     * Whether the zone is waiting for its nameservers to be pointed at Cloudflare.
     */
    public function isPending(): bool
    {
        return $this->status === ZoneStatus::Pending;
    }

    /**
     * Whether Cloudflare holds the whole zone, as opposed to a CNAME setup where it holds
     * only named hostnames.
     */
    public function isFull(): bool
    {
        return $this->type === ZoneType::Full;
    }

    /**
     * Whether proxying is switched off for the entire zone.
     *
     * `paused` is zone-wide and overrides every record's own `proxied` flag, so a zone paused
     * for a debugging session serves every record DNS-only however the records read - which
     * is worth knowing before concluding a record's proxy setting did not take.
     */
    public function isPaused(): bool
    {
        return $this->paused === true;
    }

    /**
     * A name within this zone, as the fully qualified name Cloudflare's record API expects.
     *
     *     $zone->fqdn('www');             // www.example.com
     *     $zone->fqdn('');                // example.com - the apex
     *     $zone->fqdn('www.example.com'); // unchanged; already qualified
     *
     * Cloudflare's record names are absolute everywhere, and a bare label sent where an FQDN
     * belongs is the mistake this exists to prevent - the API appends the zone itself, so it
     * usually works, and on the day it does not the record lands somewhere unintended.
     */
    public function fqdn(string $name): string
    {
        $name = strtolower(trim($name, ". \t\n\r\0\x0B"));
        $zone = strtolower(trim($this->name, '. '));

        if ($name === '' || $name === '@') {
            return $zone;
        }

        return $name === $zone || str_ends_with($name, '.' . $zone) ? $name : $name . '.' . $zone;
    }

    /**
     * What the API sent, unchanged - so a field added after this release is still reachable
     * without waiting for one.
     *
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status?->value,
            'type' => $this->type?->value,
        ];
    }
}
