<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Result\Page;
use Hampel\Cloudflare\Api\Support\RecordQuery;

/**
 * The DNS records inside a zone.
 *
 * https://developers.cloudflare.com/api/resources/dns/subresources/records/
 *
 * Every method takes the zone id first, because the API's paths do. Where several calls
 * concern one zone, `$cloudflare->zones()->records($zoneId)` binds it once and drops the
 * argument.
 *
 * Reading needs `DNS:Read` on the token; everything that changes something needs `DNS:Edit`.
 *
 * THE TWO UPDATE VERBS DO DIFFERENT THINGS AND ONE OF THEM IS DESTRUCTIVE. patch() changes
 * the fields you give it. replace() overwrites the record entirely - anything you leave out
 * is reset to its default, which means a replace() carrying only new content silently clears
 * the comment and tags, un-proxies the record and returns its TTL to automatic. Both answer
 * 200. patch() is what almost every caller wants and is why it has the shorter name.
 *
 * THE ZONE'S SOA AND ITS CLOUDFLARE NAMESERVERS ARE NOT HERE. Cloudflare generates and serves
 * those without representing them as records, so a zone that resolves perfectly well can
 * answer this endpoint with a handful of records and no NS among them. export() is what shows
 * the whole picture.
 */
final class DnsRecords extends Endpoint
{
    public const MIN_PAGE_SIZE = 1;

    /**
     * Cloudflare's documented ceiling for this endpoint, and not a typo. It is effectively
     * "no limit"; a request that actually asked for it would time out long before the page
     * arrived.
     */
    public const MAX_PAGE_SIZE = 5000000;

    /**
     * The API's own default when a request does not ask for a size.
     */
    public const DEFAULT_PAGE_SIZE = 100;

    /**
     * One page of a zone's records.
     *
     * @return Page<DnsRecord>
     */
    public function list(string $zoneId, int $page = 1, ?RecordQuery $query = null, ?int $pageSize = null): Page
    {
        return $this->apiPaginate(
            $this->path($zoneId),
            DnsRecord::fromArray(...),
            $page,
            $pageSize,
            $query?->toQuery() ?? []
        );
    }

    /**
     * Every matching record in a zone, a page at a time, fetched only as far as it is
     * consumed.
     *
     * @return \Generator<int, DnsRecord>
     */
    public function each(string $zoneId, ?RecordQuery $query = null, ?int $pageSize = null): \Generator
    {
        return $this->apiEach(
            $this->path($zoneId),
            DnsRecord::fromArray(...),
            $pageSize,
            $query?->toQuery() ?? []
        );
    }

    /**
     * Every matching record in a zone, as a list.
     *
     * @return list<DnsRecord>
     */
    public function all(string $zoneId, ?RecordQuery $query = null): array
    {
        return iterator_to_array($this->each($zoneId, $query), false);
    }

    /**
     * Every record of one type.
     *
     * @return list<DnsRecord>
     */
    public function ofType(string $zoneId, RecordType $type): array
    {
        return $this->all($zoneId, RecordQuery::ofType($type));
    }

    /**
     * Every record with one name, of any type - which is how you find out what `www` already
     * is before adding to it.
     *
     * The name is the full one: `www.example.com`, not `www`. Zone::fqdn() builds it.
     *
     * @return list<DnsRecord>
     */
    public function named(string $zoneId, string $name): array
    {
        return $this->all($zoneId, RecordQuery::name($name));
    }

    /**
     * One record. Raises NotFoundException when it is not there.
     */
    public function get(string $zoneId, string $recordId): DnsRecord
    {
        return DnsRecord::fromArray($this->apiGet($this->path($zoneId, $recordId))->object());
    }

    /**
     * One record, or null.
     */
    public function find(string $zoneId, string $recordId): ?DnsRecord
    {
        return $this->apiFind($this->path($zoneId, $recordId), DnsRecord::fromArray(...));
    }

    /**
     * Add a record.
     *
     *     $cloudflare->records()->create($zoneId, DnsRecord::a('www.example.com', '203.0.113.10'));
     *
     * NOTHING STOPS A DUPLICATE where DNS itself permits one. Two identical A records for the
     * same name are legal and Cloudflare will hold both; the 409 you get instead is for a
     * name whose types cannot coexist - a CNAME beside anything else. Check with named()
     * first where it matters.
     *
     * @param  DnsRecord|array<string, mixed>  $record
     */
    public function create(string $zoneId, DnsRecord|array $record): DnsRecord
    {
        $payload = $record instanceof DnsRecord ? $record->toArray() : $record;

        $this->logger->info('Cloudflare DNS record create', [
            'zone_id' => $zoneId,
            'type' => $payload['type'] ?? null,
            'name' => $payload['name'] ?? null,
        ]);

        return DnsRecord::fromArray($this->apiPost($this->path($zoneId), $payload)->object());
    }

