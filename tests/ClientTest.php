<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Client;
use Hampel\Cloudflare\Api\Endpoint\DnsRecords;
use Hampel\Cloudflare\Api\Endpoint\Endpoint;
use Hampel\Cloudflare\Api\Endpoint\Zones;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Exception\RuntimeException;
use Hampel\Cloudflare\Api\Support\Psr17Discovery;

final class ClientTest extends TestCase
{
    public function test_endpoints_are_memoised(): void
    {
        $client = $this->cloudflare();

        $this->assertSame($client->zones(), $client->zones());
        $this->assertSame($client->records(), $client->records());
        $this->assertInstanceOf(DnsRecords::class, $client->records());
    }

    public function test_any_endpoint_subclass_can_be_constructed_by_name(): void
    {
        $this->assertInstanceOf(Zones::class, $this->cloudflare()->endpoint(Zones::class));
    }

    /**
     * Endpoint itself satisfies class-string<Endpoint>, so nothing but this guard stands
     * between an abstract class and a fatal error on `new`.
     */
    public function test_an_abstract_or_unrelated_class_is_refused_rather_than_fatal(): void
    {
        foreach ([Endpoint::class, \stdClass::class] as $class) {
            try {
                /** @phpstan-ignore-next-line argument.type */
                $this->cloudflare()->endpoint($class);
                $this->fail($class . ' was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('concrete subclass', $e->getMessage());
            }
        }
    }

    public function test_with_token_is_the_short_form_of_the_long_constructor(): void
    {
        $client = Client::withToken('abcdefghijklmnopqrstuvwxyz012345', $this->client);

        $this->client->pushJson(200, $this->envelope([]));
        $client->connection()->get('zones');

        $this->assertSame(
            'Bearer abcdefghijklmnopqrstuvwxyz012345',
            $this->client->lastRequest()->getHeaderLine('Authorization')
        );
    }

    /**
     * Two credentials in one long-running process must not leak into each other's requests,
     * which is why this is a new client rather than a mutation.
     */
    public function test_with_credential_returns_a_separate_client(): void
    {
        $first = $this->cloudflare();
        $second = $first->withCredential(new ApiToken('second-token-0000000000wxyz'));

        $this->assertNotSame($first, $second);

        $this->client->pushJson(200, $this->envelope([]));
        $second->connection()->get('zones');
        $this->assertSame(
            'Bearer second-token-0000000000wxyz',
            $this->client->lastRequest()->getHeaderLine('Authorization')
        );

        $this->client->pushJson(200, $this->envelope([]));
        $first->connection()->get('zones');
        $this->assertSame(
            'Bearer test-token-000000000000abcd',
            $this->client->lastRequest()->getHeaderLine('Authorization'),
            'the original client is untouched'
        );
    }

    public function test_a_psr17_factory_is_discovered_when_none_is_passed(): void
    {
        $client = new Client(
            new \Hampel\Cloudflare\Api\Config(),
            new ApiToken('abcdefghijklmnopqrstuvwxyz012345'),
            $this->client
        );

        $this->client->pushJson(200, $this->envelope([]));
        $client->connection()->get('zones');

        $this->assertCount(1, $this->client->requests);
    }

    public function test_discovery_explains_itself_when_nothing_is_installed(): void
    {
        try {
            Psr17Discovery::from([['Not\\A\\Real\\Factory', 'Not\\A\\Real\\Factory']]);
            $this->fail('did not raise');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nyholm/psr7', $e->getMessage());
        }
    }

    public function test_discovery_finds_guzzles_factory(): void
    {
        [$request, $stream] = Psr17Discovery::find();

        $this->assertInstanceOf(HttpFactory::class, $request);
        $this->assertInstanceOf(HttpFactory::class, $stream);
    }

    public function test_a_token_is_never_printable(): void
    {
        $token = new ApiToken('abcdefghijklmnopqrstuvwxyz012345');

        $this->assertStringNotContainsString('abcdefghij', (string) $token);
        $this->assertStringNotContainsString('abcdefghij', print_r($token, true));
        $this->assertStringContainsString('ending 2345', $token->describe());
        $this->assertStringContainsString('32 characters', $token->describe());
    }

    public function test_a_token_with_stray_whitespace_is_refused_with_the_real_reason(): void
    {
        try {
            new ApiToken("abcdef\nghijkl");
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('newline or a stray quote', $e->getMessage());
        }
    }

    public function test_an_empty_token_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiToken('   ');
    }
}
