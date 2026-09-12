<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Support\RecordQuery;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class RecordQueryTest extends BaseTestCase
{
    public function test_an_empty_query_sends_nothing(): void
    {
        $this->assertTrue(RecordQuery::make()->isEmpty());
        $this->assertSame([], RecordQuery::make()->toQuery());
    }

    public function test_the_four_name_predicates_map_to_their_dotted_parameters(): void
    {
        $this->assertSame(['name.exact' => 'www.example.com'], RecordQuery::name('www.example.com')->toQuery());
        $this->assertSame(['name.contains' => 'w.exam'], RecordQuery::make()->nameContains('w.exam')->toQuery());
        $this->assertSame(['name.startswith' => 'www'], RecordQuery::make()->nameStartsWith('www')->toQuery());
        $this->assertSame(['name.endswith' => '.example.com'], RecordQuery::make()->nameEndsWith('.example.com')->toQuery());
    }

    public function test_the_content_predicates_map_the_same_way(): void
    {
        $this->assertSame(['content.exact' => '203.0.113.10'], RecordQuery::make()->content('203.0.113.10')->toQuery());
        $this->assertSame(['content.contains' => '0.113'], RecordQuery::make()->contentContains('0.113')->toQuery());
        $this->assertSame(['content.startswith' => '203.'], RecordQuery::make()->contentStartsWith('203.')->toQuery());
        $this->assertSame(['content.endswith' => '.10'], RecordQuery::make()->contentEndsWith('.10')->toQuery());
    }

    public function test_it_is_immutable(): void
    {
        $base = RecordQuery::ofType(RecordType::A);
        $specialised = $base->nameEndsWith('.example.com');

        $this->assertSame(['type' => 'A'], $base->toQuery(), 'the base is unchanged');
        $this->assertSame(['type' => 'A', 'name.endswith' => '.example.com'], $specialised->toQuery());
    }

    public function test_both_proxied_values_are_a_filter(): void
    {
        $this->assertSame(['proxied' => true], RecordQuery::make()->proxied()->toQuery());
        $this->assertSame(
            ['proxied' => false],
            RecordQuery::make()->proxied(false)->toQuery(),
            'asking for the DNS-only records is not the same as not asking'
        );
    }

    public function test_comment_presence_is_a_parameter_whose_meaning_is_its_existence(): void
    {
        $this->assertSame(['comment.present' => ''], RecordQuery::make()->hasComment()->toQuery());
        $this->assertSame(['comment.absent' => ''], RecordQuery::make()->hasNoComment()->toQuery());
    }

    public function test_tag_conditions(): void
    {
        $this->assertSame(['tag.present' => 'important'], RecordQuery::make()->tagged('important')->toQuery());
        $this->assertSame(['tag.absent' => 'important'], RecordQuery::make()->notTagged('important')->toQuery());
        $this->assertSame(['tag.exact' => 'team:DNS'], RecordQuery::make()->tagIs('team:DNS')->toQuery());
    }

    public function test_a_tag_value_condition_without_a_value_is_refused(): void
    {
        try {
            RecordQuery::make()->tagIs('team');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Use tagged() to match on the tag name alone', $e->getMessage());
        }
    }

    /**
     * `name.exact=` matches nothing and the request succeeds, so an empty variable that got
     * this far would be reported as "no such record" rather than as the mistake it is.
     */
    public function test_an_empty_condition_value_is_refused(): void
    {
        try {
            RecordQuery::make()->nameIs('   ');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('empty one matches nothing', $e->getMessage());
        }
    }

    public function test_ordering_is_restricted_to_the_fields_the_api_allows(): void
    {
        $this->assertSame(
            ['order' => 'name', 'direction' => 'asc'],
            RecordQuery::make()->orderBy('name')->toQuery()
        );

        try {
            RecordQuery::make()->orderBy('comment');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('type, name, content, ttl, proxied', $e->getMessage());
        }
    }

    public function test_a_direction_without_a_field_is_refused(): void
    {
        try {
            RecordQuery::make()->descending();
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('call orderBy() first', $e->getMessage());
        }
    }

    public function test_an_invalid_direction_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecordQuery::make()->orderBy('name', 'sideways');
    }

    public function test_match_and_tag_match_are_separate_settings(): void
    {
        $query = RecordQuery::make()->tagged('a')->tagged('b')->matchAny()->tagMatchAll();

        $this->assertSame('any', $query->toQuery()['match']);
        $this->assertSame('all', $query->toQuery()['tag_match']);
    }

    public function test_neither_is_sent_when_neither_was_set(): void
    {
        $query = RecordQuery::ofType(RecordType::A)->toQuery();

        $this->assertArrayNotHasKey('match', $query, "the API's own default applies rather than ours");
        $this->assertArrayNotHasKey('tag_match', $query);
    }
}