    /**
     * Change some of a record, leaving the rest alone. An HTTP PATCH.
     *
     * The update to reach for. Pass an array to change one field without describing the whole
     * record:
     *
     *     $cloudflare->records()->patch($zoneId, $recordId, ['ttl' => 300]);
     *
     * Passing a DnsRecord sends every field that is set on it, which for a record read back
     * from the API is all of them - the same values it already had, but worth knowing.
     *
     * @param  DnsRecord|array<string, mixed>  $changes
     */
    public function patch(string $zoneId, string $recordId, DnsRecord|array $changes): DnsRecord
    {
        $payload = $changes instanceof DnsRecord ? $changes->toPatchArray() : $changes;

        $this->logger->info('Cloudflare DNS record patch', [
            'zone_id' => $zoneId,
            'record_id' => $recordId,
            'fields' => array_keys($payload),
        ]);

        return DnsRecord::fromArray($this->apiPatch($this->path($zoneId, $recordId), $payload)->object());
    }

    /**
     * Replace a record entirely. An HTTP PUT.
     *
     * EVERY FIELD NOT IN THE PAYLOAD IS RESET TO ITS DEFAULT - the comment cleared, the tags
     * dropped, the proxy turned off, the TTL returned to automatic. The call answers 200 and
     * says nothing about the fields it reset, so this is the one operation in the package
     * that can quietly undo somebody else's work.
     *
     * It is here because replacing a record wholesale is sometimes exactly right - restoring
     * a zone from a known-good description, for one, where anything not in the description is
     * meant to go. When the intention is "change this one thing", patch() is the call.
     *
     * @param  DnsRecord|array<string, mixed>  $record
     */
    public function replace(string $zoneId, string $recordId, DnsRecord|array $record): DnsRecord
    {
        $payload = $record instanceof DnsRecord ? $record->toArray() : $record;

        $this->logger->warning('Cloudflare DNS record replace (PUT - resets omitted fields)', [
            'zone_id' => $zoneId,
            'record_id' => $recordId,
            'fields' => array_keys($payload),
        ]);

        return DnsRecord::fromArray($this->apiPut($this->path($zoneId, $recordId), $payload)->object());
    }

    /**
     * Remove a record.
     *
     * Immediate, and subject only to whatever TTL resolvers are still holding - which is the
     * argument for lowering a TTL well before a change rather than at the moment of it.
     *
     * Returns the id Cloudflare confirms it deleted, which is what its `result` carries. A
     * record that was not there raises NotFoundException rather than passing quietly, because
     * "delete something that does not exist" is far more often a wrong id than an idempotent
     * retry.
     */
    public function delete(string $zoneId, string $recordId): string
    {
        $this->logger->warning('Cloudflare DNS record delete', [
            'zone_id' => $zoneId,
            'record_id' => $recordId,
        ]);

        $response = $this->apiDelete($this->path($zoneId, $recordId));

        return is_string($id = $response->value('id')) ? $id : $recordId;
    }

    /**
     * The zone as it is actually served, as a BIND zone file.
     *
     * The authoritative answer to "what did that change really do", and the thing to diff
     * before and after a bulk edit. It is generated, so it carries the SOA and the Cloudflare
     * nameservers that no record in the API describes.
     *
     * ONE ENDPOINT ON THIS API WHOSE SUCCESS IS NOT JSON - it answers `text/plain`. A failure
     * still comes back in the usual envelope and is raised as usual.
     */
    public function export(string $zoneId): string
    {
        [$body] = $this->connection->raw($this->path($zoneId) . '/export');

        return $body;
    }

    protected function minimumPageSize(): int
    {
        return self::MIN_PAGE_SIZE;
    }

    protected function maximumPageSize(): int
    {
        return self::MAX_PAGE_SIZE;
    }

    protected function collectionName(): string
    {
        return 'DNS records';
    }

    private function path(string $zoneId, ?string $recordId = null): string
    {
        $path = 'zones/' . Zones::identifier($zoneId, 'zone') . '/dns_records';

        return $recordId === null ? $path : $path . '/' . Zones::identifier($recordId, 'DNS record');
    }
}
