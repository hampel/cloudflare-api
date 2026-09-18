<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Comparing what came back with what was sent, and the factory defaults that are deprecated
 * because an omitted argument silently chose an ordering.
 */
final class DnsRecordComparisonTest extends BaseTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function txtSpellings(): array
    {
        $long = str_repeat('a', 255) . str_repeat('b', 145);

        return [
            'unquoted' => ['v=spf1 -all', 'v=spf1 -all'],
            'one quoted string' => ['"v=spf1 -all"', 'v=spf1 -all'],
            'split into two' => ['"v=spf1 " "-all"', 'v=spf1 -all'],
            'split with no space' => ['"v=spf1 ""-all"', 'v=spf1 -all'],
            'a long value split into strings' => ['"' . str_repeat('a', 255) . '" "' . str_repeat('b', 145) . '"', $long],
            'a long value unsplit' => [$long, $long],
            'surrounding whitespace' => ["  \"v=spf1 -all\"\t", 'v=spf1 -all'],
            'escaped quote and backslash' => ['"say \"hi\" \\\\ bye"', 'say "hi" \\ bye'],
            'decimal escape' => ['"semi\059colon"', 'semi;colon'],
            'a DMARC value as dig prints it' => ['"v=DMARC1\\; p=none\\; pct=100"', 'v=DMARC1; p=none; pct=100'],
            'unterminated quoting left alone' => ['"v=spf1 -all', '"v=spf1 -all'],
            'text between strings left alone' => ['"a" b "c"', '"a" b "c"'],
            'a quote inside an unquoted value left alone' => ['a "b" c', 'a "b" c'],
            'empty quoted string' => ['""', ''],
        ];
    }

    #[DataProvider('txtSpellings')]
    public function test_a_txt_value_is_joined_and_unquoted(string $value, string $expected): void
    {
        $this->assertSame($expected, DnsRecord::comparableTxt($value));
    }

    public function test_a_long_txt_value_compares_equal_to_its_split_spelling(): void
    {
        $sent = str_repeat('k', 400);

        $split = DnsRecord::fromArray([
            'type' => 'TXT',
            'name' => 'selector._domainkey.example.com',
            'content' => '"' . substr($sent, 0, 255) . '" "' . substr($sent, 255) . '"',
        ]);

        $this->assertNotSame($sent, $split->content);
        $this->assertSame(DnsRecord::comparableTxt($sent), $split->comparableContent());
    }

    public function test_content_of_other_types_is_unchanged(): void
    {
        $record = DnsRecord::fromArray(['type' => 'CNAME', 'name' => 'a.example.com', 'content' => '"quoted"']);

        $this->assertSame('"quoted"', $record->comparableContent());
    }

    public function test_a_record_with_no_content_has_nothing_to_compare(): void
    {
        $record = DnsRecord::fromArray(['type' => 'TXT', 'name' => 'a.example.com']);

        $this->assertNull($record->comparableContent());
    }

    #[IgnoreDeprecations]
    public function test_mx_without_a_priority_is_deprecated_and_still_gives_ten(): void
    {
        $this->expectUserDeprecationMessageMatches('/DnsRecord::mx\(\) without a priority is deprecated/');

        $record = DnsRecord::mx('example.com', 'mail.example.com');

        $this->assertSame(10, $record->priority);
    }

    public function test_mx_with_a_priority_is_not_deprecated(): void
    {
        $this->assertSame(0, DnsRecord::mx('example.com', 'mail.example.com', 0)->priority);
    }

    /**
     * @return array<string, array{?int, ?int}>
     */
    public static function srvOmissions(): array
    {
        return [
            'both left out' => [null, null],
            'weight left out' => [10, null],
            'priority left out' => [null, 5],
        ];
    }

    #[DataProvider('srvOmissions')]
    #[IgnoreDeprecations]
    public function test_srv_without_a_priority_and_a_weight_is_deprecated(?int $priority, ?int $weight): void
    {
        $this->expectUserDeprecationMessageMatches('/DnsRecord::srv\(\) without a priority and a weight is deprecated/');

        $record = DnsRecord::srv('_sip._tcp.example.com', 'sip.example.com', 5060, $priority, $weight);

        $this->assertSame($priority ?? 0, $record->component('priority'));
        $this->assertSame($weight ?? 0, $record->component('weight'));
    }

    public function test_srv_with_both_is_not_deprecated(): void
    {
        $record = DnsRecord::srv('_sip._tcp.example.com', 'sip.example.com', 5060, 0, 0);

        $this->assertSame(0, $record->component('priority'));
    }
}
