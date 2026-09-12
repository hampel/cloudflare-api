<?php

/**
 * Exercise: prove the token works, and find out what it actually reaches. Read-only.
 *
 * THE FIRST THING TO RUN AGAINST A NEW CREDENTIAL. It also settles two things this package
 * could not verify from the specification, because both need a real token:
 *
 *   - the RATE LIMIT HEADERS. `Ratelimit` and `Ratelimit-Policy` are documented; whether
 *     they appear on an ordinary response was never measured. The raw values are printed
 *     below, so the first real run answers it.
 *   - WHAT A TOKEN CAN REACH. Cloudflare publishes a token's permissions nowhere, so this
 *     asks instead: it tries the accounts list and the zones list and reports each. That is
 *     the closest thing to a capability report this API allows.
 *
 * Needs CLOUDFLARE_TOKEN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Cloudflare\Api\Entity\Zone;
use Hampel\Cloudflare\Api\Exception\ExceptionInterface;
use Hampel\Cloudflare\Api\Exception\NotAuthenticatedException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;

require __DIR__ . '/lib/client.php';

$io->title('cloudflare · verify the token');

$cloudflare = harness_client($io);

try {
    $token = $cloudflare->verify();
} catch (NotAuthenticatedException $e) {
    $io->error('✗ the token is not valid');
    $io->value('message', $e->getMessage());
    $io->info('Cloudflare answers the same for a missing, wrong, revoked or malformed token, so');
    $io->info('there is nothing in the reply to say which of the four this is.');

    exit(1);
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

$io->success('✓ the token works');
$io->line();

$io->values([
    'token id' => $token->id === '' ? '(not reported)' : $token->id,
    'status' => $token->status?->value ?? ('unmapped: ' . $token->rawStatus),
    'expires' => $token->expiresOn?->format('Y-m-d H:i:s \U\T\C') ?? 'never',
    'not before' => $token->notBefore?->format('Y-m-d H:i:s \U\T\C') ?? '(none)',
]);

if (!$token->isActive()) {
    $io->warn('The token verified but is not active. It will be refused by everything else.');
}

if ($token->expiresWithinDays(30)) {
    $io->warn('This token expires within 30 days.');
}

$io->line();

// The unsettled question. ResponseMeta parses these headers from their documented form;
// these two lines are what say whether they are sent at all.
$meta = $token->meta;

$io->values([
    'Ratelimit (raw)' => $meta->rateLimitHeader === '' ? '(header absent)' : $meta->rateLimitHeader,
    'Ratelimit-Policy (raw)' => $meta->rateLimitPolicyHeader === '' ? '(header absent)' : $meta->rateLimitPolicyHeader,
    'parsed as' => sprintf(
        '%s remaining of %s, resets in %s',
        $meta->rateLimitRemaining ?? '?',
        $meta->rateLimit ?? '?',
        $meta->rateLimitResetsIn === null ? '?' : $meta->rateLimitResetsIn . 's'
    ),
    'Retry-After' => $meta->retryAfter === null ? '(absent, as documented on a success)' : (string) $meta->retryAfter,
    'CF-Ray' => $meta->ray ?? '(absent)',
]);

$io->line();
$io->info('Cloudflare reports a token\'s permissions nowhere, so what follows is measured by');
$io->info('trying rather than read from a header.');
$io->line();

// Accounts: a zone-scoped token is refused here, and that is correct rather than a problem.
try {
    $accounts = $cloudflare->accounts()->list(1, 5);

    $io->success(sprintf('Account Settings:Read — yes (%d visible)', $accounts->total()));

    foreach ($accounts->items as $account) {
        $io->line(sprintf('    %-34s %s', $account->name, $account->id));
    }
} catch (NotPermittedException) {
    $io->info('Account Settings:Read — no. Correct for a token that only manages DNS.');
}

$io->line();

// Zones: the one this package actually needs.
try {
    $zones = $cloudflare->zones()->list(1, 5);

    $io->success(sprintf('Zone:Read — yes (%d visible)', $zones->total()));

    foreach ($zones->items as $zone) {
        $io->line(sprintf(
            '    %-28s %s  %s%s',
            $zone->name,
            $zone->id,
            $zone->status?->value ?? '?',
            $zone->isPaused() ? ' (paused)' : ''
        ));
    }

    if ($zones->total() > count($zones->items)) {
        $io->line(sprintf('    ... and %d more', $zones->total() - count($zones->items)));
    }

    $pending = array_filter($zones->items, static fn (Zone $zone): bool => $zone->isPending());

    if ($pending !== []) {
        $io->line();
        $io->warn('Some zones are pending: they accept every DNS change and serve none of them,');
        $io->warn('because their nameservers still point somewhere other than Cloudflare.');
    }
} catch (NotPermittedException) {
    $io->error('Zone:Read — no. This token cannot list zones, so nothing else in this package will work.');

    exit(1);
}

$io->line();
$io->info('DNS:Edit cannot be checked without writing something. The `records` exercise is that check.');
