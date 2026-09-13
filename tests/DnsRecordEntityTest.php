<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Enum\CaaTag;
use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Support\Ttl;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class DnsRecordEntityTest extends BaseTestCase
{
    public function test_the_two_families_of_type_are_told_apart(): void
    {
        foreach ([RecordType::A, RecordType::AAAA, RecordType::CNAME, RecordType::MX,
            RecordType::NS, RecordType::OPENPGPKEY, RecordType::PTR, RecordType::TXT] as $type) {
            $this->assertFalse($type->usesData(), $type->value . ' carries its value in content');
        }

        foreach ([RecordType::CAA, RecordType::CERT, RecordType::DNSKEY, RecordType::DS,
            RecordType::HTTPS, RecordType::LOC, RecordType::NAPTR, RecordType::SMIMEA,
            RecordType::SRV, RecordType::SSHFP, RecordType::SVCB, RecordType::TLSA,
            RecordType::URI] as $type) {
            $this->assertTrue($type->usesData(), $type->value . ' carries its value in data');
        }
    }

    public function test_only_mx_and_uri_use_the_top_level_priority(): void
    {
        $this->assertTrue(RecordType::MX->usesPriority());
        $this->assertTrue(RecordType::URI->usesPriority());
        $this->assertFalse(RecordType::SRV->usesPriority(), 'an SRV priority is a data component');
        $this->assertFalse(RecordType::A->usesPriority());
    }

    public function test_only_address_records_and_cnames_are_proxiable(): void
    {
        $this->assertTrue(RecordType::A->isProxiable());
        $this->assertTrue(RecordType::AAAA->isProxiable());
        $this->assertTrue(RecordType::CNAME->isProxiable());
        $this->assertFalse(RecordType::TXT->isProxiable());
        $this->assertFalse(RecordType::MX->isProxiable());
    }

    /**
     * The API appends the zone to a bare label, so a mistake here produces a working record
     * in an unintended place rather than an error - which is why it is caught locally.
     */
    public function test_a_record_name_must_be_the_full_name(): void
    {
        $record = DnsRecord::a('www.example.com', '203.0.113.10');

        $this->assertSame('www.example.com', $record->name);
        $this->assertSame('www.example.com', DnsRecord::a('www.example.com.', '203.0.113.10')->name);
    }

    /**
     * The three named constructors the README lists that nothing else here exercises. Each is
     * a one-liner, and each puts its value in a different place - so the assertion is the
     * emitted payload rather than the constructor returning something.
     */
    public function test_the_remaining_named_constructors_emit_the_right_payload(): void
    {
        $this->assertSame(
            ['type' => 'AAAA', 'name' => 'www.example.com', 'content' => '2001:db8::1'],
            DnsRecord::aaaa('www.example.com', '2001:db8::1')->toArray()
        );

        $this->assertSame(
            ['type' => 'NS', 'name' => 'sub.example.com', 'content' => 'ns1.elsewhere.com'],
            DnsRecord::ns('sub.example.com', 'ns1.elsewhere.com')->toArray()
        );

        $this->assertSame(
            ['type' => 'PTR', 'name' => '10.113.0.203.in-addr.arpa', 'content' => 'www.example.com'],
            DnsRecord::ptr('10.113.0.203.in-addr.arpa', 'www.example.com')->toArray()
        );
    }

    /**
     * @return array<string, array{\Closure(): DnsRecord}>
     */
    public static function constructorsRefusingAnEmptyValue(): array
    {
        return [
            'aaaa' => [static fn (): DnsRecord => DnsRecord::aaaa('www.example.com', '')],
            'ns' => [static fn (): DnsRecord => DnsRecord::ns('sub.example.com', '')],
            'ptr' => [static fn (): DnsRecord => DnsRecord::ptr('10.113.0.203.in-addr.arpa', ' ')],
        ];
    }

    /**
     * ONE CASE PER TEST, and that is the whole point of the provider rather than tidiness. A
     * loop over the three reports only the first to break - with `expectException()` it
     * reports nothing at all, because the first throw ends the test and the remaining cases
     * never run while the suite stays green. Measured here: with `ns()` and `ptr()` both
     * broken, a loop named `ns` and left `ptr` invisible until the first was fixed.
     *
     * @param  \Closure(): DnsRecord  $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('constructorsRefusingAnEmptyValue')]
    public function test_those_constructors_refuse_an_empty_value_like_the_others(\Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }

    public function test_an_empty_name_is_refused_with_the_reason(): void
    {
        try {
            DnsRecord::a('', '203.0.113.10');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('full name including the zone', $e->getMessage());
        }
    }

    public function test_an_at_sign_is_refused_because_cloudflare_has_no_apex_shorthand(): void
    {
        try {
            DnsRecord::a('@', '203.0.113.10');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no "@" for the zone apex', $e->getMessage());
        }
    }

    /**
     * An SRV whose name lacks the decoration is accepted by the API as an ordinary record,
     * which no service lookup will ever find - a silent failure worth refusing locally.
     */
    public function test_an_undecorated_srv_name_is_refused(): void
    {
        try {
            DnsRecord::srv('sip.example.com', 'sip.example.com', port: 5060);
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('_sip._tcp.example.com', $e->getMessage());
        }
    }

    public function test_proxying_forces_the_ttl_to_automatic(): void
    {
        $record = DnsRecord::a('www.example.com', '203.0.113.10')->withTtl(3600)->proxy();

        $this->assertTrue($record->isProxied());
        $this->assertSame(Ttl::AUTOMATIC, $record->ttl, 'a proxied record has no TTL of its own');
        $this->assertSame(['type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10',
            'ttl' => 1, 'proxied' => true], $record->toArray());
    }

    public function test_setting_a_ttl_on_a_proxied_record_is_refused_rather_than_sent(): void
    {
        $record = DnsRecord::a('www.example.com', '203.0.113.10')->proxy();

        try {
            $record->withTtl(3600);
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('proxied record has no TTL of its own', $e->getMessage());
        }
    }

    public function test_a_type_that_cannot_be_proxied_says_so(): void
    {
        try {
            DnsRecord::txt('_dmarc.example.com', 'v=DMARC1; p=none')->proxy();
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot proxy a TXT record', $e->getMessage());
        }
    }

    public function test_a_ttl_cloudflare_would_refuse_never_leaves_the_process(): void
    {
        foreach ([2, 30, 59, 86401] as $ttl) {
            try {
                DnsRecord::a('www.example.com', '203.0.113.10')->withTtl($ttl);
                $this->fail('TTL ' . $ttl . ' was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('between 60 and 86400', $e->getMessage());
            }
        }
    }

    public function test_one_is_automatic_and_not_one_second(): void
    {
        $record = DnsRecord::a('www.example.com', '203.0.113.10')->withTtl(Ttl::AUTOMATIC);

        $this->assertSame(1, $record->ttl);
        $this->assertSame(300, $record->effectiveTtl(), 'what it is actually served as');
        $this->assertSame('automatic (300s)', Ttl::describe(1));
        $this->assertTrue(Ttl::isValid(1));
        $this->assertTrue(Ttl::isAutomatic(1));
    }

    /**
     * `1` is the only automatic value, and nothing near it is. 300 is what automatic is SERVED
     * as, which makes it the value most likely to be mistaken for the flag - a record set to
     * 300 has a fixed five-minute TTL, and one set to 1 has whatever Cloudflare decides
     * automatic means.
     */
    public function test_only_one_is_automatic(): void
    {
        $this->assertTrue(Ttl::isAutomatic(Ttl::AUTOMATIC));

        foreach ([0, 2, 60, 300, Ttl::AUTOMATIC_SECONDS, 3600, 86400] as $ttl) {
            $this->assertFalse(Ttl::isAutomatic($ttl), $ttl . ' is not the automatic flag');
        }
    }

    public function test_a_record_with_no_ttl_reports_the_automatic_default(): void
    {
        $record = DnsRecord::a('www.example.com', '203.0.113.10');

        $this->assertNull($record->ttl);
        $this->assertSame(300, $record->effectiveTtl());
        $this->assertArrayNotHasKey('ttl', $record->toArray(), 'unset means unsent');
    }

    public function test_the_enterprise_floor_is_opt_in(): void
    {
        $this->assertFalse(Ttl::isValid(30));
        $this->assertTrue(Ttl::isValid(30, enterprise: true));
        $this->assertFalse(Ttl::isValid(29, enterprise: true));
    }

    public function test_content_cannot_be_set_on_a_data_type(): void
    {
        $record = DnsRecord::caa('example.com', CaaTag::Issue, 'letsencrypt.org');

        try {
            $record->withContent('0 issue "letsencrypt.org"');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('generated by Cloudflare', $e->getMessage());
        }
    }

    public function test_data_cannot_be_set_on_a_content_type(): void
    {
        try {
            DnsRecord::a('www.example.com', '203.0.113.10')->withData(['x' => 1]);
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no `data` components', $e->getMessage());
        }
    }

    public function test_the_generic_constructors_refuse_the_wrong_family(): void
    {
        try {
            DnsRecord::of(RecordType::CAA, 'example.com', 'anything');
            $this->fail('of() accepted a data type');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Use components() instead', $e->getMessage());
        }

        try {
            DnsRecord::components(RecordType::A, 'www.example.com', ['x' => 1]);
            $this->fail('components() accepted a content type');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Use of() instead', $e->getMessage());
        }
    }

    public function test_a_priority_on_an_srv_points_at_the_data_components(): void
    {
        $record = DnsRecord::srv('_sip._tcp.example.com', 'sip.example.com', port: 5060);

        try {
            $record->withPriority(10);
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('inside `data`', $e->getMessage());
        }
    }

    public function test_a_priority_outside_the_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DnsRecord::mx('example.com', 'mail.example.com', priority: 65536);
    }

    public function test_tags_are_sent_when_set_and_omitted_when_never_given(): void
    {
        $built = DnsRecord::a('www.example.com', '203.0.113.10');
        $this->assertArrayNotHasKey('tags', $built->toArray());

        $tagged = $built->withTags(['production']);
        $this->assertSame(['production'], $tagged->toArray()['tags']);

        // A record read from the API has the key, so an empty list must be sent - that is how
        // every tag is removed.
        $fetched = DnsRecord::fromArray([
            'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10', 'tags' => ['x'],
        ]);
        $this->assertSame([], $fetched->withTags([])->toArray()['tags']);
    }

    /**
     * A type Cloudflare adds that this release does not know reads as `null`, not as a guess.
     *
     * It used to fall back to `RecordType::TXT`, which was the one field that decides where a
     * record's value lives, whether it can be proxied and whether it has a priority - so the
     * guess made `$record->type === RecordType::TXT` true for a record that was not TXT, and
     * would have sent `content` for a record whose value belongs in `data`.
     */
    public function test_an_unknown_record_type_reads_as_null_rather_than_a_guess(): void
    {
        $record = DnsRecord::fromArray(['type' => 'FUTURE', 'name' => 'x.example.com', 'content' => 'v']);

        $this->assertNull($record->type);
        $this->assertNotSame(RecordType::TXT, $record->type, 'it must not masquerade as a type it is not');
        $this->assertSame('FUTURE', $record->raw['type'], 'the real value stays reachable');
        $this->assertSame('x.example.com', $record->name);
        $this->assertSame('v', $record->content);
    }

    /**
     * Reading such a record is fine. Writing it is refused, because every rule for building the
     * payload is a property of the type.
     */
    public function test_a_record_of_an_unknown_type_cannot_be_written_back(): void
    {
        $record = DnsRecord::fromArray(['type' => 'FUTURE', 'name' => 'x.example.com', 'content' => 'v']);

        foreach ([
            'toArray' => static fn (): mixed => $record->toArray(),
            'toPatchArray' => static fn (): mixed => $record->toPatchArray(),
            'proxy' => static fn (): mixed => $record->proxy(),
            'withContent' => static fn (): mixed => $record->withContent('x'),
            'withPriority' => static fn (): mixed => $record->withPriority(10),
        ] as $what => $call) {
            try {
                $call();
                $this->fail($what . '() built a payload for a type it does not model');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('FUTURE', $e->getMessage(), $what);
            }
        }
    }

    /**
     * describe() is for logs and must never throw - an unloggable record is worse than an
     * imprecise log line.
     */
    public function test_an_unknown_type_still_describes_itself(): void
    {
        $record = DnsRecord::fromArray(['type' => 'FUTURE', 'name' => 'x.example.com', 'content' => 'v']);

        $this->assertStringContainsString('FUTURE', $record->describe());
        $this->assertStringContainsString('x.example.com', $record->describe());
    }

    public function test_describe_reads_like_a_zone_file_line(): void
    {
        $record = DnsRecord::a('www.example.com', '203.0.113.10')->withTtl(3600);

        $this->assertSame('www.example.com 3600s A 203.0.113.10', $record->describe());
        $this->assertStringContainsString('(proxied)', DnsRecord::a('www.example.com', '203.0.113.10')->proxy()->describe());
    }

    public function test_timestamps_are_parsed_as_utc(): void
    {
        $record = DnsRecord::fromArray([
            'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10',
            'created_on' => '2014-01-01T05:20:00.12345Z',
        ]);

        $this->assertNotNull($record->createdOn);
        $this->assertSame('2014-01-01 05:20:00', $record->createdOn->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $record->createdOn->getTimezone()->getName());
    }
}
