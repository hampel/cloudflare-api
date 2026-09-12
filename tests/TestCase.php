<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Authentication\Authentication;
use Hampel\Cloudflare\Api\Client;
use Hampel\Cloudflare\Api\Config;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    /**
     * A zone id in the shape Cloudflare uses: 32 hex characters.
     */
    protected const ZONE_ID = '023e105f4ecef8ad9ca31a8372d0c353';

    protected const RECORD_ID = '372e67954025e0ba6aaa6d586b9e0b59';

    protected StubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubClient();
    }

    protected function cloudflare(
        ?Config $config = null,
        ?LoggerInterface $logger = null,
        ?Authentication $authentication = null,
    ): Client {
        $factory = new HttpFactory();

        return new Client(
            $config ?? new Config(),
            $authentication ?? new ApiToken('test-token-000000000000abcd'),
            $this->client,
            $factory,
            $factory,
            $logger ?? new NullLogger()
        );
    }

    /**
     * The body of the last request the stub client was given, decoded.
     *
     * @return array<mixed>
     */
    protected function sentBody(): array
    {
        $decoded = json_decode((string) $this->client->lastRequest()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function sentMethod(): string
    {
        return $this->client->lastRequest()->getMethod();
    }

    /**
     * The path of the last request, without the host - what an assertion about routing
     * actually cares about.
     */
    protected function sentPath(): string
    {
        return $this->client->lastRequest()->getUri()->getPath();
    }

    protected function sentQuery(): string
    {
        return $this->client->lastRequest()->getUri()->getQuery();
    }

    /**
     * The last request's query string, parsed - for asserting on one parameter without
     * pinning the order of the rest.
     *
     * SPLIT BY HAND RATHER THAN WITH parse_str(), which would defeat the purpose here.
     * PHP rewrites a dot in a parameter name to an underscore when building the variable
     * name, so `name.endswith=x` comes back as `name_endswith` - and every filter on this
     * API is a dotted name. A test using parse_str() would report the package sending the
     * wrong parameter when it had sent exactly the right one.
     *
     * @return array<string, string>
     */
    protected function sentParameters(): array
    {
        $parameters = [];

        foreach (explode('&', $this->sentQuery()) as $pair) {
            if ($pair === '') {
                continue;
            }

            $equals = strpos($pair, '=');

            [$key, $value] = $equals === false
                ? [$pair, '']
                : [substr($pair, 0, $equals), substr($pair, $equals + 1)];

            $parameters[rawurldecode($key)] = rawurldecode($value);
        }

        return $parameters;
    }

    /**
     * A success envelope in the shape every endpoint on this API answers with.
     *
     * @param  array<string, mixed>  $extra  `result_info`, `messages` - whatever the case needs
     * @return array<string, mixed>
     */
    protected function envelope(mixed $result, array $extra = []): array
    {
        // array_merge rather than `+`: the union operator keeps the value already present
        // for a duplicate key, so `$extra` could never override `messages`.
        return array_merge(
            ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result],
            $extra
        );
    }

    /**
     * A collection envelope, with the `result_info` Cloudflare sends beside it.
     *
     * @param  list<array<string, mixed>>  $result
     * @return array<string, mixed>
     */
    protected function collection(
        array $result,
        int $page = 1,
        int $totalPages = 1,
        ?int $totalCount = null,
        int $perPage = 100,
    ): array {
        return $this->envelope($result, [
            'result_info' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => count($result),
                'total_count' => $totalCount ?? count($result),
                'total_pages' => $totalPages,
            ],
        ]);
    }

    /**
     * A failure envelope in Cloudflare's shape.
     *
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>
     */
    protected function failure(array $errors): array
    {
        return ['success' => false, 'errors' => $errors, 'messages' => [], 'result' => null];
    }
}
