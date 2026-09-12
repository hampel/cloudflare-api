<?php

/**
 * Exercise: the full record lifecycle against a real zone - create, read, patch, replace,
 * delete.
 *
 * THIS ONE WRITES TO REAL DNS, and that is the whole point of it: DNS:Edit cannot be checked
 * without writing something, and the difference between PATCH and PUT on this API cannot be
 * demonstrated by a mocked test at all, because what makes PUT dangerous is what the SERVER
 * does with the fields you left out.
 *
 * WHAT IT DOES. One TXT record, named zz-delete-me-cloudflare-api-harness-<timestamp> in
 * CLOUDFLARE_DOMAIN. It is created, read back, patched, replaced, and deleted in a `finally`
 * so it goes even if a step fails. A record in a live zone is served to the whole internet
 * for as long as it exists, so "safe" here means "does not break the zone", not "invisible".
 * TXT, and a name nothing resolves, so nothing routes differently while it is there.
 *
 * HOW TO RUN IT. Two opt-ins, and they are not the same switch:
 *
 *     CLOUDFLARE_WRITE_RECORDS=yes              may write to real DNS - fine in .env
 *     CLOUDFLARE_AGENT_MAY_WRITE_RECORDS=1      ...even in an agent session - command line only
 *
 * The second exists because the first lives in the .env of whoever owns the credentials and
 * generally says yes, so an agent would inherit an authorisation it never made. See
 * lib/agent.php.
 *
 * Needs CLOUDFLARE_TOKEN with DNS:Edit, and CLOUDFLARE_DOMAIN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Cloudflare\Api\Entity\DnsRecord;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;
use Hampel\Cloudflare\Api\Support\Ttl;

require __DIR__ . '/lib/client.php';
require __DIR__ . '/lib/agent.php';

$io->title('cloudflare · the record lifecycle');

if (getenv('CLOUDFLARE_WRITE_RECORDS') !== 'yes') {
    $io->warn('This exercise writes a real DNS record to a real zone, so it is opt-in.');
    $io->info('Set CLOUDFLARE_WRITE_RECORDS=yes once you have read what it does - see the header of');
    $io->info('this file. Everything else in this harness is read-only.');

    exit(0);
}

if (harness_agent_refuses('CLOUDFLARE_AGENT_MAY_WRITE_RECORDS')) {
    $io->error('Refused: an agent is running and CLOUDFLARE_AGENT_MAY_WRITE_RECORDS is not set.');
    $io->info('CLOUDFLARE_WRITE_RECORDS in .env is the credential owner\'s standing decision, not');
    $io->info('this session\'s. If writing a throwaway TXT record to a live zone is wanted here,');
    $io->info('ask - and it goes on the command line for one run, never into .env.');

    exit(1);
}

$cloudflare = harness_client($io);
$zone = harness_zone($io, $cloudflare);
$records = $cloudflare->zones()->records($zone->id);

$name = $zone->fqdn('zz-delete-me-cloudflare-api-harness-' . time());

$io->value('zone', sprintf('%s (%s)', $zone->name, $zone->id));
$io->value('record', $name);

if (!$zone->isActive()) {
    $io->warn('The zone is not active, so none of this will resolve. The API calls still exercise.');
}

$io->line();

$created = null;

try {
    $io->info('1. create');

    try {
        $created = $records->create(
            DnsRecord::txt($name, 'created by the hampel/cloudflare-api harness')
                ->withTtl(120)
                ->withComment('throwaway - safe to delete')
                ->withTags(['harness'])
        );
    } catch (NotPermittedException $e) {
        $io->error('    refused: this token cannot write DNS. It needs DNS:Edit on this zone.');
        $io->value('    message', $e->getMessage());

        exit(1);
    }

    $io->line(sprintf('    %-16s %s', 'id', $created->id ?? '?'));
    $io->line(sprintf('    %-16s %s', 'ttl', Ttl::describe($created->ttl ?? Ttl::AUTOMATIC)));
    $io->line(sprintf('    %-16s %s', 'comment', $created->comment ?? '(none)'));
    $io->line(sprintf('    %-16s %s', 'tags', implode(', ', $created->tags) ?: '(none)'));

    $recordId = $created->id;

    if ($recordId === null) {
        $io->error('    the API returned no id, so there is nothing to work with.');

        exit(1);
    }

    $io->line();
    $io->info('2. read it back');

    $fetched = $records->get($recordId);
    $io->line(sprintf('    %s', $fetched->describe()));

    // Cloudflare stores TXT content as quoted character strings and splits anything over 255
    // bytes, so what comes back need not be byte-identical to what went out.
    $io->line(sprintf('    %-16s %s', 'content sent', 'created by the hampel/cloudflare-api harness'));
    $io->line(sprintf('    %-16s %s', 'content stored', (string) $fetched->content));

    $io->line();
    $io->info('3. patch - changes the TTL and leaves everything else alone');

    $patched = $records->patch($recordId, ['ttl' => 300]);

    $io->line(sprintf('    %-16s %s', 'ttl', Ttl::describe($patched->ttl ?? Ttl::AUTOMATIC)));
    $io->line(sprintf('    %-16s %s', 'comment', $patched->comment ?? '(GONE)'));
    $io->line(sprintf('    %-16s %s', 'tags', implode(', ', $patched->tags) ?: '(GONE)'));

    if ($patched->comment !== null && $patched->tags !== []) {
        $io->success('    the comment and tags survived, which is what PATCH promises');
    } else {
        $io->warn('    the comment or tags did NOT survive a PATCH - that would contradict the docs');
    }

    $io->line();
    $io->info('4. replace - a PUT carrying only type, name and content');

    // THE DEMONSTRATION. Nothing about this call mentions the comment, the tags or the TTL.
    // A partial update would leave all three; a replacement resets them, silently, with a 200.
    $replaced = $records->replace($recordId, [
        'type' => 'TXT',
        'name' => $name,
        'content' => 'replaced by the harness',
    ]);

    $io->line(sprintf('    %-16s %s', 'content', (string) $replaced->content));
    $io->line(sprintf('    %-16s %s', 'ttl', Ttl::describe($replaced->ttl ?? Ttl::AUTOMATIC)));
    $io->line(sprintf('    %-16s %s', 'comment', $replaced->comment ?? '(GONE)'));
    $io->line(sprintf('    %-16s %s', 'tags', implode(', ', $replaced->tags) ?: '(GONE)'));

    if ($replaced->comment === null && $replaced->tags === []) {
        $io->success('    the comment and tags are gone - nothing in the payload mentioned them, and');
        $io->success('    the call answered 200. This is why replace() is not the default update.');
    } else {
        $io->warn('    they survived a PUT, which contradicts the documented behaviour. Worth chasing.');
    }
} finally {
    $io->line();
    $io->info('5. delete');

    if ($created?->id === null) {
        $io->line('    nothing was created, so there is nothing to remove.');
    } else {
        try {
            $io->line(sprintf('    removed %s', $records->delete($created->id)));
        } catch (Throwable $e) {
            $io->error(sprintf('    COULD NOT DELETE %s - remove it by hand.', $name));
            $io->value('    message', $e->getMessage());
        }
    }
}
