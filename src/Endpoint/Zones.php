<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Entity\Zone;
use Hampel\Cloudflare\Api\Enum\ZoneStatus;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;
use Hampel\Cloudflare\Api\Result\Page;
use Hampel\Cloudflare\Api\Support\Identifier;

/**
 * Zones - one per domain.
 *
 * https://developers.cloudflare.com/api/resources/zones/
 *
 * READ-ONLY, DELIBERATELY. Listing and fetching are here; creating, deleting and
 * reconfiguring a zone are not. Those are rare acts with large consequences, usually done
 * once in the dashboard, and their absence means no code path in a consuming application can
 * reach them by accident. Connection is the way out if one is genuinely needed.
 *
 * Needs `Zone:Read` on the token. A token scoped to one zone can still call these - it sees
 * a collection of one.
 *
 * THE ID IS WHAT EVERYTHING ELSE NEEDS, and the name is what you have. findByName() is the
 * bridge, and on an account of any size it is the first call a job makes.
 *
 * PAGE SIZES HERE ARE 5 TO 50, not the 1 to 5,000,000 the DNS record endpoint accepts. The
 * default is 20.
 */
final class Zones extends Endpoint
{
    public const MIN_PAGE_SIZE = 5;

    public const MAX_PAGE_SIZE = 50;

    /**
     * The API's own default when a request does not ask for a size.
     */
    public const DEFAULT_PAGE_SIZE = 20;

    /**
     * What Cloudflare answers, inside a 403, for a zone id this token cannot address -
     * whether because no such zone exists or because it belongs to somebody else.
     *
     * NOT UNIQUE TO ZONES. The same code arrives for a token refused by its IP address filter,
     * so on its own it does not mean the zone is absent. See find().
     */
    public const CODE_INVALID_ZONE_IDENTIFIER = 9109;

    /**
     * The message that accompanies CODE_INVALID_ZONE_IDENTIFIER when it means what its name
     * says. Measured on 2026-09-12.
     */
    private const MESSAGE_INVALID_ZONE_IDENTIFIER = 'invalid zone identifier';

    /**
     * One page of the account's zones.
     *
     * @return Page<Zone>
     */
    public function list(int $page = 1, ?int $pageSize = null, ?ZoneStatus $status = null): Page
    {
        return $this->apiPaginate('zones', Zone::fromArray(...), $page, $pageSize, self::filter($status));
    }

    /**
     * Every zone, a page at a time, fetched only as far as it is consumed.
     *
     * @return \Generator<int, Zone>
     */
    public function each(?int $pageSize = null, ?ZoneStatus $status = null): \Generator
    {
        return $this->apiEach('zones', Zone::fromArray(...), $pageSize, self::filter($status));
    }

    /**
     * Every zone, as a list.
     *
     * Fine for an account with tens of zones and a bad idea for one with thousands - it holds
     * them all in memory and makes every request before returning any of them. Use each()
     * where the count is unknown.
     *
     * @return list<Zone>
     */
    public function all(?ZoneStatus $status = null): array
    {
        return iterator_to_array($this->each(status: $status), false);
    }

    /**
     * One zone by id.
     *
     * RAISES NotPermittedException FOR A ZONE THAT IS NOT THERE, not NotFoundException.
     * Measured against the live API on 2026-09-12: an unknown zone id answers
     * `403 {"code": 9109, "message": "Invalid zone identifier"}`. That is deliberate of
     * Cloudflare - answering 404 would confirm to a credential which zone ids exist - and it
     * means "no such zone" and "not your zone" are genuinely the same reply. find() is the
     * method that treats it as an ordinary answer.
     */
    public function get(string $zoneId): Zone
    {
        return Zone::fromArray($this->apiGet($this->path($zoneId))->object());
    }

