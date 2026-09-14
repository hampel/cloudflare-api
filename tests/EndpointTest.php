<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Endpoint\Endpoint;
use Hampel\Cloudflare\Api\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The base class's walk, driven through a subclass the way a consumer writes one.
 */
final class EndpointTest extends TestCase
{
    private function endpoint(): Endpoint
    {
        return new class ($this->cloudflare()->connection()) extends Endpoint {
            /**
             * @return \Generator<int, array<string, mixed>>
             */
            public function walk(): \Generator
            {
                return $this->apiEach('things', static fn (array $row): array => $row);
            }

            /**
             * @return \Generator<int, array<string, mixed>>
             */
            public function walkByCursor(?int $pageSize = null): \Generator
            {
                return $this->apiEachByCursor('things', static fn (array $row): array => $row, $pageSize);
            }

            protected function minimumPageSize(): int
            {
                return 1;
            }

            protected function maximumPageSize(): int
            {
                return 50;
            }

            protected function collectionName(): string
            {
                return 'things';
            }
        };
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function walk(Endpoint $endpoint): \Generator
    {
        $callable = [$endpoint, 'walk'];
        assert(is_callable($callable));
        $walk = $callable();
        assert($walk instanceof \Generator);

        return $walk;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function walkByCursor(Endpoint $endpoint, ?int $pageSize = null): \Generator
    {
        $callable = [$endpoint, 'walkByCursor'];
        assert(is_callable($callable));
        $walk = $callable($pageSize);
        assert($walk instanceof \Generator);

        return $walk;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function page(array $rows, array $info): array
    {
        return $this->envelope($rows, ['result_info' => $info]);
    }

    /**
     * The defect 1.0.2 fixes. Keys used to restart at 0 on every page, so iterator_to_array() -
     * which preserves keys by default - let each page overwrite the last. Four rows over two pages
     * came back as two.
     */
    public function test_keys_run_across_the_whole_walk_so_iterator_to_array_keeps_every_page(): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1], ['n' => 2]], ['page' => 1, 'per_page' => 2, 'count' => 2, 'total_count' => 4, 'total_pages' => 2]));
        $this->client->pushJson(200, $this->page([['n' => 3], ['n' => 4]], ['page' => 2, 'per_page' => 2, 'count' => 2, 'total_count' => 4, 'total_pages' => 2]));

        $rows = iterator_to_array($this->walk($this->endpoint()));

        $this->assertSame([0, 1, 2, 3], array_keys($rows));
        $this->assertSame([1, 2, 3, 4], array_column($rows, 'n'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cursorPagedWithMoreToCome(): array
    {
        return [
            // measured on the Registrar, per_page=1 against 22 registrations
            'cursor string' => [['cursor' => 'eyJ0IjoiMjAwNSJ9', 'per_page' => 1, 'count' => 1]],
            // the list items shape, from the specification
            'cursors.after' => [['cursors' => ['before' => '', 'after' => 'eyJhIjoxfQ'], 'per_page' => 1, 'count' => 1]],
        ];
    }

    /**
     * The silent truncation 1.0.2 turns into an error. Nothing is yielded before the refusal, so a
     * caller never processes part of a collection and then meets the exception.
     *
     * @param  array<string, mixed>  $info
     */
    #[DataProvider('cursorPagedWithMoreToCome')]
    public function test_a_cursor_paged_collection_with_more_pages_is_refused_before_anything_is_yielded(array $info): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1]], $info));

        $yielded = 0;

        try {
            foreach ($this->walk($this->endpoint()) as $row) {
                $yielded++;
            }
            $this->fail('a cursor-paged collection was walked as one page');
        } catch (RuntimeException $e) {
            $this->assertSame(0, $yielded);
            $this->assertStringContainsString('paged by cursor', $e->getMessage());
            $this->assertCount(1, $this->client->requests, 'refused on the first page, not after guessing further');
        }
    }

    /**
     * An empty cursor is Cloudflare's "no more pages" - measured on the Registrar at per_page=50
     * against 22 registrations. The first page is the whole collection, so it is walked.
     */
    public function test_a_cursor_paged_collection_that_fits_on_one_page_is_walked(): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1], ['n' => 2]], ['cursor' => '', 'per_page' => 50, 'count' => 2]));

        $rows = iterator_to_array($this->walk($this->endpoint()));

        $this->assertSame([1, 2], array_column($rows, 'n'));
    }

    /**
     * A total_count means the collection pages by number, whatever else result_info carries.
     */
    public function test_a_cursor_beside_a_total_count_is_not_refused(): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1]], ['cursor' => 'x', 'page' => 1, 'per_page' => 1, 'count' => 1, 'total_count' => 1, 'total_pages' => 1]));

        $this->assertCount(1, iterator_to_array($this->walk($this->endpoint())));
    }

    /**
     * The Registrar's shape, measured: a string cursor while more remain, "" on the last page.
     * The cursor goes back as `?cursor=`, and `page` is never sent - the collection ignores it.
     */
    public function test_a_cursor_walk_follows_the_cursor_string_to_the_empty_one(): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1], ['n' => 2]], ['cursor' => 'c1', 'per_page' => 2, 'count' => 2]));
        $this->client->pushJson(200, $this->page([['n' => 3], ['n' => 4]], ['cursor' => 'c2', 'per_page' => 2, 'count' => 2]));
        $this->client->pushJson(200, $this->page([['n' => 5]], ['cursor' => '', 'per_page' => 2, 'count' => 1]));

        $rows = iterator_to_array($this->walkByCursor($this->endpoint(), 2));

        $this->assertSame([0, 1, 2, 3, 4], array_keys($rows), 'keys run across the whole walk');
        $this->assertSame([1, 2, 3, 4, 5], array_column($rows, 'n'));
        $this->assertCount(3, $this->client->requests);

        $queries = array_map(static function ($request): array {
            parse_str($request->getUri()->getQuery(), $parsed);

            return $parsed;
        }, $this->client->requests);

        $this->assertSame(['per_page' => '2'], $queries[0], 'the first page carries no cursor');
        $this->assertSame(['per_page' => '2', 'cursor' => 'c1'], $queries[1]);
        $this->assertSame(['per_page' => '2', 'cursor' => 'c2'], $queries[2]);

        foreach ($queries as $query) {
            $this->assertArrayNotHasKey('page', $query);
        }
    }

    /**
     * The list items shape, from the specification: `cursors.after` carries the next cursor.
     */
    public function test_a_cursor_walk_follows_cursors_after(): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1]], ['cursors' => ['before' => '', 'after' => 'a1'], 'per_page' => 1, 'count' => 1]));
        $this->client->pushJson(200, $this->page([['n' => 2]], ['cursors' => ['before' => 'a1', 'after' => ''], 'per_page' => 1, 'count' => 1]));

        $this->assertSame([1, 2], array_column(iterator_to_array($this->walkByCursor($this->endpoint(), 1)), 'n'));
        $this->assertStringContainsString('cursor=a1', $this->sentQuery());
    }

    public function test_a_cursor_walk_stops_on_an_empty_page_even_with_a_cursor(): void
    {
        $this->client->pushJson(200, $this->page([], ['cursor' => 'c1', 'per_page' => 2, 'count' => 0]));

        $this->assertSame([], iterator_to_array($this->walkByCursor($this->endpoint(), 2)));
        $this->assertCount(1, $this->client->requests);
    }

    /**
     * A repeated cursor would loop forever. Stopping quietly would return part of the collection as
     * all of it, so it raises - after yielding what it had, and naming how much that was.
     */
    public function test_a_cursor_issued_twice_raises_rather_than_looping_or_truncating_quietly(): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1]], ['cursor' => 'same', 'per_page' => 1, 'count' => 1]));
        $this->client->pushJson(200, $this->page([['n' => 2]], ['cursor' => 'same', 'per_page' => 1, 'count' => 1]));

        $yielded = 0;

        try {
            foreach ($this->walkByCursor($this->endpoint(), 1) as $row) {
                $yielded++;
            }
            $this->fail('a repeated cursor was followed or ignored');
        } catch (RuntimeException $e) {
            $this->assertSame(2, $yielded);
            $this->assertStringContainsString('already issued', $e->getMessage());
            $this->assertStringContainsString('after 2 items', $e->getMessage());
            $this->assertCount(2, $this->client->requests);
        }
    }

    public function test_the_refusal_names_the_walk_to_use_instead(): void
    {
        $this->client->pushJson(200, $this->page([['n' => 1]], ['cursor' => 'c1', 'per_page' => 1, 'count' => 1]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('apiEachByCursor()');

        iterator_to_array($this->walk($this->endpoint()));
    }

    public function test_a_cursor_walk_validates_the_page_size_before_a_request(): void
    {
        try {
            iterator_to_array($this->walkByCursor($this->endpoint(), 51));
            $this->fail('a page size above the maximum was sent');
        } catch (\Hampel\Cloudflare\Api\Exception\InvalidArgumentException $e) {
            $this->assertSame([], $this->client->requests);
        }
    }
}
