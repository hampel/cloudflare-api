<?php

/**
 * Exercise: the account's Cloudflare Registrar domains, walked by cursor. Read-only.
 *
 * READ-ONLY, and needs a token with `Account / Registrar: Domains / Read`, an account-level
 * permission. Without it Cloudflare answers `403, code 10000, Authentication error`, and this says
 * so.
 *
 * It settles one question a stubbed test cannot, and reports the rest:
 *
 *   - DOES THE CURSOR WALK REACH EVERY PAGE? The same collection is walked twice, once at one
 *     registration per page and once at fifty, and the two counts are printed side by side. A walk
 *     that stopped after its first page - which is what a page-numbered walk did here before
 *     1.1.0 - would report 1 against the true total. They must agree.
 *   - which registrations expire soon, have auto-renew off, or are unlocked - the report a
 *     person actually wants from this endpoint.
 *
 * The account is the one CLOUDFLARE_DOMAIN's zone belongs to.
 *
 * Needs CLOUDFLARE_TOKEN and CLOUDFLARE_DOMAIN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Cloudflare\Api\Entity\Registration;
use Hampel\Cloudflare\Api\Exception\ExceptionInterface;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;

require __DIR__ . '/lib/client.php';

$io->title('cloudflare · registrar registrations');

$cloudflare = harness_client($io);
$zone = harness_zone($io, $cloudflare);

if ($zone->accountId === null) {
    $io->error('The zone did not report its account, so there is no account to read registrations from.');

    exit(1);
}

$io->value('account', sprintf('%s (%s)', $zone->accountName ?? '?', $zone->accountId));
$io->line();

try {
    // One per page first: every registration is its own page, so the cursor is followed as many
    // times as there are registrations. This is the walk that proves the cursor is being followed.
    $one = iterator_to_array($cloudflare->registrations()->each($zone->accountId, 1), false);

    // And again at the maximum, which is normally a single page.
    $fifty = $cloudflare->registrations()->all($zone->accountId);
} catch (NotPermittedException $e) {
    $io->error('✗ refused: this token lacks Account / Registrar: Domains / Read');
    $io->value('message', $e->messages()[0] ?? $e->getMessage());

    exit(1);
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

$names = static fn (array $list): array => array_map(static fn (Registration $r): string => $r->domainName, $list);

$io->values([
    'walked at per_page=1' => count($one),
    'walked at per_page=50' => count($fifty),
    'same domains, same order' => $names($one) === $names($fifty) ? 'yes' : 'NO',
]);

if (count($one) !== count($fifty) || $names($one) !== $names($fifty)) {
    $io->error('✗ the two walks disagree - one of them did not reach every page');

    exit(1);
}

$io->success(sprintf('✓ the cursor walk reached all %d registrations one page at a time', count($one)));
$io->line();

$statuses = array_count_values(array_map(static fn (Registration $r): string => $r->status ?? '(none)', $fifty));
$privacy = array_count_values(array_map(static fn (Registration $r): string => $r->privacyMode ?? '(none)', $fifty));

$io->values([
    'status' => json_encode($statuses),
    'privacy_mode' => json_encode($privacy),
]);
$io->line();

$report = [
    'expiring within 90 days' => array_filter($fifty, static fn (Registration $r): bool => $r->expiresWithinDays(90)),
    'auto-renew off' => array_filter($fifty, static fn (Registration $r): bool => $r->lapsesWithoutAction()),
    'not locked' => array_filter($fifty, static fn (Registration $r): bool => $r->locked === false),
];

foreach ($report as $label => $matches) {
    if ($matches === []) {
        $io->info(sprintf('%s: none', $label));

        continue;
    }

    $io->warn(sprintf('%s: %d', $label, count($matches)));

    foreach ($matches as $registration) {
        $io->line(sprintf('    %-34s expires %s', $registration->domainName, $registration->expiresAt?->format('Y-m-d') ?? '?'));
    }
}
