<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Enum\CaaTag;
use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Exception\ConflictException;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Support\RecordQuery;

final class DnsRecordsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::RECORD_ID,
            'zone_id' => self::ZONE_ID,
            'zone_name' => 'example.com',
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '203.0.113.10',
            'proxiable' => true,
            'proxied' => false,
            'ttl' => 3600,
            'settings' => [],
            'meta' => [],
            'comment' => null,
            'tags' => [],
            'created_on' => '2014-01-01T05:20:00.12345Z',
            'modified_on' => '2014-01-01T05:20:00.12345Z',
        ];
    }

    private function path(?string $recordId = null): string
    {
        $path = '/client/v4/zones/' . self::ZONE_ID . '/dns_records';

        return $recordId === null ? $path : $path . '/' . $recordId;
    }

    public function test_it_lists_a_zones_records(): void
    {
        $this->client->pushJson(200, $this->collection(
            [$this->row(), $this->row(['id' => 'b', 'name' => 'mail.example.com'])],
            totalCount: 2
        ));

        $page = $this->cloudflare()->records()->list(self::ZONE_ID);

        $this->assertCount(2, $page);
        $this->assertSame('www.example.com', $page->items[0]->name);
        $this->assertSame(RecordType::A, $page->items[0]->type);
        $this->assertSame('203.0.113.10', $page->items[0]->content);
        $this->assertSame($this->path(), $this->sentPath());
    }

    public function test_the_bound_endpoint_does_not_repeat_the_zone_id(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $records = $this->cloudflare()->zones()->records(self::ZONE_ID);

        $this->assertSame(self::ZONE_ID, $records->zoneId);
        $this->assertCount(1, $records->all());
        $this->assertSame($this->path(), $this->sentPath());
    }

    /**
     * DNS records accept a page size that zones would refuse, which is the whole reason the
     * bounds live on the endpoint rather than in one constant.
     */
    public function test_a_page_size_of_one_is_accepted_here_though_zones_refuses_it(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));
        $this->cloudflare()->records()->list(self::ZONE_ID, pageSize: 1);

        $this->assertSame('1', $this->sentParameters()['per_page']);
    }

    public function test_a_page_size_above_this_endpoints_ceiling_is_refused(): void
    {
        try {
            $this->cloudflare()->records()->list(self::ZONE_ID, pageSize: 5000001);
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('DNS records', $e->getMessage());
            $this->assertSame([], $this->client->requests);
        }
    }

    public function test_a_query_is_sent_as_the_dotted_parameters_cloudflare_expects(): void
    {
        $this->client->pushJson(200, $this->collection([]));

        $this->cloudflare()->records()->list(self::ZONE_ID, query: RecordQuery::make()
            ->type(RecordType::TXT)
            ->nameEndsWith('.example.com')
            ->proxied(false)
            ->orderBy('name', 'desc'));

        $parameters = $this->sentParameters();

        $this->assertSame('TXT', $parameters['type']);
        $this->assertSame('.example.com', $parameters['name.endswith']);
        $this->assertSame('name', $parameters['order']);
        $this->assertSame('desc', $parameters['direction']);
        $this->assertSame(
            'false',
            $parameters['proxied'],
            'a boolean must be written true/false, not 1/0 - an unreadable filter is ignored, '
                . 'and an ignored filter returns the whole collection with a 200'
        );
    }

    public function test_named_looks_up_by_exact_name(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()]));

        $this->cloudflare()->records()->named(self::ZONE_ID, 'www.example.com');

        $this->assertSame('www.example.com', $this->sentParameters()['name.exact']);
    }

    public function test_of_type_filters_by_type(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row(['type' => 'MX', 'priority' => 10])]));

        $records = $this->cloudflare()->records()->ofType(self::ZONE_ID, RecordType::MX);

        $this->assertSame('MX', $this->sentParameters()['type']);
        $this->assertSame(10, $records[0]->priority);
    }

    public function test_it_creates_a_record(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $record = $this->cloudflare()->records()->create(
            self::ZONE_ID,
            DnsRecord::a('www.example.com', '203.0.113.10')->withTtl(3600)
        );

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame($this->path(), $this->sentPath());
        $this->assertSame(
            ['type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10', 'ttl' => 3600],
            $this->sentBody()
        );
        $this->assertSame(self::RECORD_ID, $record->id);
    }

    public function test_a_conflicting_record_raises_a_type_a_caller_can_act_on(): void
    {
        $this->client->pushJson(409, $this->failure([
            ['code' => 81058, 'message' => 'An A, AAAA, or CNAME record with that host already exists.'],
        ]));

        try {
            $this->cloudflare()->records()->create(self::ZONE_ID, DnsRecord::cname('www.example.com', 'other.example'));
            $this->fail('did not raise');
        } catch (ConflictException $e) {
            $this->assertTrue($e->hasCode(81058));
        }
    }

    public function test_patch_sends_only_what_it_was_given(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row(['ttl' => 300])));

        $record = $this->cloudflare()->records()->patch(self::ZONE_ID, self::RECORD_ID, ['ttl' => 300]);

        $this->assertSame('PATCH', $this->sentMethod());
        $this->assertSame($this->path(self::RECORD_ID), $this->sentPath());
        $this->assertSame(['ttl' => 300], $this->sentBody());
        $this->assertSame(300, $record->ttl);
    }

    /**
     * The two verbs are not interchangeable on this API and the difference is destructive:
     * a PUT resets every field it does not carry.
     */
    public function test_replace_is_a_put_and_is_logged_as_the_destructive_operation_it_is(): void
    {
        $logger = new RecordingLogger();
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->cloudflare(logger: $logger)->records()->replace(
            self::ZONE_ID,
            self::RECORD_ID,
            DnsRecord::a('www.example.com', '203.0.113.11')
        );

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertNotNull(
            $logger->contextFor('Cloudflare DNS record replace (PUT - resets omitted fields)')
        );
    }

    public function test_delete_returns_the_id_cloudflare_confirms(): void
    {
        $this->client->pushJson(200, $this->envelope(['id' => self::RECORD_ID]));

        $id = $this->cloudflare()->records()->delete(self::ZONE_ID, self::RECORD_ID);

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame(self::RECORD_ID, $id);
    }

    public function test_deleting_something_that_is_not_there_raises_rather_than_passing_quietly(): void
    {
        $this->client->pushJson(404, $this->failure([['code' => 81044, 'message' => 'Record does not exist.']]));

        $this->expectException(NotFoundException::class);

        $this->cloudflare()->records()->delete(self::ZONE_ID, self::RECORD_ID);
    }

    /**
     * A record and a zone disagree about which status means absence, and both were measured on
     * 2026-09-12: a missing record is `404 / 81044`, a missing zone is `403 / 9109`.
     */
    public function test_find_returns_null_for_a_record_that_is_not_there(): void
    {
        $this->client->pushJson(404, $this->failure([['code' => 81044, 'message' => 'Record does not exist.']]));

        $this->assertNull($this->cloudflare()->records()->find(self::ZONE_ID, self::RECORD_ID));
    }

    /**
     * A malformed id never matches a route, so it is a 400 rather than absence - and is not
     * absorbed, a malformed id being a bug rather than a thing that is missing.
     */
    public function test_a_malformed_record_id_is_a_failure_rather_than_an_absence(): void
    {
        $this->client->pushJson(400, $this->failure([['code' => 7000, 'message' => 'No route for that URI']]));

        $this->expectException(\Hampel\Cloudflare\Api\Exception\ValidationException::class);

        $this->cloudflare()->records()->find(self::ZONE_ID, 'not-a-real-id');
    }

    /**
     * A CAA read back from the API carries a generated `content`. Echoing it into a write
     * would be refused, so toArray() must send the components instead.
     */
    public function test_a_data_record_round_trips_through_data_and_never_through_content(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row([
            'type' => 'CAA',
            'name' => 'example.com',
            'content' => '0 issue "letsencrypt.org"',
            'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
        ])));

        $record = $this->cloudflare()->records()->get(self::ZONE_ID, self::RECORD_ID);

        $this->assertSame(RecordType::CAA, $record->type);
        $this->assertSame('0 issue "letsencrypt.org"', $record->content, 'readable');
        $this->assertSame('issue', $record->component('tag'));

        $this->client->pushJson(200, $this->envelope($this->row()));
        $this->cloudflare()->records()->create(self::ZONE_ID, $record);

        $body = $this->sentBody();

        $this->assertArrayNotHasKey('content', $body, 'the generated content must not be sent back');
        $this->assertSame(['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], $body['data']);
    }

    public function test_a_caa_record_is_built_from_components(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->cloudflare()->records()->create(
            self::ZONE_ID,
            DnsRecord::caa('example.com', CaaTag::Issue, 'letsencrypt.org')
        );

        $this->assertSame([
            'type' => 'CAA',
            'name' => 'example.com',
            'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
        ], $this->sentBody());
    }

    public function test_an_srv_record_carries_its_components_in_data(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->cloudflare()->records()->create(
            self::ZONE_ID,
            DnsRecord::srv('_sip._tcp.example.com', 'sip.example.com', port: 5060, priority: 10, weight: 5)
        );

        $body = $this->sentBody();

        $this->assertSame('_sip._tcp.example.com', $body['name']);
        $this->assertSame(
            ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.com'],
            $body['data']
        );
        $this->assertArrayNotHasKey('priority', $body, 'an SRV priority lives inside data, not at the top level');
    }

    public function test_an_mx_record_carries_its_priority_at_the_top_level(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $this->cloudflare()->records()->create(
            self::ZONE_ID,
            DnsRecord::mx('example.com', 'mail.example.com', priority: 10)
        );

        $this->assertSame([
            'type' => 'MX',
            'name' => 'example.com',
            'content' => 'mail.example.com',
            'priority' => 10,
        ], $this->sentBody());
    }

    public function test_export_returns_the_bind_zone_file(): void
    {
        $zoneFile = ";; Domain: example.com\nwww.example.com.\t300\tIN\tA\t203.0.113.10\n";
        $this->client->pushRaw(200, $zoneFile, ['Content-Type' => 'text/plain']);

        $exported = $this->cloudflare()->zones()->records(self::ZONE_ID)->export();

        $this->assertSame($zoneFile, $exported);
        $this->assertSame($this->path() . '/export', $this->sentPath());
    }

    public function test_an_empty_record_id_is_refused_before_a_request(): void
    {
        try {
            $this->cloudflare()->records()->get(self::ZONE_ID, '');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('DNS record id is required', $e->getMessage());
            $this->assertSame([], $this->client->requests);
        }
    }
}
