<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Tests;

use Hampel\Cloudflare\Api\Entity\Registration;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;

/**
 * Fixtures follow the object measured on a live account on 2026-09-14 - the specification leaves
 * it undefined.
 */
final class RegistrationsTest extends TestCase
{
    private const ACCOUNT_ID = '01a7362d577a6c3019a474fd6f485823';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(string $domain = 'example.com', array $overrides = []): array
    {
        return $overrides + [
            'domain_name' => $domain,
            'status' => 'active',
            'created_at' => '2005-11-24T04:53:57Z',
            'expires_at' => '2027-11-24T04:53:57Z',
            'auto_renew' => true,
            'privacy_mode' => 'redaction',
            'locked' => true,
        ];
    }

    private function path(?string $domain = null): string
    {
        $path = '/client/v4/accounts/' . self::ACCOUNT_ID . '/registrar/registrations';

        return $domain === null ? $path : $path . '/' . $domain;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function cursorPage(array $rows, string $cursor, int $perPage): array
    {
        return $this->envelope($rows, ['result_info' => ['cursor' => $cursor, 'per_page' => $perPage, 'count' => count($rows)]]);
    }

    public function test_each_walks_every_page_by_cursor(): void
    {
        $this->client->pushJson(200, $this->cursorPage([$this->row('a.example'), $this->row('b.example')], 'eyJuIjoiYiJ9', 2));
        $this->client->pushJson(200, $this->cursorPage([$this->row('c.example')], '', 2));

        $registrations = iterator_to_array($this->cloudflare()->registrations()->each(self::ACCOUNT_ID, 2));

        $this->assertSame(
            ['a.example', 'b.example', 'c.example'],
            array_map(static fn (Registration $r): string => $r->domainName, $registrations)
        );
        $this->assertSame($this->path(), $this->sentPath());
        $this->assertSame(['per_page' => '2', 'cursor' => 'eyJuIjoiYiJ9'], $this->sentParameters());
    }

    public function test_all_asks_for_the_largest_page_the_endpoint_accepts(): void
    {
        $this->client->pushJson(200, $this->cursorPage([$this->row()], '', 50));

        $this->assertCount(1, $this->cloudflare()->registrations()->all(self::ACCOUNT_ID));
        $this->assertSame('50', $this->sentParameters()['per_page']);
    }

    public function test_a_page_size_above_fifty_is_refused_before_a_request(): void
    {
        try {
            iterator_to_array($this->cloudflare()->registrations()->each(self::ACCOUNT_ID, 51));
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('between 1 and 50 for registrations', $e->getMessage());
            $this->assertSame([], $this->client->requests);
        }
    }

    /**
     * Measured: the lookup is case-sensitive, and a registered domain asked for in capitals answers
     * 404 exactly as an unregistered one does. So the name is normalised before it is sent.
     */
    public function test_get_lower_cases_and_trims_the_domain_name(): void
    {
        $this->client->pushJson(200, $this->envelope($this->row()));

        $registration = $this->cloudflare()->registrations()->get(self::ACCOUNT_ID, ' Example.COM. ');

        $this->assertSame($this->path('example.com'), $this->sentPath());
        $this->assertSame('example.com', $registration->domainName);
        $this->assertTrue($registration->isActive());
    }

    /**
     * Measured: a domain the account does not hold is 404 with code 10000 - the code that means
     * "Authentication error" on a 403. Classified by status, so find() returns null.
     */
    public function test_find_returns_null_for_a_domain_the_account_does_not_hold(): void
    {
        $this->client->pushJson(404, $this->failure([['code' => 10000, 'message' => 'Domain not found']]));

        $this->assertNull($this->cloudflare()->registrations()->find(self::ACCOUNT_ID, 'example.com'));
    }

    /**
     * Measured before the token had Registrar access: 403 with the same code 10000. That is a
     * permission, not an absence, and find() must not read it as "not registered here".
     */
    public function test_find_still_raises_for_a_token_without_registrar_access(): void
    {
        $this->client->pushJson(403, $this->failure([['code' => 10000, 'message' => 'Authentication error']]));

        $this->expectException(NotPermittedException::class);

        $this->cloudflare()->registrations()->find(self::ACCOUNT_ID, 'example.com');
    }

    public function test_an_empty_domain_name_is_refused_before_a_request(): void
    {
        try {
            $this->cloudflare()->registrations()->get(self::ACCOUNT_ID, ' . ');
            $this->fail('did not raise');
        } catch (InvalidArgumentException $e) {
            $this->assertSame([], $this->client->requests);
        }
    }

    public function test_the_measured_row_maps_onto_the_entity(): void
    {
        $registration = Registration::fromArray($this->row());

        $this->assertSame('active', $registration->status);
        $this->assertSame('redaction', $registration->privacyMode);
        $this->assertTrue($registration->autoRenew);
        $this->assertTrue($registration->locked);
        $this->assertNotNull($registration->expiresAt);
        $this->assertSame('2027-11-24 04:53:57', $registration->expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $registration->expiresAt->getTimezone()->getName());
        $this->assertSame($this->row(), $registration->jsonSerialize(), 'the payload is kept whole');
    }

    /**
     * @return array<string, array{bool|null, bool}>
     */
    public static function autoRenewStates(): array
    {
        return [
            'off' => [false, true],
            'on' => [true, false],
            'not reported' => [null, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('autoRenewStates')]
    public function test_only_an_explicit_auto_renew_off_lapses_without_action(?bool $autoRenew, bool $lapses): void
    {
        $row = $this->row();
        $row['auto_renew'] = $autoRenew;

        $this->assertSame($lapses, Registration::fromArray($row)->lapsesWithoutAction());
    }

    public function test_expiry_can_be_checked_ahead_of_time(): void
    {
        $soon = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 days');
        $registration = Registration::fromArray($this->row('example.com', ['expires_at' => $soon->format(\DateTimeInterface::ATOM)]));

        $this->assertTrue($registration->expiresWithinDays(30));
        $this->assertFalse($registration->expiresWithinDays(5));
        $this->assertFalse(Registration::fromArray($this->row('example.com', ['expires_at' => null]))->expiresWithinDays(3650));
    }

    public function test_an_unreported_status_is_not_active(): void
    {
        $this->assertFalse(Registration::fromArray($this->row('example.com', ['status' => 'pending_transfer']))->isActive());
        $this->assertFalse(Registration::fromArray(['domain_name' => 'example.com'])->isActive());
    }
}
