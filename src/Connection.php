<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api;

use Hampel\Cloudflare\Api\Authentication\Authentication;
use Hampel\Cloudflare\Api\Exception\ApiException;
use Hampel\Cloudflare\Api\Exception\MalformedResponseException;
use Hampel\Cloudflare\Api\Exception\RequestException;
use Hampel\Cloudflare\Api\Result\ApiResponse;
use Hampel\Cloudflare\Api\Result\ResponseMeta;
use Hampel\Cloudflare\Api\Support\Json;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything that touches HTTP, in one place.
 *
 * The client is injected as a PSR-18 ClientInterface rather than a concrete one, which is the
 * whole point of the package: a host application with its own HTTP stack - a proxy-aware,
 * SSRF-guarded client that all outbound requests are required to go through - implements
 * sendRequest() over it and shares this code, instead of writing a second API client because
 * ours hard-coded the wrong library. It is also what lets a Laravel integration route this
 * traffic through `Http::fake()`.
 *
 * A PSR-18 client does not throw on an HTTP status, only on a transport failure, so the two
 * failure modes stay cleanly separated here.
 *
 * This class is the extension point of last resort. Any of the two thousand endpoints this
 * package does not wrap can be called through it directly:
 *
 *     $cloudflare->connection()->get('zones/' . $id . '/settings/ssl')->object();
 */
final class Connection
{
    /**
     * Written without a charset parameter, and that is worth a note because it looks like an
     * omission.
     *
     * Cloudflare itself does not care - it parses the body as JSON either way. The consumer's
     * test suite does. `Illuminate\Http\Client\Request::isJson()` is a substring test and
     * survives a parameter, but the same class's `isForm()` is an exact match, and a package
     * that writes its content types loosely in one place tends to write them loosely in both.
     */
    public const JSON_CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
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
     * The injected transport, exposed rather than hidden because a caller assembling
     * something this class does not cover needs the same client and the same factories to do
     * it, rather than reaching for an HTTP library of its own.
     */
    public function client(): ClientInterface
    {
        return $this->client;
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->send($this->request('GET', $path, $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function post(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('POST', $path, $query), $payload));
    }

    /**
     * A FULL REPLACEMENT, and this is the one method on this class worth reading twice.
     *
     * Cloudflare's PUT overwrites the whole record: a field left out of the payload is not
     * left alone, it is reset to its default. So a PUT carrying only `content` silently
     * clears the record's comment and tags, un-proxies it, and returns its TTL to automatic -
     * with a 200 and no indication that anything beyond the content changed.
     *
     * That is the opposite of the behaviour several other DNS APIs give the same verb, which
     * is exactly why it is dangerous: the wrong instinct produces no error. patch() is the
     * partial update and is what almost every caller wants; this exists for the case where
     * replacing the record wholesale is the actual intention.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function put(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('PUT', $path, $query), $payload));
    }

    /**
     * A partial update: the fields in the payload change and everything else is left alone.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function patch(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('PATCH', $path, $query), $payload));
    }

    /**
     * Cloudflare answers a successful delete with the deleted object's id in `result`.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function delete(string $path, array $query = []): ApiResponse
    {
        return $this->send($this->request('DELETE', $path, $query));
    }

    /**
     * A GET whose success is NOT the JSON envelope - which on this API means the BIND zone
     * file export, and nothing else this package wraps.
     *
     * Kept separate from get() rather than folded into it with a flag, because the two have
     * opposite ideas about what a non-JSON 200 means: everywhere else it is the maintenance
     * page or proxy error that MalformedResponseException exists to catch, and here it is the
     * answer. A failure still comes back as JSON and is raised exactly as it would be
     * elsewhere.
     *
     * @param  array<string, scalar|null>  $query
     * @return array{string, ResponseMeta}  the body as it arrived, and the response metadata
     */
    public function raw(string $path, array $query = []): array
    {
        $request = $this->request('GET', $path, $query);
        $response = $this->dispatch($request);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $meta = ResponseMeta::fromResponse($response);

        if ($status >= 200 && $status < 300) {
            $this->noteRateLimit($request, $meta);

            return [$body, $meta];
        }

        $decoded = Json::decode($body);

        $this->logger->error('Cloudflare API error response', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'status' => $status,
            'body' => $decoded ?? $body,
        ]);

        throw ApiException::fromResponse(
            $request->getMethod(),
            (string) $request->getUri(),
            $response,
            $decoded,
            $body
        );
    }

