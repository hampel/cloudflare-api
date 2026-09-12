<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Entity;

use Hampel\Cloudflare\Api\Support\Cast;

/**
 * An account - the billing and ownership container a zone belongs to.
 *
 * Present here for one job: proving a token reaches what it is supposed to reach. A token's
 * permissions are invisible on this API, so "which accounts can this credential see" is
 * answered by asking, and the answer is the closest thing to a capability report there is.
 *
 * A ZONE-SCOPED TOKEN CANNOT LIST ACCOUNTS AT ALL, and that is correct rather than a
 * limitation - a credential that manages one zone's DNS has no business enumerating the
 * account it sits in. Accounts::find() absorbs that refusal so a diagnostic can report what
 * it can rather than stopping at the first thing it is not allowed to see.
 */
final class Account implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** `standard` or `enterprise`. Left as a string: it governs nothing this package does. */
        public readonly ?string $type = null,
        public readonly ?string $abuseContactEmail = null,
        public readonly ?bool $enforcesTwoFactor = null,
        public readonly ?\DateTimeImmutable $createdOn = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $settings = Cast::object($row['settings'] ?? null);

        return new self(
            Cast::string($row['id'] ?? null) ?? '',
            Cast::string($row['name'] ?? null) ?? '',
            Cast::string($row['type'] ?? null),
            Cast::string($settings['abuse_contact_email'] ?? null),
            Cast::bool($settings['enforce_twofactor'] ?? null),
            Cast::datetime($row['created_on'] ?? null),
            $row,
        );
    }

    public function isEnterprise(): bool
    {
        return $this->type === 'enterprise';
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : ['id' => $this->id, 'name' => $this->name];
    }
}
