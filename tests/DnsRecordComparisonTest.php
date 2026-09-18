<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Comparing what came back with what was sent.
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
            'a long value as Cloudflare splits it' => ['"' . str_repeat('a', 255) . '" "' . str_repeat('b', 145) . '"', $long],
            'a long value unsplit' => [$long, $long],
            'surrounding whitespace' => ["  \"v=spf1 -all\"\t", 'v=spf1 -all'],
            'escaped quote and backslash' => ['"say \"hi\" \\\\ bye"', 'say "hi" \\ bye'],
            'decimal escape' => ['"semi\059colon"', 'semi;colon'],
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

    public function test_a_long_txt_value_compares_equal_to_its_split_read_back(): void
    {
        $sent = str_repeat('k', 400);

        $readBack = DnsRecord::fromArray([
            'type' => 'TXT',
            'name' => 'selector._domainkey.example.com',
            'content' => '"' . substr($sent, 0, 255) . '" "' . substr($sent, 255) . '"',
        ]);

        $this->assertNotSame($sent, $readBack->content);
        $this->assertSame(DnsRecord::comparableTxt($sent), $readBack->comparableContent());
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
}
