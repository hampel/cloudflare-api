<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Result\Page;
use Hampel\Cloudflare\Api\Support\RecordQuery;

/**
 * DnsRecords with the zone already supplied.
 *
 *     $records = $cloudflare->zones()->records($zoneId);
 *
 *     $records->all();
 *     $records->create(DnsRecord::a('www.example.com', '203.0.113.10'));
 *
 * Not an Endpoint subclass: it holds no connection and makes no requests of its own. It is a
 * partial application of the endpoint above, which is worth its file for one reason - a
 * sequence of calls against one zone should not repeat the id, and an id repeated by hand is
 * an id that can be wrong on the fourth line. On an API where the id is a 32-character hex
 * string rather than a name, that is not a hypothetical.
 */
final class BoundDnsRecords
{
    public function __construct(
        private readonly DnsRecords $records,
        public readonly string $zoneId,
    ) {
    }

    /**
     * The unbound endpoint, for anything not forwarded here.
     */
    public function endpoint(): DnsRecords
    {
        return $this->records;
    }

    /**
     * @return Page<DnsRecord>
     */
    public function list(int $page = 1, ?RecordQuery $query = null, ?int $pageSize = null): Page
    {
        return $this->records->list($this->zoneId, $page, $query, $pageSize);
    }

    /**
     * @return \Generator<int, DnsRecord>
     */
    public function each(?RecordQuery $query = null, ?int $pageSize = null): \Generator
    {
        return $this->records->each($this->zoneId, $query, $pageSize);
    }

    /**
     * @return list<DnsRecord>
     */
    public function all(?RecordQuery $query = null): array
    {
        return $this->records->all($this->zoneId, $query);
    }

    /**
     * @return list<DnsRecord>
     */
    public function ofType(RecordType $type): array
    {
        return $this->records->ofType($this->zoneId, $type);
    }

    /**
     * @return list<DnsRecord>
     */
    public function named(string $name): array
    {
        return $this->records->named($this->zoneId, $name);
    }

    public function get(string $recordId): DnsRecord
    {
        return $this->records->get($this->zoneId, $recordId);
    }

    public function find(string $recordId): ?DnsRecord
    {
        return $this->records->find($this->zoneId, $recordId);
    }

    /**
     * @param  DnsRecord|array<string, mixed>  $record
     */
    public function create(DnsRecord|array $record): DnsRecord
    {
        return $this->records->create($this->zoneId, $record);
    }

    /**
     * A partial update - see DnsRecords::patch().
     *
     * @param  DnsRecord|array<string, mixed>  $changes
     */
    public function patch(string $recordId, DnsRecord|array $changes): DnsRecord
    {
        return $this->records->patch($this->zoneId, $recordId, $changes);
    }

    /**
     * A full replacement, which resets every field not supplied - see DnsRecords::replace().
     *
     * @param  DnsRecord|array<string, mixed>  $record
     */
    public function replace(string $recordId, DnsRecord|array $record): DnsRecord
    {
        return $this->records->replace($this->zoneId, $recordId, $record);
    }

    public function delete(string $recordId): string
    {
        return $this->records->delete($this->zoneId, $recordId);
    }

    /**
     * The zone as a BIND zone file - see DnsRecords::export().
     */
    public function export(): string
    {
        return $this->records->export($this->zoneId);
    }
}
