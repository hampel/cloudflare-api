<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;
use Hampel\Cloudflare\Api\Exception\NotAuthenticatedException;
use Hampel\Cloudflare\Api\Endpoint\Accounts;

final class AccountsTest extends TestCase
{
    private const ACCOUNT_ID = '01a7362d577a6c3019a474fd6f485823';

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => self::ACCOUNT_ID,
            'name' => 'Example Account',
            'type' => 'standard',
            'created_on' => '2014-03-01T12:21:02.0000Z',
            'settings' => ['abuse_contact_email' => 'abuse@example.com', 'enforce_twofactor' => false],
        ];
    }

    public function test_it_lists_accounts(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()], perPage: 20));

        $page = $this->cloudflare()->accounts()->list();

        $this->assertSame('/client/v4/accounts', $this->sentPath());
        $this->assertSame('Example Account', $page->items[0]->name);
        $this->assertSame('abuse@example.com', $page->items[0]->abuseContactEmail);
        $this->assertFalse($page->items[0]->isEnterprise());
    }

    public function test_the_page_size_limits_match_zones_rather_than_dns_records(): void
    {
        try {
            $this->cloudflare()->accounts()->list(pageSize: 100);
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('between 5 and 50 for accounts', $e->getMessage());
        }
    }

    /**
     * A token scoped to DNS is refused here, and that is correct rather than a limitation -
     * so a diagnostic should be able to report what it can instead of stopping.
     */
    public function test_first_degrades_gracefully_when_the_token_may_not_read_accounts(): void
    {
        $logger = new RecordingLogger();
        $this->client->pushJson(403, $this->failure([
            ['code' => 9109, 'message' => 'Unauthorized to access requested resource'],
        ]));

        $this->assertNull($this->cloudflare(logger: $logger)->accounts()->first());
        $this->assertNotNull($logger->contextFor('Cloudflare accounts are not readable by this token'));
    }

    public function test_list_still_raises_so_the_two_cases_can_be_told_apart(): void
    {
        $this->client->pushJson(403, $this->failure([['code' => 9109, 'message' => 'Unauthorized']]));

        $this->expectException(NotPermittedException::class);

        $this->cloudflare()->accounts()->list();
    }

    /**
     * The shape a zone-scoped token actually gets, measured on 2026-09-12: a 200 and an
     * empty collection rather than a refusal. It reads as "this user has no accounts", which
     * is never true - it means the token's resources include none.
     */
    public function test_an_empty_list_is_what_a_zone_scoped_token_gets_rather_than_a_refusal(): void
    {
        $this->client->pushJson(200, $this->collection([], perPage: 5));

        $this->assertNull($this->cloudflare()->accounts()->first());

        $this->client->pushJson(200, $this->collection([], perPage: 5));
        $page = $this->cloudflare()->accounts()->list(1, 5);

        $this->assertSame(0, $page->total());
        $this->assertTrue($page->isEmpty());
    }

    /**
     * Found by extending a report about Zones::find(): both Accounts methods absorbed any 403,
     * so a token refused by its IP filter read as "this token cannot read accounts" rather than
     * "this token cannot be used from here". Reproduced live on 2026-09-13.
     *
     * @return array<string, array{\Closure(Accounts): mixed}>
     */
    public static function degradingMethods(): array
    {
        return [
            'first' => [static fn (Accounts $accounts): mixed => $accounts->first()],
            'find' => [static fn (Accounts $accounts): mixed => $accounts->find(self::ACCOUNT_ID)],
        ];
    }

    /**
     * @param  \Closure(Accounts): mixed  $call
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('degradingMethods')]
    public function test_a_location_refusal_is_not_absorbed_as_an_unreadable_account(\Closure $call): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => 9109, 'message' => 'Cannot use the access token from location: 203.0.113.99'],
        ]));

        $this->expectException(NotAuthenticatedException::class);

        $call($this->cloudflare()->accounts());
    }

    public function test_find_absorbs_both_absence_and_refusal(): void
    {
        $this->client->pushJson(404, $this->failure([['code' => 7003, 'message' => 'No route for the URI']]));
        $this->assertNull($this->cloudflare()->accounts()->find(self::ACCOUNT_ID));

        $this->client->pushJson(403, $this->failure([['code' => 9109, 'message' => 'Unauthorized']]));
        $this->assertNull($this->cloudflare()->accounts()->find(self::ACCOUNT_ID));
    }
}
