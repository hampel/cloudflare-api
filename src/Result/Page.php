<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Result;

use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;

/**
 * One page of a collection, and the pagination that came with it.
 *
 * Every paginated endpoint on this API answers in the same envelope, so this works for one
 * this package has never wrapped.
 *
 * THE PAGE SIZE LIMITS DIFFER PER ENDPOINT, which is the trap worth naming here. DNS records
 * accept 1 to 5,000,000 and default to 100. Zones and accounts accept 5 to 50 and default to
 * 20 - so a page size of 1, perfectly good for records, is a 400 on zones, and one of 100 is
 * a 400 there too. There is no single correct range to validate against, so the limits live
 * on the endpoints that own them and are passed to assertValidPageSize().
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ResultInfo $info,
    ) {
    }

    /**
     * @template TItem
     * @param  ApiResponse  $response  a collection response
     * @param  callable(array<string, mixed>): TItem  $map  how to build one item
     * @return self<TItem>
     */
    public static function fromResponse(ApiResponse $response, callable $map): self
    {
        $rows = $response->rows();
        $items = array_map($map, $rows);

        $info = $response->resultInfo() ?? ResultInfo::fromArray([
            'page' => 1,
            'per_page' => count($rows),
            'count' => count($rows),
            'total_count' => count($rows),
            'total_pages' => 1,
        ]);

        return new self(array_values($items), $info);
    }

    public function currentPage(): int
    {
        return $this->info->page;
    }

    public function lastPage(): int
    {
        return $this->info->totalPages;
    }

    /**
     * How many items there are altogether, across every page. `count($page)` is how many are
     * on this one.
     */
    public function total(): int
    {
        return $this->info->totalCount;
    }

    /**
     * Whether another page exists.
     *
     * This is the test to loop on. Reading until a page comes back empty also works - a page
     * past the last one is an empty `result` and a 200, not an error - but it costs one
     * wasted request every time, on an API that counts them against a five-minute budget.
     */
    public function hasMore(): bool
    {
        return $this->info->hasMore();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * How many items are on THIS page.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<T>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }

    /**
     * Refuse a page size the endpoint will refuse, before spending a request finding out.
     *
     * The bounds are the caller's because they belong to the endpoint - see the note on this
     * class. Getting them from the endpoint rather than from a constant here is what stops
     * one endpoint's limits being quietly applied to another's request.
     */
    public static function assertValidPageSize(int $pageSize, int $minimum, int $maximum, string $of): void
    {
        if ($pageSize < $minimum || $pageSize > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Cloudflare accepts a page size between %d and %d for %s; %d was asked for. '
                    . 'The limits differ per endpoint, so a size that works elsewhere on this '
                    . 'API can still be refused here.',
                $minimum,
                $maximum,
                $of,
                $pageSize
            ));
        }
    }
}
