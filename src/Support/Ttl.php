<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Support;

use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;

/**
 * The TTL values Cloudflare DNS accepts, and what `1` actually means.
 *
 * `1` IS NOT ONE SECOND. It is the magic value for "automatic", which Cloudflare serves as
 * 300 seconds. It is also the default for a new record, so a record created without a TTL
 * reports `ttl: 1` rather than `ttl: 300`, and a naive `$record->ttl` in a report says one
 * second to anyone reading it.
 *
 * ANYTHING ELSE MUST BE 60-86400, and out of that range is a 400 rather than a silent
 * correction. That cuts the other way from APIs that round a value up to the nearest one
 * they like: here a rejected TTL costs a round trip but nothing is ever stored except what
 * was asked for. assertValid() spends neither.
 *
 * The floor drops to 30 on an Enterprise zone. This package cannot tell what plan a zone is
 * on from a TTL alone, so the strict check is the default and ENTERPRISE_MINIMUM is there
 * for a caller that knows better:
 *
 *     Ttl::assertValid(30);                      // throws
 *     Ttl::assertValid(30, enterprise: true);    // fine
 *
 * A PROXIED RECORD HAS NO TTL OF ITS OWN. Cloudflare forces automatic on anything orange-
 * clouded, because the value being served is its own anycast address and it needs to be able
 * to change it - so setting one alongside `proxied: true` is refused. See DnsRecord::proxied().
 */
final class Ttl
{
    /**
     * Cloudflare's "automatic", which it serves as 300 seconds.
     */
    public const AUTOMATIC = 1;

    /**
     * What automatic resolves to on the wire.
     */
    public const AUTOMATIC_SECONDS = 300;

    public const MINIMUM = 60;

    /**
     * Enterprise zones only. Everything else is refused below 60.
     */
    public const ENTERPRISE_MINIMUM = 30;

    public const MAXIMUM = 86400;

    public static function isAutomatic(int $ttl): bool
    {
        return $ttl === self::AUTOMATIC;
    }

    public static function isValid(int $ttl, bool $enterprise = false): bool
    {
        if ($ttl === self::AUTOMATIC) {
            return true;
        }

        $minimum = $enterprise ? self::ENTERPRISE_MINIMUM : self::MINIMUM;

        return $ttl >= $minimum && $ttl <= self::MAXIMUM;
    }

    /**
     * Refuse a TTL Cloudflare will refuse, before spending a request finding out.
     */
    public static function assertValid(int $ttl, bool $enterprise = false): void
    {
        if (self::isValid($ttl, $enterprise)) {
            return;
        }

        $minimum = $enterprise ? self::ENTERPRISE_MINIMUM : self::MINIMUM;

        throw new InvalidArgumentException(sprintf(
            'Cloudflare accepts a TTL of %d ("automatic", served as %d seconds) or a value '
                . 'between %d and %d; %d was asked for.%s',
            self::AUTOMATIC,
            self::AUTOMATIC_SECONDS,
            $minimum,
            self::MAXIMUM,
            $ttl,
            $enterprise || $ttl >= self::ENTERPRISE_MINIMUM && $ttl < self::MINIMUM
                ? ' The floor of ' . self::ENTERPRISE_MINIMUM . ' applies to Enterprise zones only.'
                : ''
        ));
    }

    /**
     * The interval this TTL will actually be served as, with automatic resolved.
     *
     * What to print beside a record rather than the raw field - `1` is the one value that
     * means something other than itself.
     */
    public static function effective(int $ttl): int
    {
        return $ttl === self::AUTOMATIC ? self::AUTOMATIC_SECONDS : $ttl;
    }

    /**
     * How to write a TTL for a human: "automatic (300s)" or "3600s".
     */
    public static function describe(int $ttl): string
    {
        return $ttl === self::AUTOMATIC
            ? sprintf('automatic (%ds)', self::AUTOMATIC_SECONDS)
            : $ttl . 's';
    }
}
