<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Config;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ConfigTest extends BaseTestCase
{
    public function test_it_defaults_to_cloudflares_own_host_and_version_segment(): void
    {
        $config = new Config();

        $this->assertSame('https://api.cloudflare.com', $config->baseUri);
        $this->assertSame('api.cloudflare.com', $config->host());
        $this->assertSame('https://api.cloudflare.com/client/v4/zones', $config->resolve('zones'));
    }

    public function test_a_path_copied_from_the_documentation_is_normalised_rather_than_doubled(): void
    {
        $config = new Config();

        $this->assertSame('https://api.cloudflare.com/client/v4/zones', $config->resolve('/client/v4/zones'));
        $this->assertSame('https://api.cloudflare.com/client/v4/zones', $config->resolve('client/v4/zones'));
        $this->assertSame('https://api.cloudflare.com/client/v4', $config->resolve('/client/v4'));
    }

    public function test_an_absolute_uri_passes_through_untouched(): void
    {
        $config = new Config();
        $url = 'https://api.cloudflare.com/client/v4/zones?page=2';

        $this->assertSame($url, $config->resolve($url));
    }

    public function test_the_base_uri_is_settable_for_a_proxy_or_a_local_fixture(): void
    {
        $config = new Config('http://127.0.0.1:8080/');

        $this->assertSame('http://127.0.0.1:8080', $config->baseUri);
        $this->assertSame('http://127.0.0.1:8080/client/v4/zones', $config->resolve('zones'));
    }

    public function test_a_base_uri_without_a_scheme_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('api.cloudflare.com');
    }

    public function test_an_empty_base_uri_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('   ');
    }

    /**
     * A boolean rendered as 1/0 is a filter Cloudflare does not read - and an unread filter
     * is not an error, it is the whole unfiltered collection returned with a 200.
     */
    public function test_booleans_are_written_as_true_and_false(): void
    {
        $config = new Config();

        $this->assertSame(
            'https://api.cloudflare.com/client/v4/zones?proxied=true&paused=false',
            $config->resolve('zones', ['proxied' => true, 'paused' => false])
        );
    }

    public function test_null_parameters_are_dropped_and_empty_strings_are_kept(): void
    {
        $config = new Config();

        $this->assertSame(
            'https://api.cloudflare.com/client/v4/zones?comment.present=',
            $config->resolve('zones', ['name' => null, 'comment.present' => '']),
            'a presence-only parameter means something by appearing at all'
        );
    }

    public function test_a_dotted_parameter_name_is_not_mangled(): void
    {
        $config = new Config();

        $this->assertStringContainsString(
            'name.endswith=.example.com',
            $config->resolve('zones/z/dns_records', ['name.endswith' => '.example.com'])
        );
    }

    public function test_a_query_is_appended_to_a_uri_that_already_has_one(): void
    {
        $config = new Config();

        $this->assertSame(
            'https://api.cloudflare.com/client/v4/zones?page=2&per_page=20',
            $config->resolve('https://api.cloudflare.com/client/v4/zones?page=2', ['per_page' => 20])
        );
    }

    public function test_a_default_page_size_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(pageSize: 0);
    }
}
