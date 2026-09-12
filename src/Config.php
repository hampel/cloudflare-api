<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api;

use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;

/**
 * Which API we are talking to, and how its URLs are built.
 *
 * There is exactly one Cloudflare and exactly one version of its REST API, so this is
 * constructible with no arguments at all and usually should be:
 *
 *     new Config()
 *
 * The base URI is settable for the two cases that need it - a recorded fixture served
 * locally, and an outbound proxy that terminates the connection - and for nothing else.
 *
 * THE VERSION IS PART OF THE PATH AND IS NOT A CHOICE. `/client/v4` has been the only
 * version since 2014; there is no v5 to point at and no beta segment to switch to. It is a
 * constant here rather than a parameter because a settable one would invite an application
 * to make it configurable, and the only values it could take are wrong.
 */
final class Config
{
    public const DEFAULT_HOST = 'api.cloudflare.com';

    /**
     * The path segment every endpoint sits under, version included.
     */
    public const BASE_PATH = '/client/v4';

    public readonly string $baseUri;

    /**
     * @param  string|null  $baseUri  the API root WITHOUT the `/client/v4` segment, e.g.
     *                                `https://api.cloudflare.com`. Null uses Cloudflare's own
     * @param  int|null  $pageSize  how many items a list request asks for when the caller does
     *                              not say. Null uses each endpoint's own default, which
     *                              differs between them - 100 for DNS records, 20 for zones
     *                              and accounts. The endpoints validate this against their own
     *                              limits, which also differ
     */
    public function __construct(
        ?string $baseUri = null,
        public readonly ?int $pageSize = null,
    ) {
        if ($pageSize !== null && $pageSize < 1) {
            throw new InvalidArgumentException(sprintf(
                'A default page size must be at least 1; %d was given. Each endpoint applies '
                    . 'its own upper limit when the request is built.',
                $pageSize
            ));
        }

        $baseUri = trim($baseUri ?? 'https://' . self::DEFAULT_HOST);

        if ($baseUri === '') {
            throw new InvalidArgumentException('The Cloudflare API base URI cannot be empty.');
        }

        if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
            throw new InvalidArgumentException(sprintf(
                'The Cloudflare API base URI must be absolute, with a scheme: "%s" is not.',
                $baseUri
            ));
        }

        $this->baseUri = rtrim($baseUri, '/');
    }

    /**
     * Turn a path into an absolute URI.
     *
     * Three shapes arrive here. A relative path - `zones/023e.../dns_records` - is the usual
     * one. An absolute URI passes through untouched, so a URL the API itself produced can be
     * fed straight back in. And a path that already carries `/client/v4` is normalised rather
     * than doubled, which is what makes a path copied out of the documentation work as
     * readily as one written for this package.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function resolve(string $path, array $query = []): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $uri = $path;
        } else {
            $path = ltrim($path, '/');
            $prefix = ltrim(self::BASE_PATH, '/');

            if ($path === $prefix) {
                $path = '';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix) + 1);
            }

            $uri = rtrim($this->baseUri . self::BASE_PATH . '/' . $path, '/');
        }

        $query = self::queryString($query);

        if ($query !== '') {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . $query;
        }

        return $uri;
    }

    /**
     * The host the client will talk to, for anything that needs to name the endpoint - a log
     * line, an exception message, a settings screen.
     */
    public function host(): string
    {
        return parse_url($this->baseUri, PHP_URL_HOST) ?: self::DEFAULT_HOST;
    }

    /**
     * Build the query string, with booleans written the way Cloudflare reads them.
     *
     * `http_build_query()` renders true as `1` and false as `0`, and this API wants `true`
     * and `false`. The difference is invisible in the good case - a `proxied=1` is generally
     * understood - and the bad case is the expensive one: a filter the API does not accept is
     * not an error, it is an unfiltered collection returned with a 200. Anything that walks
     * that result and deletes what it finds has just been handed the whole zone.
     *
     * Null values are dropped entirely, so an unset optional parameter costs nothing at the
     * call site. An empty STRING is kept, because Cloudflare has presence-only parameters -
     * `comment.present=` - whose whole meaning is the key appearing at all.
     *
     * @param  array<string, scalar|null>  $query
     */
    public static function queryString(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            $pairs[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return $pairs === [] ? '' : http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }
}
