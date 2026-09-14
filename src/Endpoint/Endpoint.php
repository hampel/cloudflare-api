<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Connection;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Exception\RuntimeException;
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
 *     final class CustomHostnames extends Endpoint
 *     {
 *         public function each(string $zoneId): \Generator
 *         {
 *             return $this->apiEach('zones/' . $zoneId . '/custom_hostnames', static fn (array $row) => $row);
 *         }
 *
 *         protected function minimumPageSize(): int { return 5; }
 *         protected function maximumPageSize(): int { return 1000; }
 *         protected function collectionName(): string { return 'custom hostnames'; }
 *     }
 *
 *     $cloudflare->endpoint(CustomHostnames::class)->each($zoneId);
 *
 * The three protected methods are required - the page size limits differ per endpoint, so each
 * subclass states its own, and without them the class is abstract and cannot be constructed.
 *
 * There is nothing to register, nothing to boot and no container. The class IS the
 * registration, so a third-party package ships one, a consumer type-hints it, and static
 * analysis follows the return type all the way through.
 *
 * What subclassing buys over calling Connection directly is the pagination below. Every
 * collection on this API answers in the same envelope, so apiPaginate() and apiEach() work
 * for an endpoint this package has never heard of - provided its page size limits are given,
 * because those are per-endpoint and there is no safe default.
 *
 * NOT EVERY COLLECTION PAGES BY NUMBER. Some - the Registrar's registrations, rulesets, list
 * items - page by cursor, and their `result_info` carries a cursor and no `total_count`. Walk
 * those with apiEachByCursor(). apiEach() refuses one rather than returning its first page as
 * the whole collection.
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
        return Page::fromResponse($this->apiGet($path, $this->pageQuery($page, $pageSize, $query)), $map);
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
     * KEYS RUN 0 TO N-1 ACROSS THE WHOLE WALK, not per page. They used to restart at 0 on each
     * page, which foreach never notices and `iterator_to_array()` does: with its default of
     * preserving keys, every page overwrote the one before and only the last page survived.
     * Measured before 1.0.2 - four zones over two pages came back as two.
     *
     * A CURSOR-PAGED COLLECTION IS REFUSED, before anything is yielded. Its `result_info` carries
     * a non-empty `cursor` (or `cursors.after`) and no `total_count`, so the page numbers this
     * walk sends are ignored and it would stop after the first page with no error - returning,
     * measured against the Registrar, one registration of 22. An empty cursor means the first
     * page is the whole collection, and that is walked normally.
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
        $key = 0;

        while (true) {
            $response = $this->apiGet($path, $this->pageQuery($page, $pageSize, $query));

            // Before yielding anything, so a caller never processes a partial collection and then
            // meets the exception half way through.
            self::refuseCursorPaging($response, $path);

            $result = Page::fromResponse($response, $map);

            // Not `yield from $result->items`: that re-yields each page's own keys, 0 upwards, so
            // iterator_to_array() keeps only the last page. See the note above.
            foreach ($result->items as $item) {
                yield $key++ => $item;
            }

            if (!$result->hasMore() || $result->isEmpty()) {
                return;
            }

            $page++;
        }
    }

    /**
     * Every item in a cursor-paged collection, fetched a page at a time and only as far as it is
     * consumed.
     *
     * For the collections apiEach() refuses: `result_info` carries a cursor and no
     * `total_count`, and the next page is reached by sending that cursor back as `?cursor=`.
     * Two shapes exist on this API - a string `result_info.cursor`, measured on the Registrar,
     * and an object `result_info.cursors` whose `after` points onward, in the specification for
     * list items. Both are followed.
     *
     * AN EMPTY CURSOR IS THE LAST PAGE, and that is where the walk stops. Measured on the
     * Registrar: 22 registrations at `per_page=50` answered one page with `cursor` of "".
     *
     * A CURSOR SEEN TWICE IS AN ERROR, not the end. An API that hands back a cursor it has
     * already issued would loop forever, and stopping quietly at that point would return part
     * of the collection as all of it - the failure this method exists to prevent. So it
     * raises.
     *
     * Keys run 0 to n-1 across the whole walk, for the reason given on apiEach().
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return \Generator<int, TItem>
     */
    protected function apiEachByCursor(
        string $path,
        callable $map,
        ?int $pageSize = null,
        array $query = [],
    ): \Generator {
        $query = $this->sizeQuery($pageSize, $query);
        $seen = [];
        $key = 0;

        while (true) {
            $response = $this->apiGet($path, $query);
            $rows = $response->rows();

            foreach ($rows as $row) {
                yield $key++ => $map($row);
            }

            $next = self::nextCursor($response);

            if ($rows === [] || $next === null) {
                return;
            }

            if (isset($seen[$next])) {
                throw new RuntimeException(sprintf(
                    '%s returned a cursor it had already issued, so the walk would never end. '
                        . 'It stopped after %d items rather than report them as the whole '
                        . 'collection.',
                    $path,
                    $key
                ));
            }

            $seen[$next] = true;
            $query['cursor'] = $next;
        }
    }

    /**
     * The cursor that reaches the next page, or null when there is none - no cursor at all, or
     * an empty one, which is how this API marks the last page.
     */
    private static function nextCursor(ApiResponse $response): ?string
    {
        $info = $response->envelope['result_info'] ?? null;

        if (!is_array($info)) {
            return null;
        }

        $cursors = $info['cursors'] ?? null;
        $next = $info['cursor'] ?? (is_array($cursors) ? ($cursors['after'] ?? null) : null);

        return is_string($next) && $next !== '' ? $next : null;
    }

    /**
     * The query for one page of a page-numbered collection, with the page size validated
     * against this endpoint's own limits before a request is spent finding out.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, scalar|null>
     */
    private function pageQuery(int $page, ?int $pageSize, array $query): array
    {
        $query = $this->sizeQuery($pageSize, $query);
        $query['page'] = max(1, $page);

        return $query;
    }

    /**
     * The page size, validated against this endpoint's own limits, and nothing else - which is
     * what a cursor walk sends, since a cursor-paged collection ignores `page`.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, scalar|null>
     */
    private function sizeQuery(?int $pageSize, array $query): array
    {
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

        return $query;
    }

    /**
     * Stop a page-numbered walk that has landed on a cursor-paged collection with more to come.
     *
     * Two cursor shapes exist on this API: a string `result_info.cursor`, measured on the
     * Registrar, and an object `result_info.cursors` whose `after` points onward, in the
     * specification for list items. Either one, non-empty and without a `total_count` beside it,
     * means more pages that page numbers cannot reach.
     */
    private static function refuseCursorPaging(ApiResponse $response, string $path): void
    {
        $info = $response->envelope['result_info'] ?? null;

        if (!is_array($info) || array_key_exists('total_count', $info)) {
            return;
        }

        if (self::nextCursor($response) === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s is paged by cursor, not by page number: its result_info carries a cursor and no '
                . 'total_count. A page-numbered walk would return the first page as the whole '
                . 'collection, so it is refused. Walk it with apiEachByCursor().',
            $path
        ));
    }
}
