<?php

/**
 * Not an exercise - see lib/agent.php. Builds the client every exercise needs, from the
 * environment, and fails with something legible when it cannot.
 */

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Client;
use Hampel\Cloudflare\Api\Config;
use Hampel\Rig\Io;

function harness_client(Io $io): Client
{
    $token = getenv('CLOUDFLARE_TOKEN');

    if (!is_string($token) || $token === '') {
        $io->error('CLOUDFLARE_TOKEN is not set. Copy .env.example to .env beside the package.');
        $io->error('If you are an agent and the rig said it withheld the environment file, that is the guard');
        $io->error('working - ask rather than working around it.');

        exit(1);
    }

    $factory = new HttpFactory();

    return new Client(new Config(), new ApiToken($token), new Guzzle(), $factory, $factory);
}

/**
 * The zone an exercise is pointed at, by domain name. Nothing here guesses one: an exercise
 * that picked "the first zone on the account" would do something different on every account
 * it ran against, and on one of them that would be the wrong domain.
 */
function harness_domain(Io $io): string
{
    $domain = getenv('CLOUDFLARE_DOMAIN');

    if (!is_string($domain) || $domain === '') {
        $io->error('CLOUDFLARE_DOMAIN is not set - name the zone this exercise should work with.');

        exit(1);
    }

    return $domain;
}

/**
 * The zone itself, resolved from the name, with the two failures that look alike told apart.
 */
function harness_zone(Io $io, Client $cloudflare): Hampel\Cloudflare\Api\Entity\Zone
{
    $domain = harness_domain($io);

    try {
        $zone = $cloudflare->zones()->findByName($domain);
    } catch (Hampel\Cloudflare\Api\Exception\NotAuthenticatedException $e) {
        // A token its IP filter refuses gets here having passed nothing - there is no verify()
        // on this path - so the message is the whole diagnosis. `rig verify` explains it.
        $io->error('The token was refused: ' . ($e->messages()[0] ?? $e->getMessage()));
        $io->info('If that names a location, the token\'s IP address filter excludes wherever this is');
        $io->info('running. Run `rig verify` for the full picture.');

        exit(1);
    }

    if ($zone === null) {
        $io->error(sprintf('No zone named "%s" is visible to this token.', $domain));
        $io->info('Either Cloudflare does not hold that domain, or the token\'s zone resources do not');
        $io->info('include it. Those look identical from here - the dashboard is what tells them apart.');

        exit(1);
    }

    return $zone;
}
