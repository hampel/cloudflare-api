<?php

/**
 * Exercise: find a zone by name, and show what the API says about it. Read-only.
 *
 * The call every job starts with, because the API works in zone ids and you have a domain
 * name. It also prints the two things that decide whether DNS changes will actually do
 * anything - the status and the nameservers - which no amount of successful record writing
 * will tell you.
 *
 * Needs CLOUDFLARE_TOKEN and CLOUDFLARE_DOMAIN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Cloudflare\Api\Enum\RecordType;

require __DIR__ . '/lib/client.php';

$io->title('cloudflare · zones');

$cloudflare = harness_client($io);
$zone = harness_zone($io, $cloudflare);

$io->success(sprintf('✓ found %s', $zone->name));
$io->line();

$io->values([
    'zone id' => $zone->id,
    'status' => $zone->status?->value ?? '(unmapped)',
    'type' => $zone->type?->value ?? '(unmapped)',
    'paused' => $zone->isPaused() ? 'yes - the whole zone is served DNS-only' : 'no',
    'account' => sprintf('%s (%s)', $zone->accountName ?? '?', $zone->accountId ?? '?'),
    'plan' => $zone->planName ?? '(not reported)',
    'created' => $zone->createdOn?->format('Y-m-d') ?? '?',
    'activated' => $zone->activatedOn?->format('Y-m-d') ?? 'never',
]);

$io->line();
$io->value('Cloudflare nameservers', implode(', ', $zone->nameServers) ?: '(none)');
$io->value('originally', implode(', ', $zone->originalNameServers) ?: '(not recorded)');

if (!$zone->isActive()) {
    $io->line();
    $io->warn(sprintf('This zone is %s, not active.', $zone->status?->value ?? 'in an unmapped state'));
    $io->warn('Every DNS change below would be accepted and none of it would resolve - the domain\'s');
    $io->warn('registrar still points somewhere other than the nameservers above.');
}

$io->line();

// fqdn() is the bridge between how people write names and how this API needs them.
$io->info('Record names on this API are absolute. Zone::fqdn() builds one from a label:');

foreach (['', 'www', 'a.b', $zone->name] as $label) {
    $io->line(sprintf('    %-22s -> %s', $label === '' ? '(apex)' : $label, $zone->fqdn($label)));
}

$io->line();

// A count per type, which is the cheapest useful picture of a zone.
$counts = [];

foreach ($cloudflare->zones()->records($zone->id)->each() as $record) {
    $counts[$record->type->value] = ($counts[$record->type->value] ?? 0) + 1;
}

if ($counts === []) {
    $io->warn('No records at all. Note that the SOA and Cloudflare\'s own nameserver records are');
    $io->warn('generated and served without appearing here, so an empty list is not an empty zone.');
} else {
    ksort($counts);

    $io->success(sprintf('%d records, by type:', array_sum($counts)));

    foreach ($counts as $type => $count) {
        $io->line(sprintf('    %-8s %d%s', $type, $count, RecordType::tryFrom($type)?->usesData() ? '  (data type)' : ''));
    }
}
