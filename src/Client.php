<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api;

use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Authentication\Authentication;
use Hampel\Cloudflare\Api\Endpoint\Accounts;
use Hampel\Cloudflare\Api\Endpoint\DnsRecords;
use Hampel\Cloudflare\Api\Endpoint\Endpoint;
use Hampel\Cloudflare\Api\Endpoint\Tokens;
use Hampel\Cloudflare\Api\Endpoint\Zones;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Result\TokenVerification;
use Hampel\Cloudflare\Api\Support\Psr17Discovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The entry point. Hand it a token and any PSR-18 client:
 *
 *     $guzzle  = new GuzzleHttp\Client();
 *     $factory = new GuzzleHttp\Psr7\HttpFactory();   // PSR-17, both roles
 *
 *     $cloudflare = new Client(new Config(), new ApiToken($token), $guzzle, $factory, $factory);
 *
 *     $cloudflare->verify();                          // does this token work?
 *     $zone = $cloudflare->zones()->getByName('example.com');
 *     $cloudflare->zones()->records($zone->id)->all();
 *
 * Or, for the ordinary case where the answer to every construction question is the default:
 *
 *     $cloudflare = Client::withToken($token, $guzzle);
 *
 * EXTENDING IT. This package wraps the endpoints that manage DNS and identify a token, which
 * is a dozen of Cloudflare's two thousand. The rest are reachable without waiting for a
 * release, and in two ways:
 *
 *     $cloudflare->connection()->get('zones/' . $id . '/settings/ssl')->object();   // once
 *     $cloudflare->endpoint(Firewall::class)->rules($id);                          // more than once
 *
 * The second is the one to build on - see Endpoint.
 */
final class Client
{
    private readonly Connection $connection;

    /** @var array<class-string<Endpoint>, Endpoint> */
    private array $endpoints = [];

    /**
     * @param  RequestFactoryInterface|null  $requestFactory  PSR-17. Leave both null and the
     *         package finds one - Guzzle's, Nyholm's or Diactoros', whichever is installed;
     *         see Psr17Discovery. Pass them to choose, or when none of those is present.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($requestFactory === null || $streamFactory === null) {
            [$foundRequest, $foundStream] = Psr17Discovery::find();

            $requestFactory ??= $foundRequest;
            $streamFactory ??= $foundStream;
        }

        $this->connection = new Connection(
            $this->config,
            $this->authentication,
            $client,
            $requestFactory,
            $streamFactory,
            $this->logger
        );
    }

    /**
     * The short form: an API token, the default configuration, and a transport.
     *
     * Everything the long constructor takes is still available on it; this exists because
     * naming a Config and an ApiToken to accept both defaults is ceremony, and ceremony in an
     * example is what gets copied.
     */
    public static function withToken(
        #[\SensitiveParameter] string $token,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            new Config(),
            new ApiToken($token),
            $client,
            $requestFactory,
            $streamFactory,
            $logger ?? new NullLogger()
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }

    /**
     * Does this token work - in one request.
     *
     * The call to make at startup, before anything that matters. Raises
     * NotAuthenticatedException for a token that is not valid.
     *
     * IT CANNOT TELL YOU WHAT THE TOKEN MAY DO, because Cloudflare does not report a token's
     * permissions anywhere. A verification that passes says the credential is real and live,
     * and says nothing about whether it can read a zone - see TokenVerification.
     */
    public function verify(): TokenVerification
    {
        return $this->tokens()->verify();
    }

    /**
     * The same configuration and transport, under a different credential.
     *
     * A new client rather than a mutation, so two credentials in one long-running process - a
     * job that walks several accounts - cannot leak into each other's requests. Endpoints are
     * not carried over: they hold the connection this one is replacing.
     */
    public function withCredential(Authentication $authentication): self
    {
        return new self(
            $this->config,
            $authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger
        );
    }

    /**
     * Any Endpoint subclass, constructed and memoised.
     *
     * This is the extension point. A third-party package ships an Endpoint subclass for the
     * endpoints it needs, a consumer names the class, and static analysis follows the return
     * type through - there is nothing to register, no container and no string keys. The named
     * accessors below are the same mechanism with a shorter name.
     *
     * @template T of Endpoint
     * @param  class-string<T>  $class
     * @return T
     */
    public function endpoint(string $class): Endpoint
    {
        if (!isset($this->endpoints[$class])) {
            // Both halves earn their place. The first catches a class that is not an Endpoint
            // at all, which static analysis already rejects but a caller without it can still
            // write. The second catches Endpoint itself and any abstract subclass - both of
            // which satisfy class-string<Endpoint>, so nothing but this stands between them
            // and a fatal error on `new`.
            if (!is_subclass_of($class, Endpoint::class) || !(new \ReflectionClass($class))->isInstantiable()) {
                throw new InvalidArgumentException(sprintf(
                    '%s cannot be constructed as an API endpoint: it must be a concrete subclass of %s.',
                    $class,
                    Endpoint::class
                ));
            }

            $this->endpoints[$class] = new $class($this->connection, $this->logger);
        }

        /** @var T $endpoint */
        $endpoint = $this->endpoints[$class];

        return $endpoint;
    }

    /**
     * The credential, and whether it is live.
     */
    public function tokens(): Tokens
    {
        return $this->endpoint(Tokens::class);
    }

    /**
     * Zones - read-only. The way from a domain name to the id everything else needs.
     */
    public function zones(): Zones
    {
        return $this->endpoint(Zones::class);
    }

    /**
     * DNS records, with the zone id as the first argument to every call.
     *
     * For several calls against one zone, `zones()->records($zoneId)` binds it once and drops
     * the argument - which is the form to prefer, since the id is a 32-character hex string
     * rather than a name and repeating it by hand is how the fourth line addresses the wrong
     * zone.
     */
    public function records(): DnsRecords
    {
        return $this->endpoint(DnsRecords::class);
    }

    /**
     * The accounts this token can see - a diagnostic rather than routine work, and refused
     * outright for a token scoped to DNS. See Accounts.
     */
    public function accounts(): Accounts
    {
        return $this->endpoint(Accounts::class);
    }

    /**
     * For an endpoint nothing here wraps - which is most of this API. Call it directly rather
     * than waiting for a version of this package.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }
}
