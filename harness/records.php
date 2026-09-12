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
 * IT HAS BEEN RUN. Against a live Free-plan zone on 2026-09-12, and it settled the
 * question: the PATCH left the comment alone, and the PUT - carrying only type, name and
 * content - cleared the comment and returned the TTL to automatic, with a 200 and no mention
 * of either. It also turned up that tags need a paid plan.
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
use Hampel\Cloudflare\Api\Exception\ApiException;
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

    // NO TAGS HERE, and that is a finding rather than an oversight: tags are a paid feature.
    // On a Free zone the quota is zero and a create carrying one is refused with
    // `400 / 9300 - DNS record has 1 tags, exceeding the quota of 0`. Step 2 asks for one
    // separately, so a plan that allows them still gets exercised and one that does not
    // reports the fact instead of failing the run.
    try {
        $created = $records->create(
            DnsRecord::txt($name, 'created by the hampel/cloudflare-api harness')
                ->withTtl(120)
                ->withComment('throwaway - safe to delete')
        );
    } catch (NotPermittedException $e) {
        $io->error('    refused: this token cannot write DNS. It needs DNS:Edit on this zone.');
        $io->value('    message', $e->getMessage());

        exit(1);
    } catch (ApiException $e) {
        $io->error('    the create was rejected, so there is nothing to exercise.');
        $io->value('    status', (string) $e->statusCode);
        $io->value('    codes', implode(', ', array_map('strval', $e->codes())));
        $io->value('    message', implode('; ', $e->messages()));

        exit(1);
    }

    $io->line(sprintf('    %-16s %s', 'id', $created->id ?? '?'));
    $io->line(sprintf('    %-16s %s', 'ttl', Ttl::describe($created->ttl ?? Ttl::AUTOMATIC)));
    $io->line(sprintf('    %-16s %s', 'comment', $created->comment ?? '(none)'));

    $recordId = $created->id;

    if ($recordId === null) {
        $io->error('    the API returned no id, so there is nothing to work with.');

        exit(1);
    }

    $io->line();
    $io->info('2. tags - a paid feature, so this is allowed to fail');

    if ($created->id !== null) {
        try {
            $tagged = $records->patch($created->id, ['tags' => ['harness']]);
            $io->success(sprintf('    tags accepted: %s', implode(', ', $tagged->tags) ?: '(none returned)'));
        } catch (ApiException $e) {
            $io->info(sprintf(
                '    refused (%d / %s): %s',
                $e->statusCode,
                implode(',', array_map('strval', $e->codes())),
                implode('; ', $e->messages())
            ));
            $io->info('    That is the zone\'s plan, not the token or the package.');
        }
    }

    $io->line();
    $io->info('3. read it back');

    $fetched = $records->get($recordId);
    $io->line(sprintf('    %s', $fetched->describe()));

    // Cloudflare stores TXT content as quoted character strings and splits anything over 255
    // bytes, so what comes back need not be byte-identical to what went out.
    $io->line(sprintf('    %-16s %s', 'content sent', 'created by the hampel/cloudflare-api harness'));
    $io->line(sprintf('    %-16s %s', 'content stored', (string) $fetched->content));

    $io->line();
    $io->info('4. patch - changes the TTL and leaves everything else alone');

    $patched = $records->patch($recordId, ['ttl' => 300]);

    $io->line(sprintf('    %-16s %s', 'ttl', Ttl::describe($patched->ttl ?? Ttl::AUTOMATIC)));
    $io->line(sprintf('    %-16s %s', 'comment', $patched->comment ?? '(GONE)'));
    $io->line(sprintf('    %-16s %s', 'tags', implode(', ', $patched->tags) ?: '(GONE)'));

    if ($patched->comment !== null) {
        $io->success('    the comment survived, which is what PATCH promises');
    } else {
        $io->warn('    the comment did NOT survive a PATCH - that would contradict the docs');
    }

    $io->line();
    $io->info('5. replace - a PUT carrying only type, name and content');

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

    if ($replaced->comment === null || $replaced->comment === '') {
        $io->success('    the comment is GONE - nothing in the payload mentioned it, the TTL went back');
        $io->success('    to automatic, and the call answered 200 saying none of it. This is why');
        $io->success('    replace() is not the default update.');
    } else {
        $io->warn('    the comment survived a PUT, which contradicts the documented behaviour.');
        $io->warn('    Worth chasing - patch() and replace() may not differ as this package claims.');
    }
} finally {
    $io->line();
    $io->info('6. delete');

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