    /**
     * One zone by id, or null when this token cannot address it.
     *
     * The 403 described on get() is absorbed here, but ONLY when it carries code 9109 AND the
     * message "Invalid zone identifier". A 403 for any other reason still raises, and the
     * distinction earns its keep: a token whose permissions are wrong reports
     * `10000, Authentication error` - measured on 2026-09-12, by listing the records of a zone
     * outside the token's resources - and silently returning null for that would turn a
     * misconfigured credential into "the zone does not exist", which sends whoever reads it to
     * the wrong dashboard.
     *
     * THE CODE ALONE WAS NOT ENOUGH, and 1.0.0 got this wrong. Cloudflare sends 9109 for a
     * token refused by its IP address filter too, with "Cannot use the access token from
     * location". Absorbing on the code made `find()` answer "no such zone" for a zone that
     * exists, from any address outside the filter - the exact outcome this catch was written
     * to prevent. That case now raises NotAuthenticatedException before it gets here.
     *
     * The message is matched positively, on the zone wording, because that fails in the safe
     * direction: if Cloudflare rewords it, this raises rather than returning a null it cannot
     * justify.
     *
     * The 404 branch is kept because it costs nothing and the API's shape here is not a
     * promise: a record id that does not exist DOES answer 404, so the two id types in this
     * package already disagree about which status means absence.
     */
    public function find(string $zoneId): ?Zone
    {
        try {
            return $this->get($zoneId);
        } catch (NotFoundException) {
            return null;
        } catch (NotPermittedException $e) {
            if (!self::isInvalidZoneIdentifier($e)) {
                throw $e;
            }

            return null;
        }
    }

    private static function isInvalidZoneIdentifier(NotPermittedException $e): bool
    {
        foreach ($e->errors as $error) {
            $message = strtolower(rtrim(trim($error->message), '.'));

            if (
                $error->code === self::CODE_INVALID_ZONE_IDENTIFIER
                && $message === self::MESSAGE_INVALID_ZONE_IDENTIFIER
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * One zone by DOMAIN NAME, or null.
     *
     * The call that turns the thing you have into the thing every other endpoint wants. The
     * name is unique across the whole of Cloudflare, so at most one zone comes back - but it
     * comes back inside a collection envelope, because the endpoint is the list one.
     *
     * The name is lower-cased here: DNS is case-insensitive and a zone is stored lower-case,
     * so `Example.COM` would otherwise find nothing while looking like it should.
     *
     * THE NAME IS CHECKED AGAIN ON THE WAY BACK, which looks like belt and braces and is not.
     * This method's whole correctness rests on the server honouring `?name=`, and the failure
     * mode if it ever stopped is not an error - it is a 200 carrying the first zone on the
     * account, which this would return as "the zone called example.com". Everything
     * downstream then edits the wrong domain's DNS. A filter being ignored is a silent
     * success, so it is caught by confirming the answer rather than by trusting the request.
     */
    public function findByName(string $domain): ?Zone
    {
        $domain = strtolower(trim($domain, ". \t\n\r\0\x0B"));

        if ($domain === '') {
            throw new InvalidArgumentException('A domain name is required to look a zone up.');
        }

        $page = $this->apiPaginate(
            'zones',
            Zone::fromArray(...),
            1,
            self::MIN_PAGE_SIZE,
            ['name' => $domain]
        );

        foreach ($page->items as $candidate) {
            if (strtolower(trim($candidate->name, '. ')) === $domain) {
                return $candidate;
            }
        }

        if (!$page->isEmpty()) {
            $this->logger->warning('Cloudflare answered a filtered zone lookup with something else', [
                'asked_for' => $domain,
                'received' => array_map(static fn (Zone $item): string => $item->name, $page->items),
                'results' => $page->total(),
            ]);
        }

        return null;
    }

    /**
     * The same lookup, raising rather than returning null.
     *
     * For a job whose whole purpose concerns one domain, where a missing zone is a
     * misconfiguration to stop on rather than a case to handle.
     */
    public function getByName(string $domain): Zone
    {
        $zone = $this->findByName($domain);

        if ($zone === null) {
            throw new InvalidArgumentException(sprintf(
                'No zone named "%s" is visible to this token. Either Cloudflare does not hold '
                    . 'that domain, or the token\'s zone resources do not include it - which '
                    . 'look identical from here.',
                $domain
            ));
        }

        return $zone;
    }

    /**
     * Records within a zone, as a bound endpoint: every call knows its zone.
     *
     *     $cloudflare->zones()->records($zoneId)->all();
     *
     * The same operations are on `$cloudflare->records()` with the zone id as the first
     * argument. This is the one to reach for when several calls concern one zone.
     */
    public function records(string $zoneId): BoundDnsRecords
    {
        return new BoundDnsRecords(new DnsRecords($this->connection, $this->logger), $zoneId);
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
        return 'zones';
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function filter(?ZoneStatus $status): array
    {
        return $status === null ? [] : ['status' => $status->value];
    }

    private function path(string $zoneId): string
    {
        return 'zones/' . Identifier::for($zoneId, 'zone');
    }

}
