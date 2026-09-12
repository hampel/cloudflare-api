<?php

/**
 * Exercise: what the failures actually look like. Read-only, and every request here is
 * MEANT to fail.
 *
 * A client's error handling is the part least likely to be exercised by ordinary use and
 * most likely to matter when it is. This drives each branch against the live API and prints
 * what came back, so the exception mapping is checked against Cloudflare's real behaviour
 * rather than against what the specification says it should be.
 *
 * IT DRIVES BOTH SHAPES OF BAD CREDENTIAL, and that is not padding. Cloudflare refuses a token
 * two ways - `401` with code 1000 for one of the right shape and the wrong value, `400` with
 * code 6003 for one it cannot parse - and this exercise tested only the first until 0.1.1. The
 * bogus token was forty zeros, which is well formed, so the 400 path never ran here and the
 * mapping for it shipped wrong. A fixture chosen to look realistic is why the realistic failure
 * went unseen.
 *
 * Both must report NotAuthenticatedException. A ValidationException on the second is the 0.1.0
 * defect returning.
 *
 * Needs CLOUDFLARE_TOKEN. Nothing is written.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Exception\ApiException;
use Hampel\Cloudflare\Api\Exception\ExceptionInterface;
use Hampel\Cloudflare\Api\Exception\RequestException;
use Hampel\Cloudflare\Api\Config;

require __DIR__ . '/lib/client.php';

$io->title('cloudflare · what the failures look like');

$cloudflare = harness_client($io);

/**
 * Run something that should fail, and report what did.
 */
$show = static function (string $what, callable $callback) use ($io): void {
    $io->line();
    $io->info($what);

    try {
        $callback();

        $io->warn('    ... it SUCCEEDED. That is worth knowing about - this exercise expects a failure.');

        return;
    } catch (ApiException $e) {
        $io->line(sprintf('    %-28s %s', 'exception', $e::class));
        $io->line(sprintf('    %-28s %d', 'HTTP status', $e->statusCode));
        $io->line(sprintf('    %-28s %s', 'codes', implode(', ', array_map('strval', $e->codes())) ?: '(none)'));

        foreach ($e->errors as $error) {
            $io->line(sprintf('    %-28s %s', 'error', $error->describe()));
        }

        if ($e->fieldErrors() !== []) {
            $io->line(sprintf('    %-28s %s', 'fields named', implode(', ', array_keys($e->fieldErrors()))));
        }

        if ($e->retryAfter !== null) {
            $io->line(sprintf('    %-28s %ds', 'Retry-After', $e->retryAfter));
        }
    } catch (ExceptionInterface $e) {
        $io->line(sprintf('    %-28s %s', 'exception', $e::class));
        $io->line(sprintf('    %-28s %s', 'message', $e->getMessage()));
    }
};

// A credential that is not one. This is the line that settles whether Cloudflare answers a
// bad token with a 401 or with a 200 whose body says success: false - the package handles
// both, and only a run says which actually happens.
// Well formed - right length, right charset - and not a real token. Answers 401 / 1000.
$show('A token of the right shape and the wrong value:', static function () use ($cloudflare): void {
    $cloudflare->withCredential(new ApiToken(str_repeat('0', 40)))->verify();
});

// Not a token at all, which is what a placeholder left in config, a truncated value or a stray
// `Bearer ` prefix looks like. Refused before authentication runs, so it answers 400 / 6003 -
// and must still arrive as NotAuthenticatedException.
$show('A token Cloudflare cannot parse at all:', static function () use ($cloudflare): void {
    $cloudflare->withCredential(new ApiToken('MY-API-TOKEN'))->verify();
});

// A path that does not exist - the documented example of error code 7003.
$show('A path the API does not have:', static function () use ($cloudflare): void {
    $cloudflare->connection()->get('zones/nonexistent-zone-id/there-is-no-such-endpoint');
});

// A zone id of the right shape that is not a zone.
$show('A zone id that is well-formed and wrong:', static function () use ($cloudflare): void {
    $cloudflare->zones()->get('00000000000000000000000000000000');
});

// A value the API validates. A TTL of 5 is out of range; the interesting part is whether the
// error carries a source pointer naming the field.
$show('A record with a TTL Cloudflare refuses:', static function () use ($cloudflare, $io): void {
    $zone = $cloudflare->zones()->list(1, 5)->items[0] ?? null;

    if ($zone === null) {
        $io->line('    (skipped - this token can see no zones)');

        return;
    }

    // Deliberately bypasses DnsRecord, which would refuse this locally - the point here is
    // to see the API's own rejection.
    $cloudflare->records()->create($zone->id, [
        'type' => 'A',
        'name' => 'zz-this-will-never-be-created.' . $zone->name,
        'content' => '203.0.113.10',
        'ttl' => 5,
    ]);
});

// The transport, rather than the API. Nothing answers on this port.
$show('Nothing listening at the other end:', static function () use ($cloudflare): void {
    $offline = new Hampel\Cloudflare\Api\Client(
        new Config('http://127.0.0.1:9'),
        $cloudflare->authentication(),
        new GuzzleHttp\Client(['timeout' => 3]),
        new GuzzleHttp\Psr7\HttpFactory(),
        new GuzzleHttp\Psr7\HttpFactory()
    );

    $offline->zones()->list();
});

$io->line();
$io->info('The last one is a RequestException and not an ApiException, deliberately: nothing');
$io->info('answered, so there is no status to interpret and a retry is reasonable in a way it is');
$io->info('not for any of the others.');
$io->line();
$io->info(sprintf('RequestException extends %s', get_parent_class(RequestException::class) ?: '?'));
