<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Endpoint\BoundDnsRecords;
use Hampel\Cloudflare\Api\Endpoint\DnsRecords;
use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Support\RecordQuery;

/**
 * Every method here is a one-line forward to DnsRecords, which is exactly why they are worth
 * a test of their own.
 *
 * DnsRecordsTest proves the unbound endpoint addresses the right thing. It says nothing about
 * the wrapper, and the wrapper is the form the documentation recommends - `zones()->records()`
 * rather than repeating a 32-character hex id on every call.
 *
 * THE FAILURE THIS GUARDS AGAINST IS A TRANSPOSITION. `patch()`, `replace()` and `delete()`
 * take a record id and forward it after the zone id; both are strings, so swapping the pair
 * compiles, satisfies static analysis, and silently addresses a different record in a
 * different zone. Nothing else in the suite would notice. So each assertion below checks the
 * URL the request actually went to, not merely that a call was made.
 */
final class BoundDnsRecordsTest extends TestCase
{
    private const OTHER_ID = 'ffffffffffffffffffffffffffffffff';

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => self::RECORD_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '203.0.113.10',
            'ttl' => 3600,
            'proxied' => false,
            'tags' => [],
            'comment' => null,
        ];
    }

    private function bound(): BoundDnsRecords
    {
        return $this->cloudflare()->zones()->records(self::ZONE_ID);
    }

    private function collectionPath(): string
    {
        return '/client/v4/zones/' . self::ZONE_ID . '/dns_records';
    }

    public function test_it_exposes_the_zone_it_is_bound_to(): void
    {
        $this->assertSame(self::ZONE_ID, $this->bound()->zoneId);
    }

    /**
     * The escape hatch, for anything not forwarded. It must hand back the real endpoint
     * rather than another wrapper, or a caller reaching past the binding gets no benefit.
     */
    public function test_it_hands_back_the_unbound_endpoint(): void
    {
        $this->assertInstanceOf(DnsRecords::class, $this->bound()->endpoint());
    }

    public function test_list_forwards_the_zone_the_page_and_the_query(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $page = $this->bound()->list(2, RecordQuery::ofType(RecordType::A), 50);

        $this->assertCount(1, $page);
        $this->assertSame($this->collectionPath(), $this->sentPath());
        $this->assertSame(
            ['type' => 'A', 'per_page' => '50', 'page' => '2'],
            $this->sentParameters()
        );
    }

    public function test_each_forwards_the_zone_and_walks(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()], page: 1, totalPages: 2, totalCount: 2));
        $this->client->pushJson(200, $this->collection([$this->row()], page: 2, totalPages: 2, totalCount: 2));

        $records = iterator_to_array($this->bound()->each(), false);

        $this->assertCount(2, $records);
        $this->assertSame($this->collectionPath(), $this->sentPath());
        $this->assertSame('2', $this->sentParameters()['page']);
    }

    public function test_all_forwards_the_zone_and_the_query(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $this->assertCount(1, $this->bound()->all(RecordQuery::make()->nameEndsWith('.example.com')));
        $this->assertSame($this->collectionPath(), $this->sentPath());
        $this->assertSame('.example.com', $this->sentParameters()['name.endswith']);
    }

    public function test_of_type_forwards_the_zone_and_the_type(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $this->bound()->ofType(RecordType::MX);

        $this->assertSame($this->collectionPath(), $this->sentPath());
        $this->assertSame('MX', $this->sentParameters()['type']);
    }

    public function test_named_forwards_the_zone_and_the_name(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $this->bound()->named('www.example.com');

        $this->assertSame($this->collectionPath(), $this->sentPath());
        $this->assertSame('www.example.com', $this->sentParameters()['name.exact']);
    }

    public function test_get_forwards_the_zone_and_the_record_in_that_order(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->assertSame(self::RECORD_ID, $this->bound()->get(self::RECORD_ID)->id);
        $this->assertSame($this->collectionPath() . '/' . self::RECORD_ID, $this->sentPath());
    }

    public function test_find_forwards_the_zone_and_the_record(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->assertNotNull($this->bound()->find(self::RECORD_ID));
        $this->assertSame($this->collectionPath() . '/' . self::RECORD_ID, $this->sentPath());
    }

    public function test_find_still_absorbs_absence_through_the_wrapper(): void
    {
        $this->client->pushJson(404, $this->failure([['code' => 81044, 'message' => 'Record does not exist.']]));

        $this->assertNull($this->bound()->find(self::RECORD_ID));
    }

    public function test_create_forwards_the_zone_and_the_payload(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->bound()->create(DnsRecord::a('www.example.com', '203.0.113.10'));

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame($this->collectionPath(), $this->sentPath());
        $this->assertSame(
            ['type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10'],
            $this->sentBody()
        );
    }

    /**
     * The transposition guard. A record id sent where the zone id belongs would produce
     * `/zones/<record>/dns_records/<zone>` - a real path, addressing something else entirely.
     */
    public function test_patch_forwards_zone_then_record_and_not_the_other_way_round(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->bound()->patch(self::RECORD_ID, ['ttl' => 300]);

        $this->assertSame('PATCH', $this->sentMethod());
        $this->assertSame($this->collectionPath() . '/' . self::RECORD_ID, $this->sentPath());
        $this->assertSame(['ttl' => 300], $this->sentBody());
    }

    public function test_replace_forwards_zone_then_record_and_stays_a_put(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->bound()->replace(self::RECORD_ID, DnsRecord::a('www.example.com', '203.0.113.11'));

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame($this->collectionPath() . '/' . self::RECORD_ID, $this->sentPath());
    }

    public function test_delete_forwards_zone_then_record_and_returns_the_confirmed_id(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => self::RECORD_ID]));

        $this->assertSame(self::RECORD_ID, $this->bound()->delete(self::RECORD_ID));
        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame($this->collectionPath() . '/' . self::RECORD_ID, $this->sentPath());
    }

    public function test_export_forwards_the_zone(): void
    {
        $this->client->pushRaw(200, "www.example.com. 300 IN A 203.0.113.10\n", ['Content-Type' => 'text/plain']);

        $this->assertStringContainsString('203.0.113.10', $this->bound()->export());
        $this->assertSame($this->collectionPath() . '/export', $this->sentPath());
    }

    /**
     * Two wrappers over one endpoint must not share a zone. They are separate objects around
     * the same memoised DnsRecords, so a binding held in a property would make the second
     * silently rewrite the first's target.
     */
    public function test_two_bindings_of_the_same_endpoint_do_not_leak_into_each_other(): void
    {
        $cloudflare = $this->cloudflare();
        $first = $cloudflare->zones()->records(self::ZONE_ID);
        $second = $cloudflare->zones()->records(self::OTHER_ID);

        $this->client->pushJson(200, $this->envelope($this->row()));
        $second->get(self::RECORD_ID);
        $this->assertStringContainsString('/zones/' . self::OTHER_ID . '/', $this->sentPath());

        $this->client->pushJson(200, $this->envelope($this->row()));
        $first->get(self::RECORD_ID);
        $this->assertStringContainsString('/zones/' . self::ZONE_ID . '/', $this->sentPath());
    }
}
