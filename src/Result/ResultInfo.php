<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Result;

use Hampel\Cloudflare\Api\Support\Cast;

/**
 * The `result_info` object that comes with every collection.
 *
 *     {"page": 1, "per_page": 100, "count": 100, "total_count": 247, "total_pages": 3}
 *
 * `count` IS THIS PAGE AND `total_count` IS EVERYTHING - two numbers that are easy to read
 * as each other, and the reason they have methods with different names here.
 *
 * Cloudflare has been known to omit `total_pages` on some endpoints, so it is derived from
 * `total_count` and `per_page` when it is missing rather than defaulted to 1 - which would
 * end a walk after the first page and report a partial zone as a whole one.
 */
final class ResultInfo implements \JsonSerializable
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        /** How many items are on THIS page. */
        public readonly int $count,
        /** How many there are altogether, across every page. */
        public readonly int $totalCount,
        public readonly int $totalPages,
    ) {
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public static function fromArray(array $info): self
    {
        $page = Cast::int($info['page'] ?? null) ?? 1;
        $perPage = Cast::int($info['per_page'] ?? null) ?? 0;
        $count = Cast::int($info['count'] ?? null) ?? 0;
        $totalCount = Cast::int($info['total_count'] ?? null) ?? $count;
        $totalPages = Cast::int($info['total_pages'] ?? null);

        if ($totalPages === null) {
            $totalPages = $perPage > 0 ? (int) ceil($totalCount / $perPage) : 1;
        }

        return new self($page, $perPage, $count, $totalCount, max(1, $totalPages));
    }

    public function hasMore(): bool
    {
        return $this->page < $this->totalPages;
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'count' => $this->count,
            'total_count' => $this->totalCount,
            'total_pages' => $this->totalPages,
        ];
    }
}
