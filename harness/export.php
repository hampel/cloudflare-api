<?php

/**
 * Exercise: the zone as Cloudflare actually serves it, as a BIND file. Read-only.
 *
 * The authoritative answer to "what did that change really do", and the thing to diff before
 * and after a bulk edit:
 *
 *     vendor/bin/rig export > before.zone
 *     ... make the change ...
 *     vendor/bin/rig export > after.zone
 *     diff before.zone after.zone
 *
 * It is also the one endpoint in this package whose success is not JSON - it answers
 * text/plain - which is why Connection has a raw() alongside get(). Everywhere else a
 * non-JSON 200 is a maintenance page and gets refused.
 *
 * Worth comparing with the record list: the export carries the SOA and the Cloudflare
 * nameserver records, which are generated and appear nowhere in the API.
 *
 * Needs CLOUDFLARE_TOKEN and CLOUDFLARE_DOMAIN.
 *
 * @var Hampel\Rig\Io $io
 */

require __DIR__ . '/lib/client.php';

$io->title('cloudflare · export the zone file');

$cloudflare = harness_client($io);
$zone = harness_zone($io, $cloudflare);

$zoneFile = $cloudflare->zones()->records($zone->id)->export();

$lines = array_values(array_filter(
    explode("\n", trim($zoneFile)),
    static fn (string $line): bool => trim($line) !== ''
));

$io->success(sprintf('✓ %s exported, %d lines', $zone->name, count($lines)));
$io->line();

foreach ($lines as $line) {
    $io->line('    ' . $line);
}

$io->line();

$records = count($cloudflare->zones()->records($zone->id)->all());
$comments = count(array_filter($lines, static fn (string $line): bool => str_starts_with(trim($line), ';')));

$io->values([
    'lines in the export' => (string) count($lines),
    'of which comments' => (string) $comments,
    'records via the API' => (string) $records,
]);

$io->line();
$io->info('The two counts differ, and that is the point: the SOA and Cloudflare\'s own NS records');
$io->info('are generated. They are served, they are in this file, and no API call lists them.');
