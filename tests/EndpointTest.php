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
}