    /**
     * Build a request without sending it, for a caller assembling something this class does
     * not cover. The credential and the Accept header are already applied.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function request(string $method, string $path, array $query = []): RequestInterface
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->config->resolve($path, $query))
            ->withHeader('Accept', self::JSON_CONTENT_TYPE);

        return $this->authentication->applyTo($request);
    }

    /**
     * Attach a JSON body to a request built elsewhere.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withJson(RequestInterface $request, array $payload): RequestInterface
    {
        return $request
            ->withHeader('Content-Type', self::JSON_CONTENT_TYPE)
            ->withBody($this->streamFactory->createStream(Json::encode($payload)));
    }

    /**
     * Send a request that was built elsewhere, with this connection's error handling.
     */
    public function send(RequestInterface $request): ApiResponse
    {
        $response = $this->dispatch($request);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = Json::decode($body);
        $meta = ResponseMeta::fromResponse($response);

        $succeeded = $status >= 200 && $status < 300;

        if ($succeeded && $decoded === null) {
            // A 2xx that did not decode is not an empty answer, it is somebody else's answer -
            // a maintenance page, a proxy error document, a challenge page, a truncated body.
            // Read as [] it would reach the caller as "this zone has no records", which is the
            // failure worth being loud about on an API used to manage DNS.
            //
            // A 204 is not special-cased: this API does not send one. Every endpoint answers
            // with the envelope, and a genuinely empty result is `"result": null` inside it.
            $this->logger->error('Cloudflare API answered success with a body that is not JSON', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'status' => $status,
                'content_type' => $response->getHeaderLine('Content-Type'),
            ]);

            throw MalformedResponseException::forResponse(
                $request->getMethod(),
                (string) $request->getUri(),
                $response,
                $body
            );
        }

        // THE STATUS IS NOT THE WHOLE ANSWER. This API carries its own `success` flag, and a
        // 2xx whose body says false is a real shape here - historically on endpoints that
        // report a partial failure, and on anything sitting in front of the API that answers
        // in its envelope. Trusting the status alone would hand the caller a null `result`
        // dressed as an empty collection, which is the same accident as the non-JSON body
        // above by a different route.
        if ($succeeded && ($decoded['success'] ?? null) === false) {
            $this->logger->error('Cloudflare API answered a 2xx whose body reports failure', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'status' => $status,
                'body' => $decoded,
            ]);

            throw ApiException::fromResponse(
                $request->getMethod(),
                (string) $request->getUri(),
                $response,
                $decoded,
                $body
            );
        }

        if ($succeeded) {
            $this->noteRateLimit($request, $meta);

            $messages = ApiError::listFrom($decoded, 'messages');

            if ($messages !== []) {
                $this->logger->info('Cloudflare API returned advisory messages', [
                    'uri' => (string) $request->getUri(),
                    'messages' => array_map(
                        static fn (ApiError $message): string => $message->describe(),
                        $messages
                    ),
                ]);
            }

            return new ApiResponse($decoded, $status, $meta, $messages);
        }

        $this->logger->error('Cloudflare API error response', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'status' => $status,
            'body' => $decoded ?? $body,
        ]);

        throw ApiException::fromResponse(
            $request->getMethod(),
            (string) $request->getUri(),
            $response,
            $decoded,
            $body
        );
    }

    /**
     * Log it, send it, and keep a transport failure distinct from an HTTP status. A PSR-18
     * client throws only for the former, which is what makes that separation free.
     *
     * The catch is ClientExceptionInterface and not \Throwable, deliberately. Anything else a
     * client throws is not a transport failure and must not be dressed as one: Laravel's
     * StrayRequestException, raised by `Http::preventStrayRequests()` when a request escapes
     * the fakes, is a plain RuntimeException, and it reaches the consumer's test naming the
     * URL only because it passes through here untouched. Widened, it would arrive as "could
     * not reach the Cloudflare API", which is the wrong diagnosis in the one place a wrong
     * diagnosis costs most.
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();

        $this->logger->debug('Cloudflare API request', [
            'method' => $method,
            'uri' => $uri,
        ]);

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('Cloudflare API request failed', [
                'method' => $method,
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);

            throw RequestException::for($method, $uri, $e);
        }
    }

    /**
     * Nothing has failed, but the window is nearly spent.
     *
     * Worth acting on here more than on most APIs: Cloudflare's limit is counted over five
     * minutes, and going over it blocks every call for the next five rather than only the one
     * that went over.
     */
    private function noteRateLimit(RequestInterface $request, ResponseMeta $meta): void
    {
        if ($meta->isNearingRateLimit()) {
            $this->logger->warning('Cloudflare API rate limit is nearly spent', [
                'uri' => (string) $request->getUri(),
                ...$meta->toArray(),
            ]);
        }
    }
}
