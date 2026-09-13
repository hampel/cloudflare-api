<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Support\Identifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class IdentifierTest extends BaseTestCase
{
    public function test_an_id_passes_through_trimmed(): void
    {
        $this->assertSame('023e105f4ecef8ad9ca31a8372d0c353', Identifier::for('023e105f4ecef8ad9ca31a8372d0c353', 'zone'));
        $this->assertSame('abc', Identifier::for('  abc  ', 'zone'));
    }

    /**
     * An id reaches a URL path, so anything in it that would change the path's shape has to be
     * encoded rather than concatenated. No real Cloudflare id contains one of these - they are
     * hex - but the value arrives from a caller, and a `/` in it would otherwise address a
     * different endpoint entirely.
     */
    public function test_a_value_that_would_change_the_path_is_encoded(): void
    {
        $this->assertSame('a%2Fb', Identifier::for('a/b', 'zone'));
        $this->assertSame('a%3Fb', Identifier::for('a?b', 'zone'));
        $this->assertSame('a%23b', Identifier::for('a#b', 'zone'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyIdentifiers(): array
    {
        return [
            'empty string' => [''],
            'spaces' => ['   '],
            'tab and newline' => ["\t\n"],
        ];
    }

    /**
     * The failure this exists for: an empty id collapses the path onto the collection, which
     * answers 200 with the wrong thing rather than an error.
     */
    #[DataProvider('emptyIdentifiers')]
    public function test_an_empty_id_is_refused_with_the_reason(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addresses the collection instead');

        Identifier::for($id, 'zone');
    }

    public function test_the_message_names_the_kind_of_id(): void
    {
        foreach (['zone', 'account', 'DNS record'] as $of) {
            try {
                Identifier::for('', $of);
                $this->fail($of . ' was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('A ' . $of . ' id is required', $e->getMessage());
            }
        }
    }
}
