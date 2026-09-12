<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Enum;

/**
 * The record types Cloudflare DNS supports - all twenty-one of them.
 *
 * THE SET IS SPLIT IN TWO, AND WHICH HALF A TYPE IS IN DECIDES HOW YOU WRITE IT. Eight types
 * carry their value in `content` as a single string. The other thirteen carry it in a `data`
 * object of named components, and their `content` is read-only - Cloudflare composes it from
 * the components and rejects any attempt to set it. usesData() is that question, and it is
 * the one thing about this API most worth knowing before writing a record.
 *
 * A CLOSED SET, AND THAT IS A PROMISE WITH A COST. An exhaustive `match` over this enum in a
 * consumer throws `UnhandledMatchError` the day a case is added, so adding one is a breaking
 * change for this package and gets a major version. Write a `default` arm anyway.
 */
enum RecordType: string
{
    // Content types: the value is one string.
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case NS = 'NS';
    case OPENPGPKEY = 'OPENPGPKEY';
    case PTR = 'PTR';
    case TXT = 'TXT';

    // Data types: the value is an object of components, and `content` is generated.
    case CAA = 'CAA';
    case CERT = 'CERT';
    case DNSKEY = 'DNSKEY';
    case DS = 'DS';
    case HTTPS = 'HTTPS';
    case LOC = 'LOC';
    case NAPTR = 'NAPTR';
    case SMIMEA = 'SMIMEA';
    case SRV = 'SRV';
    case SSHFP = 'SSHFP';
    case SVCB = 'SVCB';
    case TLSA = 'TLSA';
    case URI = 'URI';

    /**
     * Whether this type is written through the `data` object rather than through `content`.
     *
     * For one of these, `content` on a response is a formatted rendering Cloudflare built -
     * useful to read, refused on a write.
     */
    public function usesData(): bool
    {
        return match ($this) {
            self::A, self::AAAA, self::CNAME, self::MX,
            self::NS, self::OPENPGPKEY, self::PTR, self::TXT => false,
            default => true,
        };
    }

    /**
     * Whether this type uses the top-level `priority` field.
     *
     * MX and URI only. SRV has a priority too, but it is a component inside `data` rather
     * than the top-level field - which is exactly the kind of near-miss that makes a record
     * come back with a priority of 0 and no error to say why.
     */
    public function usesPriority(): bool
    {
        return $this === self::MX || $this === self::URI;
    }

    /**
     * Whether Cloudflare can proxy this type - which is only the types that resolve to an
     * address it can stand in front of.
     *
     * A record's own `proxiable` field is the authoritative answer for a record that already
     * exists, because it accounts for the zone's plan and the record's content as well as
     * its type. This is the answer available before one exists.
     */
    public function isProxiable(): bool
    {
        return $this === self::A || $this === self::AAAA || $this === self::CNAME;
    }

    /**
     * An address record, where the content is an IP rather than a name.
     */
    public function isAddress(): bool
    {
        return $this === self::A || $this === self::AAAA;
    }
}
