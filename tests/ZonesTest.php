<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Config;
use Hampel\Cloudflare\Api\Entity\Zone;
use Hampel\Cloudflare\Api\Enum\ZoneStatus;
use Hampel\Cloudflare\Api\Enum\ZoneType;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Exception\NotAuthenticatedException;
use Hampel\Cloudflare\Api\Endpoint\Zones;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;

final class ZonesTest extends TestCase
{
    /**
     * A zone row in the shape the specification describes.
     *
     * @return array<string, mixed>
     */
    private function row(string $name = 'example.com', string $id = self::ZONE_ID): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status' => 'active',
            'paused' => false,
            'type' => 'full',
            'development_mode' => 0,
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
            'original_name_servers' => ['ns1.example-registrar.com'],
            'original_registrar' => 'Example Registrar, LLC',
            'account' => ['id' => '01a7362d577a6c3019a474fd6f485823', 'name' => 'Example Account'],
            'plan' => ['id' => '0feee', 'name' => 'Free Website'],
            'created_on' => '2014-01-01T05:20:00.12345Z',
            'modified_on' => '2014-01-01T05:20:00.12345Z',
            'activated_on' => '2014-01-02T00:01:00.12345Z',
        ];
    }

    public function test_it_lists_a_page_of_zones(): void
    {
        $this->client->pushJson(200, $this->collection(
            [$this->row(), $this->row('example.net', 'b' . substr(self::ZONE_ID, 1))],
            page: 1,
            totalPages: 3,
            totalCount: 47,
            perPage: 20
        ));

        $page = $this->cloudflare()->zones()->list();

        $this->assertCount(2, $page);
        $this->assertSame(47, $page->total(), 'total_count is everything, not this page');
        $this->assertSame(3, $page->lastPage());
        $this->assertTrue($page->hasMore());
        $this->assertSame('example.com', $page->items[0]->name);
        $this->assertSame(ZoneStatus::Active, $page->items[0]->status);
        $this->assertSame(ZoneType::Full, $page->items[0]->type);
        $this->assertSame('Example Account', $page->items[0]->accountName);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $page->items[0]->nameServers);
        $this->assertSame('/client/v4/zones', $this->sentPath());
    }

    /**
     * Page implements IteratorAggregate, so `foreach ($page as $zone)` is part of the public
     * API and is what a caller writes in preference to reaching for `->items`. Nothing else
     * here runs getIterator().
     */
    public function test_a_page_can_be_iterated_and_counted_directly(): void
    {
        $this->client->pushJson(200, $this->collection(
            [$this->row('a.example'), $this->row('b.example')],
            totalCount: 2
        ));

        $page = $this->cloudflare()->zones()->list();
        $names = [];

        foreach ($page as $zone) {
            $names[] = $zone->name;
        }

        $this->assertSame(['a.example', 'b.example'], $names);
        $this->assertCount(2, $page, 'Countable counts this page, not the collection');
        $this->assertSame(1, $page->currentPage());
    }

    public function test_each_walks_the_pages_and_stops_at_the_last_one(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row('a.example')], page: 1, totalPages: 2, totalCount: 2));
        $this->client->pushJson(200, $this->collection([$this->row('b.example')], page: 2, totalPages: 2, totalCount: 2));

        $zones = iterator_to_array($this->cloudflare()->zones()->each(), false);

        $this->assertSame(['a.example', 'b.example'], array_map(fn (Zone $z): string => $z->name, $zones));
        $this->assertCount(2, $this->client->requests, 'no wasted request past the last page');
        $this->assertSame('2', $this->sentParameters()['page']);
    }

    public function test_a_walk_stopped_early_stops_making_requests(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row('a.example')], page: 1, totalPages: 9, totalCount: 9));

        foreach ($this->cloudflare()->zones()->each() as $zone) {
            $this->assertSame('a.example', $zone->name);
            break;
        }

        $this->assertCount(1, $this->client->requests);
    }

    /**
     * The limits differ per endpoint on this API, and zones is the narrow one. A page size
     * that is perfectly good for DNS records is refused here.
     */
    public function test_a_page_size_this_endpoint_would_refuse_never_leaves_the_process(): void
    {
        foreach ([1, 4, 51, 100] as $size) {
            try {
                $this->cloudflare()->zones()->list(pageSize: $size);
                $this->fail('page size ' . $size . ' was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('between 5 and 50 for zones', $e->getMessage());
            }
        }

        $this->assertSame([], $this->client->requests);
    }

    public function test_a_valid_page_size_is_sent_as_per_page(): void
    {
        $this->client->pushJson(200, $this->collection([]));
        $this->cloudflare()->zones()->list(pageSize: 50);

        $this->assertSame(['per_page' => '50', 'page' => '1'], $this->sentParameters());
    }

    public function test_a_default_page_size_from_the_config_applies(): void
    {
        $this->client->pushJson(200, $this->collection([]));
        $this->cloudflare(new Config(pageSize: 25))->zones()->list();

        $this->assertSame('25', $this->sentParameters()['per_page']);
    }

    public function test_one_zone_by_id(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $zone = $this->cloudflare()->zones()->get(self::ZONE_ID);

        $this->assertSame('example.com', $zone->name);
        $this->assertSame('/client/v4/zones/' . self::ZONE_ID, $this->sentPath());
        $this->assertTrue($zone->isActive());
        $this->assertFalse($zone->isPaused());
        $this->assertSame('2014-01-01', $zone->createdOn?->format('Y-m-d'));
    }

    /**
     * Measured on 2026-09-12: an unknown zone id is a 403 carrying code 9109, not a
     * 404. find() has to absorb that or it raises for the commonest case it exists to handle.
     */
    public function test_find_returns_null_for_the_403_cloudflare_uses_to_mean_no_such_zone(): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => Zones::CODE_INVALID_ZONE_IDENTIFIER, 'message' => 'Invalid zone identifier'],
        ]));

        $this->assertNull($this->cloudflare()->zones()->find(self::ZONE_ID));
    }

    /**
     * The other 403 - code 10000, a token whose permissions or resources do not cover this -
     * must still raise. Absorbed, a misconfigured credential would read as "the zone does not
     * exist" and send whoever chased it to the wrong dashboard.
     */
    public function test_find_still_raises_for_a_403_that_is_a_permissions_problem(): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => 10000, 'message' => 'Authentication error'],
        ]));

        try {
            $this->cloudflare()->zones()->find(self::ZONE_ID);
            $this->fail('a permissions failure was absorbed as absence');
        } catch (NotPermittedException $e) {
            $this->assertTrue($e->hasCode(10000));
        }
    }

    /**
     * The 1.0.0 defect. From an address outside the token's IP filter, find() answered "no such
     * zone" for a zone that exists, because the refusal carries the same code as an unknown zone
     * id. Reproduced live on 2026-09-13 before this was changed.
     */
    public function test_find_does_not_report_a_location_refused_token_as_a_missing_zone(): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => 9109, 'message' => 'Cannot use the access token from location: 203.0.113.99'],
        ]));

        $this->expectException(NotAuthenticatedException::class);

        $this->cloudflare()->zones()->find(self::ZONE_ID);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unrecognisedMessagesForTheZoneCode(): array
    {
        return [
            'reworded' => ['Zone identifier is not valid'],
            'empty' => [''],
            'a location refusal Cloudflare has reworded' => ['Access denied from this network'],
        ];
    }

    /**
     * The direction find() fails in when the message is not the one it recognises: it raises.
     * A null it cannot justify is the failure this method exists to avoid, so an unfamiliar
     * message on code 9109 is reported rather than read as absence.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unrecognisedMessagesForTheZoneCode')]
    public function test_find_raises_rather_than_guessing_on_an_unrecognised_9109(string $message): void
    {
        $this->client->pushJson(403, $this->failure([['code' => 9109, 'message' => $message]]));

        $this->expectException(NotPermittedException::class);

        $this->cloudflare()->zones()->find(self::ZONE_ID);
    }

    public function test_find_by_name_raises_the_credential_type_for_a_location_refusal(): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => 9109, 'message' => 'Cannot use the access token from location: 203.0.113.99'],
        ]));

        $this->expectException(NotAuthenticatedException::class);

        $this->cloudflare()->zones()->findByName('example.com');
    }

    public function test_find_also_absorbs_a_404_for_the_endpoints_that_answer_with_one(): void
    {
        $this->client->pushJson(404, $this->failure([['code' => 7003, 'message' => 'Could not route']]));

        $this->assertNull($this->cloudflare()->zones()->find(self::ZONE_ID));
    }

    public function test_get_raises_for_a_zone_that_is_not_there(): void
    {
        $this->client->pushJson(403, $this->failure([
            ['code' => Zones::CODE_INVALID_ZONE_IDENTIFIER, 'message' => 'Invalid zone identifier'],
        ]));

        $this->expectException(NotPermittedException::class);

        $this->cloudflare()->zones()->get(self::ZONE_ID);
    }

    public function test_find_by_name_filters_on_the_name_and_lower_cases_it(): void
    {
        $this->client->pushJson(200, $this->collection([$this->row()], perPage: 5));

        $zone = $this->cloudflare()->zones()->findByName('Example.COM.');

        $this->assertNotNull($zone);
        $this->assertSame(self::ZONE_ID, $zone->id);
        $this->assertSame('example.com', $this->sentParameters()['name']);
    }

    /**
     * The whole correctness of findByName() rests on the server honouring `?name=`. If it
     * ever stopped, the failure would not be an error - it would be a 200 carrying the first
     * zone on the account, returned as "the zone called example.com", and everything
     * downstream would then edit the wrong domain's DNS.
     */
    public function test_find_by_name_refuses_an_answer_that_is_not_the_zone_it_asked_for(): void
    {
        $logger = new RecordingLogger();
        $this->client->pushJson(200, $this->collection([$this->row('somebody-else.com')], perPage: 5));

        $zone = $this->cloudflare(logger: $logger)->zones()->findByName('example.com');

        $this->assertNull($zone, 'a filter the server ignored must not come back as a match');
        $this->assertNotNull($logger->contextFor('Cloudflare answered a filtered zone lookup with something else'));
    }

    public function test_find_by_name_refuses_an_empty_name_before_making_a_request(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->cloudflare()->zones()->findByName('  .  ');
        } finally {
            $this->assertSame([], $this->client->requests);
        }
    }

    public function test_get_by_name_raises_with_both_reasons_when_nothing_matches(): void
    {
        $this->client->pushJson(200, $this->collection([], perPage: 5));

        try {
            $this->cloudflare()->zones()->getByName('example.com');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('example.com', $e->getMessage());
            $this->assertStringContainsString('zone resources', $e->getMessage());
        }
    }

    /**
     * An empty id builds the collection path, which answers 200 with the first page of every
     * zone - read as one zone, that is whichever zone sorted first.
     */
    public function test_an_empty_zone_id_is_refused_rather_than_addressing_the_collection(): void
    {
        try {
            $this->cloudflare()->zones()->get('  ');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('addresses the collection instead', $e->getMessage());
            $this->assertSame([], $this->client->requests);
        }
    }

    public function test_a_status_filter_is_sent(): void
    {
        $this->client->pushJson(200, $this->collection([]));
        $this->cloudflare()->zones()->list(status: ZoneStatus::Pending);

        $this->assertSame('pending', $this->sentParameters()['status']);
    }

    public function test_a_pending_zone_is_not_active(): void
    {
        $row = $this->row();
        $row['status'] = 'pending';
        $this->client->pushJson(200, $this->envelope($row));

        $zone = $this->cloudflare()->zones()->get(self::ZONE_ID);

        $this->assertFalse($zone->isActive());
        $this->assertTrue($zone->isPending());
    }

    public function test_fqdn_builds_a_full_record_name_from_a_label(): void
    {
        $zone = Zone::fromArray($this->row());

        $this->assertSame('www.example.com', $zone->fqdn('www'));
        $this->assertSame('example.com', $zone->fqdn(''), 'the apex is the zone name itself');
        $this->assertSame('example.com', $zone->fqdn('@'));
        $this->assertSame('www.example.com', $zone->fqdn('www.example.com'), 'already qualified, left alone');
        $this->assertSame('www.example.com', $zone->fqdn('WWW.'), 'case and trailing dot normalised');
        $this->assertSame('a.b.example.com', $zone->fqdn('a.b'));
    }

    public function test_an_unknown_status_does_not_break_the_entity(): void
    {
        $row = $this->row();
        $row['status'] = 'something-new';
        $this->client->pushJson(200, $this->envelope($row));

        $zone = $this->cloudflare()->zones()->get(self::ZONE_ID);

        $this->assertNull($zone->status, 'an unmapped value is null rather than an error');
        $this->assertSame('something-new', $zone->raw['status'], 'and is still reachable raw');
    }
}
