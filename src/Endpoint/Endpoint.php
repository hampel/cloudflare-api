<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Connection;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Result\ApiResponse;
use Hampel\Cloudflare\Api\Result\Page;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The base class for everything that groups a set of endpoints - this package's own, and
 * anybody else's.
 *
 * EXTENDING THE API. Cloudflare's API has some two thousand paths and this package wraps the
 * dozen that manage DNS and identify a token. The rest are not out of reach: an Endpoint
 * subclass is a first-class citizen, and Client::endpoint() will construct one.
 *
 *     final class Firewall extends Endpoint
 *     {
 *         public function rules(string $zoneId): \Generator
 *         {
 *             return $this->apiEach('zones/' . $zoneId . '/firewall/rules', static fn (array $row) => $row);
 *         }
 *     }
 *
 *     $cloudflare->endpoint(Firewall::class)->rules($zoneId);
 *
 * There is nothing to register, nothing to boot and no container. The class IS the
 * registration, so a third-party package ships one, a consumer type-hints it, and static
 * analysis follows the return type all the way through.
 *
 * What subclassing buys over calling Connection directly is the pagination below. Every
 * collection on this API answers in the same envelope, so apiPaginate() and apiEach() work
 * for an endpoint this package has never heard of - provided its page size limits are given,
 * because those are per-endpoint and there is no safe default.
 */
abstract class Endpoint
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The smallest page this endpoint accepts. Cloudflare's limits differ per endpoint - 1
     * for DNS records, 5 for zones and accounts - so each subclass states its own.
     */
    abstract protected function minimumPageSize(): int;

    abstract protected function maximumPageSize(): int;

    /**
     * What this endpoint lists, for the message when a page size is refused.
     */
    abstract protected function collectionName(): string;

    /**
     * Every helper here carries an `api` prefix, which looks redundant inside a class whose
     * whole job is the API and is not. An endpoint group wants to call its own methods get(),
     * create() and delete() - those are the natural names - and PHP will not let a subclass
     * redeclare an inherited method with a different signature. Prefixing the inherited ones
     * leaves the good names free, for this package's endpoints and for anybody else's.
     *
     * @param  array<string, scalar|null>  $query
     */
    protected function apiGet(string $path, array $query = []): ApiResponse
    {
        return $this->connection->get($path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPost(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->post($path, $payload, $query);
    }

    /**
     * A FULL REPLACEMENT - every field not in the payload is reset. See Connection::put().
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPut(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->put($path, $payload, $query);
    }

    /**
     * A partial update.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPatch(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->patch($path, $payload, $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    protected function apiDelete(string $path, array $query = []): ApiResponse
    {
        return $this->connection->delete($path, $query);
    }

    /**
     * A lookup where "no such thing" is an ordinary answer rather than a failure.
     *
     * Only a 404 becomes null. A 401 or a 403 is still raised, because a credential that
     * cannot see a zone and a zone that does not exist are different problems, and reporting
     * the first as the second sends whoever reads it looking in the wrong place.
     *
     * Note that Cloudflare itself blurs this at one point: a zone id that exists but is
     * outside the token's resources answers 404, not 403 - which is correct of it, since
     * confirming the zone exists would leak something, and is worth knowing when a lookup
     * comes back empty for a zone you are certain of.
     *
     * @template TItem
     * @param  array<string, scalar|null>  $query
     * @param  callable(array<string, mixed>): TItem  $map
     * @return TItem|null
     */
    protected function apiFind(string $path, callable $map, array $query = []): mixed
    {
        try {
            $response = $this->apiGet($path, $query);
        } catch (NotFoundException) {
            return null;
        }

        $object = $response->object();

        return $object === [] ? null : $map($object);
    }

    /**
     * One page of a collection.
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return Page<TItem>
     */
    protected function apiPaginate(
        string $path,
        callable $map,
        int $page = 1,
        ?int $pageSize = null,
        array $query = [],
    ): Page {
        $pageSize ??= $this->connection->config()->pageSize;

        if ($pageSize !== null) {
            Page::assertValidPageSize(
                $pageSize,
                $this->minimumPageSize(),
                $this->maximumPageSize(),
                $this->collectionName()
            );

            $query['per_page'] = $pageSize;
        }

        $query['page'] = max(1, $page);

        return Page::fromResponse($this->apiGet($path, $query), $map);
    }

    /**
     * Every item across every page, fetched a page at a time and only as far as it is
     * consumed - so stopping early stops making requests.
     *
     * Terminating on hasMore() rather than on an empty page saves one request per walk. A
     * page past the end IS an empty page here, not the error some APIs answer with, so the
     * looser loop would also work; it would just spend a request on every walk to learn
     * something the previous response already said. On an API with a five-minute request
     * budget that is worth not spending.
     *
     * A WALK IS A SAMPLE, NOT A SNAPSHOT. Each page is its own request against a collection
     * that can change between them, so a record created while a walk is in progress may
     * appear twice or not at all. Ordering explicitly - RecordQuery::orderBy() - narrows
     * that and does not remove it. Where completeness matters, de-duplicate by id.
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return \Generator<int, TItem>
     */
    protected function apiEach(
        string $path,
        callable $map,
        ?int $pageSize = null,
        array $query = [],
    ): \Generator {
        $page = 1;

        while (true) {
            $result = $this->apiPaginate($path, $map, $page, $pageSize, $query);

            yield from $result->items;

            if (!$result->hasMore() || $result->isEmpty()) {
                return;
            }

            $page++;
        }
    }
}
