<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Exception\ApiException;
use Hampel\Cloudflare\Api\Exception\ClientException;
use Hampel\Cloudflare\Api\Exception\ConflictException;
use Hampel\Cloudflare\Api\Exception\ExceptionInterface;
use Hampel\Cloudflare\Api\Exception\MalformedResponseException;
use Hampel\Cloudflare\Api\Exception\NotAuthenticatedException;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;
use Hampel\Cloudflare\Api\Exception\RequestException;
use Hampel\Cloudflare\Api\Exception\ServerException;
use Hampel\Cloudflare\Api\Exception\TooManyRequestsException;
use Hampel\Cloudflare\Api\Exception\ValidationException;

final class ConnectionTest extends TestCase
{
    public function test_it_sends_the_token_as_a_bearer_credential(): void
    {
        $this->client->pushJson(200, $this->envelope([]));
        $this->cloudflare()->connection()->get('zones');

        $this->assertSame(
            'Bearer test-token-000000000000abcd',
            $this->client->lastRequest()->getHeaderLine('Authorization')
        );
        $this->assertSame('application/json', $this->client->lastRequest()->getHeaderLine('Accept'));
    }

    public function test_it_unwraps_the_result_from_the_envelope(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => 'abc', 'name' => 'example.com']));

        $response = $this->cloudflare()->connection()->get('zones/abc');

        $this->assertSame(['id' => 'abc', 'name' => 'example.com'], $response->object());
        $this->assertSame('example.com', $response->value('name'));
    }

    /**
     * The trap this API carries that most do not: the status says one thing and the body says
     * another. Trusting the status would hand the caller a null result dressed as an empty
     * collection.
     */
    public function test_a_200_whose_body_reports_failure_is_a_failure(): void
    {
        $this->client->pushJson(200, $this->failure([
            ['code' => 7003, 'message' => 'No route for the URI'],
        ]));

        try {
            $this->cloudflare()->connection()->get('zones');
            $this->fail('a success:false body was accepted as success');
        } catch (ApiException $e) {
            $this->assertInstanceOf(ClientException::class, $e);
            $this->assertSame(200, $e->statusCode);
            $this->assertTrue($e->hasCode(7003));
        }
    }

    public function test_a_200_reporting_an_invalid_token_is_an_authentication_failure(): void
    {
        $this->client->pushJson(200, $this->failure([
            ['code' => 1000, 'message' => 'Invalid API Token'],
        ]));

        $this->expectException(NotAuthenticatedException::class);

        $this->cloudflare()->connection()->get('zones');
    }

    /**
     * A credential Cloudflare will not parse is refused before authentication runs, so it
     * arrives as a 400. Measured on 2026-09-13: a placeholder token answers
     * `400 {"code": 6003, "message": "Invalid request headers"}` where a well-formed but wrong
     * one answers `401 / 1000`. Both are the credential, and a consumer catching
     * NotAuthenticatedException at startup has to see both - the 400 is the likelier of the
     * two, being what a truncated value or a leftover placeholder produces.
     */
    public function test_a_400_reporting_unparseable_headers_is_a_credential_failure(): void
    {
        $this->client->pushJson(400, $this->failure([
            ['code' => 6003, 'message' => 'Invalid request headers'],
        ]));

        try {
            $this->cloudflare()->connection()->get('user/tokens/verify');
            $this->fail('did not raise');
        } catch (ApiException $e) {
            $this->assertInstanceOf(NotAuthenticatedException::class, $e);
            $this->assertSame(400, $e->statusCode);
            $this->assertTrue($e->hasCode(ApiException::CODE_INVALID_REQUEST_HEADERS));
        }
    }

    /**
     * A token refused by its IP address filter. Measured on 2026-09-13 from an address outside
     * the filter: every zone and account call answered this, while `verify()` said the token was
     * active. It is the credential that fails, on every call from here - not a permission the
     * token lacks - so it raises the credential type.
     */
    public function test_a_403_refusing_the_token_by_location_is_a_credential_failure(): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => 9109, 'message' => 'Cannot use the access token from location: 203.0.113.99'],
        ]));

        try {
            $this->cloudflare()->connection()->get('zones');
            $this->fail('did not raise');
        } catch (ApiException $e) {
            $this->assertInstanceOf(NotAuthenticatedException::class, $e);
            $this->assertSame(403, $e->statusCode);
            $this->assertTrue($e->hasCode(9109));
        }
    }

    /**
     * The same code with the zone meaning stays a 403 of the ordinary kind. 9109 carries two
     * meanings, and only the location one is the credential.
     */
    public function test_the_same_code_meaning_an_unknown_zone_is_still_not_permitted(): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => 9109, 'message' => 'Invalid zone identifier'],
        ]));

        $this->expectException(NotPermittedException::class);

        $this->cloudflare()->connection()->get('zones/' . self::ZONE_ID);
    }

    /**
     * Only that code. Every other 400 is still a rejected value, or the mapping would hide a
     * genuine validation failure behind a credential one.
     */
    public function test_any_other_400_is_still_a_validation_failure(): void
    {
        $this->client->pushJson(400, $this->failure([
            ['code' => 9021, 'message' => 'TTL must be between 60 and 86400 seconds, or 1 for Automatic.'],
        ]));

        $this->expectException(ValidationException::class);

        $this->cloudflare()->connection()->post('zones/z/dns_records', ['ttl' => 5]);
    }

    public function test_a_2xx_that_is_not_json_is_refused_rather_than_read_as_empty(): void
    {
        $this->client->pushRaw(200, '<html><body>We are having trouble</body></html>', [
            'Content-Type' => 'text/html',
        ]);

        try {
            $this->cloudflare()->connection()->get('zones');
            $this->fail('an HTML body was accepted as an empty result');
        } catch (MalformedResponseException $e) {
            $this->assertStringContainsString('not JSON', $e->getMessage());
            $this->assertStringContainsString('text/html', $e->getMessage());
        }
    }

    public function test_an_empty_2xx_body_is_refused_too(): void
    {
        $this->client->pushRaw(200, '');

        $this->expectException(MalformedResponseException::class);

        $this->cloudflare()->connection()->get('zones');
    }

    /**
     * @return list<array{int, class-string<ApiException>}>
     */
    public static function statuses(): array
    {
        return [
            [400, ValidationException::class],
            [401, NotAuthenticatedException::class],
            [403, NotPermittedException::class],
            [404, NotFoundException::class],
            [409, ConflictException::class],
            [429, TooManyRequestsException::class],
            [500, ServerException::class],
            [503, ServerException::class],
            [418, ClientException::class],
        ];
    }

    /**
     * @param  class-string<ApiException>  $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function test_each_status_maps_to_the_exception_a_caller_can_act_on(int $status, string $expected): void
    {
        $this->client->pushJson($status, $this->failure([
            ['code' => 1234, 'message' => 'Something went wrong'],
        ]));

        try {
            $this->cloudflare()->connection()->get('zones');
            $this->fail('did not raise');
        } catch (ApiException $e) {
            $this->assertInstanceOf($expected, $e);
            $this->assertSame($status, $e->statusCode);
            $this->assertSame(['Something went wrong'], $e->messages());
            $this->assertSame([1234], $e->codes());
        }
    }

    public function test_an_error_naming_a_field_is_reachable_by_that_field(): void
    {
        $this->client->pushJson(400, $this->failure([
            ['code' => 9020, 'message' => 'Invalid TTL', 'source' => ['pointer' => '/ttl']],
            ['code' => 9021, 'message' => 'Bad component', 'source' => ['pointer' => '/data/tag']],
            ['code' => 1000, 'message' => 'Something general'],
        ]));

        try {
            $this->cloudflare()->connection()->post('zones/x/dns_records', ['ttl' => 5]);
            $this->fail('did not raise');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['ttl' => ['Invalid TTL'], 'data.tag' => ['Bad component']],
                $e->fieldErrors(),
                'a JSON pointer is flattened to a field path, and an error naming nothing is left out'
            );
            $this->assertTrue($e->concerns('data.tag'));
            $this->assertFalse($e->concerns('name'));
        }
    }

    public function test_a_transport_failure_is_not_an_api_failure(): void
    {
        $this->client->pushThrowable(new TransportFailure(
            $this->cloudflare()->connection()->request('GET', 'zones')
        ));

        // Caught as the package-wide interface rather than as RequestException, so that
        // "this is NOT an ApiException" is a real assertion about which branch was taken
        // rather than a restatement of the catch block's own type.
        try {
            $this->cloudflare()->connection()->get('zones');
            $this->fail('did not raise');
        } catch (ExceptionInterface $e) {
            // Order matters: assertInstanceOf() narrows $e for everything after it, so the
            // "not an ApiException" check has to come first to be an assertion at all.
            $this->assertNotInstanceOf(ApiException::class, $e);
            $this->assertInstanceOf(RequestException::class, $e);
            $this->assertStringContainsString('Could not reach the Cloudflare API', $e->getMessage());
        }
    }

    /**
     * Laravel's StrayRequestException is a plain RuntimeException, not a PSR-18 one. It must
     * reach the consumer's test naming the URL, rather than arriving as "could not reach
     * Cloudflare" - which would be the wrong diagnosis in the place a wrong diagnosis costs
     * most.
     */
    public function test_a_throwable_that_is_not_a_psr18_failure_passes_through_untouched(): void
    {
        $this->client->pushThrowable(new \RuntimeException('Attempted request to [...] without a matching fake.'));

        try {
            $this->cloudflare()->connection()->get('zones');
            $this->fail('did not raise');
        } catch (\RuntimeException $e) {
            $this->assertSame('Attempted request to [...] without a matching fake.', $e->getMessage());
            $this->assertNotInstanceOf(RequestException::class, $e);
        }
    }

    public function test_patch_and_put_are_distinct_verbs_on_the_wire(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => 'r1']));
        $this->cloudflare()->connection()->patch('zones/z/dns_records/r1', ['ttl' => 300]);

        $this->assertSame('PATCH', $this->sentMethod());
        $this->assertSame(['ttl' => 300], $this->sentBody());

        $this->client->pushJson(200, $this->envelope(['id' => 'r1']));
        $this->cloudflare()->connection()->put('zones/z/dns_records/r1', ['type' => 'A']);

        $this->assertSame('PUT', $this->sentMethod());
    }

    public function test_advisory_messages_on_a_success_are_kept_and_not_treated_as_errors(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => 'r1'], [
            'messages' => [['code' => 81000, 'message' => 'This field will be deprecated']],
        ]));

        $response = $this->cloudflare()->connection()->get('zones/z');

        $this->assertSame(['This field will be deprecated'], $response->notices());
    }

    public function test_the_raw_helper_returns_a_non_json_success_body_untouched(): void
    {
        $this->client->pushRaw(200, "www.example.com. 300 IN A 127.0.0.1\n", ['Content-Type' => 'text/plain']);

        [$body] = $this->cloudflare()->connection()->raw('zones/z/dns_records/export');

        $this->assertSame("www.example.com. 300 IN A 127.0.0.1\n", $body);
    }

    public function test_the_raw_helper_still_raises_on_a_json_failure(): void
    {
        $this->client->pushJson(403, $this->failure([['code' => 9109, 'message' => 'Unauthorized']]));

        $this->expectException(NotPermittedException::class);

        $this->cloudflare()->connection()->raw('zones/z/dns_records/export');
    }

    public function test_the_rate_limit_headers_are_read_from_the_response(): void
    {
        $this->client->pushJson(200, $this->envelope([]), [
            'Ratelimit' => '"default";r=50;t=30',
            'Ratelimit-Policy' => '"default";q=1200;w=300',
            'CF-Ray' => '8a1b2c3d4e5f6789-SYD',
        ]);

        $meta = $this->cloudflare()->connection()->get('zones')->meta;

        $this->assertSame(50, $meta->rateLimitRemaining);
        $this->assertSame(30, $meta->rateLimitResetsIn);
        $this->assertSame(1200, $meta->rateLimit);
        $this->assertSame('8a1b2c3d4e5f6789-SYD', $meta->ray);
        $this->assertTrue($meta->isNearingRateLimit());
    }

    public function test_missing_rate_limit_headers_read_as_unknown_rather_than_exhausted(): void
    {
        $this->client->pushJson(200, $this->envelope([]));

        $meta = $this->cloudflare()->connection()->get('zones')->meta;

        $this->assertNull($meta->rateLimit);
        $this->assertNull($meta->rateLimitRemaining);
        $this->assertFalse(
            $meta->isNearingRateLimit(),
            'a proxy that strips the headers must not look like an exhausted budget'
        );
    }
}
