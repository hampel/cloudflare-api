<?php

/**
 * Exercise: how a TXT value comes back after a write, and whether comparableContent() matches it
 * to what was sent. WRITES FOUR THROWAWAY RECORDS TO REAL DNS, and deletes them.
 *
 * THE QUESTIONS. The schema says TXT content is quoted character strings of at most 255 bytes,
 * and that longer strings "are automatically split". A consumer reading its own zones found a
 * 425-byte value returned as two quoted strings, a 410-byte value returned unquoted, and a short
 * value returned in quotes. So, for a value written through this package:
 *
 *   1. does a short unquoted value come back byte-identical?
 *   2. does a 400-byte unquoted value come back split, and quoted?
 *   3. does the same 400 bytes sent already split come back as it went?
 *   4. does a short value sent in quotes keep them?
 *
 * and for every one, does comparableContent() on what came back equal comparableTxt() on what
 * went out. The last is the point: string equality is expected to fail on some of these, and the
 * helper is expected not to.
 *
 * WHAT IT WRITES. Four TXT records named zz-delete-me-cloudflare-api-harness-txt-<n>-<timestamp>
 * in CLOUDFLARE_DOMAIN, each deleted in a `finally`. TXT, at names nothing looks up.
 *
 * HOW TO RUN IT. The same two opt-ins as `records`:
 *
 *     CLOUDFLARE_WRITE_RECORDS=yes              may write to real DNS - fine in .env
 *     CLOUDFLARE_AGENT_MAY_WRITE_RECORDS=1      ...even in an agent session - command line only
 *
 * Needs CLOUDFLARE_TOKEN with DNS:Edit, and CLOUDFLARE_DOMAIN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/client.php';
require __DIR__ . '/lib/agent.php';

$io->title('cloudflare · a TXT value, there and back');

if (getenv('CLOUDFLARE_WRITE_RECORDS') !== 'yes') {
    $io->warn('mode: nothing written - CLOUDFLARE_WRITE_RECORDS is not "yes".');
    $io->info('This exercise writes four TXT records to a real zone and deletes them. Read the');
    $io->info('header of this file, then set CLOUDFLARE_WRITE_RECORDS=yes to run it.');

    exit(0);
}

if (harness_agent_refuses('CLOUDFLARE_AGENT_MAY_WRITE_RECORDS')) {
    $io->error('mode: refused - an agent is running and CLOUDFLARE_AGENT_MAY_WRITE_RECORDS is not set.');
    $io->info('If writing throwaway TXT records to a live zone is wanted here, ask - and it goes on');
    $io->info('the command line for one run, never into .env.');

    exit(1);
}

$cloudflare = harness_client($io);
$zone = harness_zone($io, $cloudflare);
$records = $cloudflare->zones()->records($zone->id);

$io->warn(sprintf('mode: WRITING to %s - four TXT records, each deleted afterwards', $zone->name));
$io->line();

// Base64-shaped, like the DKIM key this is about, and exactly 400 bytes.
$long = substr(str_repeat('MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA', 10), 0, 400);

$probes = [
    'short, unquoted' => 'hampel/cloudflare-api harness short value',
    '400 bytes, unquoted' => $long,
    '400 bytes, sent split' => '"' . substr($long, 0, 255) . '" "' . substr($long, 255) . '"',
    'short, sent quoted' => '"hampel/cloudflare-api harness quoted value"',
];

$stamp = time();
$created = [];
$failure = null;
$leaked = false;

try {
    $n = 0;

    foreach ($probes as $label => $sent) {
        $n++;
        $name = $zone->fqdn(sprintf('zz-delete-me-cloudflare-api-harness-txt-%d-%d', $n, $stamp));

        $io->info(sprintf('%d. %s', $n, $label));

        $record = $records->create(DnsRecord::txt($name, $sent)->withTtl(120));
        $created[$name] = $record->id;

        $fetched = $record->id !== null ? $records->get($record->id) : $record;
        $stored = (string) $fetched->content;

        $io->values([
            '    bytes sent' => strlen($sent),
            '    bytes stored' => strlen($stored),
            '    stored starts' => substr($stored, 0, 24) . (strlen($stored) > 24 ? '...' : ''),
            '    quoted strings' => substr_count($stored, '" "') + (str_starts_with($stored, '"') ? 1 : 0),
            '    create = get' => ($record->content === $fetched->content) ? 'yes' : 'NO',
            '    sent === stored' => $sent === $stored ? 'yes' : 'no',
            '    comparable equal' => DnsRecord::comparableTxt($sent) === $fetched->comparableContent() ? 'yes' : 'NO',
        ]);

        $io->line();
    }
} catch (ExceptionInterface $e) {
    $failure = $e;
    $io->error(sprintf('failed: %s - %s', $e::class, $e->getMessage()));
} finally {
    $io->info('cleanup');

    foreach ($created as $name => $id) {
        if ($id === null) {
            $leaked = true;
            $io->error(sprintf('    %s came back with no id - remove it by hand.', $name));

            continue;
        }

        try {
            $records->delete($id);
            $io->line(sprintf('    deleted %s', $name));
        } catch (ExceptionInterface $cleanup) {
            $leaked = true;
            $io->error(sprintf('    CLEANUP FAILED for %s (%s) - %s', $name, $id, $cleanup::class));
        }
    }
}

if ($failure !== null || $leaked) {
    exit(1);
}
