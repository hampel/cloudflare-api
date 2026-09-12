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

    public function test_an_unknown_record_type_does_not_break_parsing(): void
    {
        $record = DnsRecord::fromArray(['type' => 'FUTURE', 'name' => 'x.example.com', 'content' => 'v']);

        $this->assertSame('FUTURE', $record->raw['type'], 'the real value stays reachable');
        $this->assertSame('x.example.com', $record->name);
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
