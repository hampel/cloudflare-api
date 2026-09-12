<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Enum\TokenStatus;
use Hampel\Cloudflare\Api\Exception\NotAuthenticatedException;

final class TokensTest extends TestCase
{
    private const TOKEN_ID = 'ed17574386854bf78a67040be0a770b0';

    public function test_it_verifies_a_live_token(): void
    {
        $this->client->pushJson(200, $this->envelope([
            'id' => self::TOKEN_ID,
            'status' => 'active',
        ], [
            'messages' => [['code' => 10000, 'message' => 'This API Token is valid and active']],
        ]));

        $verification = $this->cloudflare()->verify();

        $this->assertSame('/client/v4/user/tokens/verify', $this->sentPath());
        $this->assertSame(self::TOKEN_ID, $verification->id);
        $this->assertSame(TokenStatus::Active, $verification->status);
        $this->assertTrue($verification->isActive());
        $this->assertFalse($verification->expires());
    }

    /**
     * A bad credential must not produce a valid-looking object with a flag on it - that
     * invites a caller who forgot to check the flag to carry on as though all were well.
     */
    public function test_an_invalid_token_raises_rather_than_reporting_itself(): void
    {
        $this->client->pushJson(401, $this->failure([
            ['code' => 1000, 'message' => 'Invalid API Token'],
        ]));

        try {
            $this->cloudflare()->verify();
            $this->fail('did not raise');
        } catch (NotAuthenticatedException $e) {
            $this->assertTrue($e->hasCode(1000));
        }
    }

    /**
     * The case that does come back: a verification that succeeded and still says the
     * credential is not usable.
     */
    public function test_a_token_that_verifies_but_is_not_active_is_visible(): void
    {
        $this->client->pushJson(200, $this->envelope([
            'id' => self::TOKEN_ID,
            'status' => 'disabled',
        ]));

        $verification = $this->cloudflare()->verify();

        $this->assertSame(TokenStatus::Disabled, $verification->status);
        $this->assertFalse($verification->isActive());
    }

    public function test_an_expiry_is_parsed_and_can_be_checked_ahead_of_time(): void
    {
        $soon = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 days');

        $this->client->pushJson(200, $this->envelope([
            'id' => self::TOKEN_ID,
            'status' => 'active',
            'expires_on' => $soon->format(\DateTimeInterface::ATOM),
            'not_before' => '2018-07-01T05:20:00Z',
        ]));

        $verification = $this->cloudflare()->verify();

        $this->assertTrue($verification->expires());
        $this->assertTrue($verification->expiresWithinDays(30));
        $this->assertFalse($verification->expiresWithinDays(5));
        $this->assertSame('2018-07-01', $verification->notBefore?->format('Y-m-d'));
    }

    public function test_a_token_with_no_expiry_is_not_expiring_soon(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => self::TOKEN_ID, 'status' => 'active']));

        $this->assertFalse($this->cloudflare()->verify()->expiresWithinDays(3650));
    }

    public function test_the_summary_names_the_token_without_carrying_the_secret(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => self::TOKEN_ID, 'status' => 'active']));

        $summary = $this->cloudflare()->verify()->summary();

        $this->assertStringContainsString(self::TOKEN_ID, $summary);
        $this->assertStringContainsString('no expiry', $summary);
        $this->assertStringNotContainsString('test-token-000000000000abcd', $summary);
    }

    public function test_an_account_scoped_token_verifies_against_the_account_path(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => self::TOKEN_ID, 'status' => 'active']));

        $this->cloudflare()->tokens()->verifyForAccount('01a7362d577a6c3019a474fd6f485823');

        $this->assertSame(
            '/client/v4/accounts/01a7362d577a6c3019a474fd6f485823/tokens/verify',
            $this->sentPath()
        );
    }

    public function test_an_unmapped_status_is_kept_raw_rather_than_lost(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => self::TOKEN_ID, 'status' => 'something-new']));

        $verification = $this->cloudflare()->verify();

        $this->assertNull($verification->status);
        $this->assertFalse($verification->isActive());
        $this->assertStringContainsString('something-new', $verification->summary());
    }
}
