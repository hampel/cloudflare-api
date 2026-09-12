<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Support;

/**
 * Reading a value out of an API result without trusting it.
 *
 * Every one of these returns null rather than throwing when the value is absent or the wrong
 * shape. A field a token may not see, a field added after this package was written and a
 * field that is only present on some plans all look the same from here, and a client that
 * treated any of them as an error would break on an account whose only difference was its
 * subscription.
 *
 * CLOUDFLARE SENDS ITS NUMBERS AS JSON NUMBERS, which the schema types as `number` rather
 * than `integer` even for a TTL or a page count. So an integer field can legitimately arrive
 * as a float, and int() accepts one.
 */
final class Cast
{
    public static function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-'))
            ? (int) $value
            : null;
    }

    public static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match ($value) {
            1, '1' => true,
            0, '0' => false,
            default => null,
        };
    }

    /**
     * @return array<mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * An object out of a response, with only its string keys kept - which is what an
     * entity's fromArray() takes.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    /**
     * A list of strings, with anything that is not one dropped.
     *
     * For `tags`, `name_servers` and `permissions` - all arrays of strings, none worth a
     * class of its own.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * A Cloudflare timestamp, as a DateTimeImmutable in UTC.
     *
     * CLOUDFLARE DOES QUALIFY ITS DATES, which is worth saying because several APIs do not:
     * every `created_on`, `modified_on` and `expires_on` in the specification carries a `Z`,
     * and the fractional second runs to five places - `2014-01-01T05:20:00.12345Z`. PHP
     * parses both without help.
     *
     * The timezone is still supplied as a fallback and the result still normalised to UTC,
     * for the one case that would otherwise be silent: a value arriving WITHOUT an offset
     * would be read in PHP's own default timezone, so the same response would mean different
     * instants on a box set to Australia/Sydney and one set to UTC. Nothing would error and a
     * comparison against `now` would quietly answer wrongly.
     */
    public static function datetime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        try {
            $parsed = new \DateTimeImmutable($value, $utc);
        } catch (\Exception) {
            return null;
        }

        return $parsed->setTimezone($utc);
    }
}
