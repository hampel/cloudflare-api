<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Exception;

use Hampel\Cloudflare\Api\ApiError;
use Psr\Http\Message\ResponseInterface;

/**
 * Cloudflare answered, and the answer was not a success.
 *
 * TWO THINGS CAN MEAN FAILURE HERE, and that is the first surprise. The HTTP status is one.
 * The `success` flag in the body is the other, and it is not merely a restatement: this API
 * has historically answered some requests with a 200 whose body says `"success": false`, and
 * a client that trusted the status alone would read the `null` result as an empty collection.
 * Connection treats either as a failure and both arrive here.
 *
 * READ THE CODE, NOT THE PROSE. Every failure carries a numeric code that is documented and
 * stable - `hasCode()` is the test worth writing. The subclasses below split the statuses a
 * caller can actually act on differently, so the common cases need no inspection at all.
 *
 * THE STATUS IS NOT ALWAYS WHAT DECIDES THE TYPE. Two codes are mapped ahead of it, both
 * because the status alone would send a caller to the wrong problem: 6003 on a 400 is a
 * credential Cloudflare could not parse, and 1000 on a 2xx is one it parsed and rejected inside
 * an envelope that claimed success.
 */
abstract class ApiException extends CloudflareException
{
    /**
     * Cloudflare's code for a credential it recognised the shape of and would not accept.
     */
    public const CODE_INVALID_TOKEN = 1000;

    /**
     * Cloudflare's code for a credential it would not even parse.
     *
     * A TOKEN CAN BE REFUSED TWO WAYS AND ONLY ONE OF THEM IS A 401. Measured on 2026-09-13: a
     * forty-character token of the right shape but the wrong value answers `401` with code
     * 1000, while a token that does not look like one at all - a placeholder left in a config
     * file, a truncated value, a stray `Bearer ` prefix - answers `400` with this code and the
     * message "Invalid request headers", because the request is rejected before authentication
     * runs.
     *
     * Both are the credential, so both raise NotAuthenticatedException. Attributing this code
     * to the credential is safe for this package specifically: it sets three headers - Accept,
     * Content-Type and Authorization - and only Authorization carries anything the caller
     * supplied.
     */
    public const CODE_INVALID_REQUEST_HEADERS = 6003;

    /**
     * @param  list<ApiError>  $errors  the API's own errors[], parsed
     * @param  string  $body  the raw response body, for when it was not JSON at all
     */
    final public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $errors = [],
        public readonly string $body = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * @param  array<mixed>|null  $decoded  the decoded body, or null if it was not JSON
     */
    public static function fromResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        ?array $decoded,
        string $body,
    ): self {
        $status = $response->getStatusCode();
        $errors = ApiError::listFrom($decoded);

        $detail = $errors !== []
            ? implode('; ', array_map(static fn (ApiError $error): string => $error->describe(), $errors))
            : trim($body);

        $message = sprintf(
            'Cloudflare rejected %s %s (HTTP %d)%s',
            $method,
            $uri,
            $status,
            $detail === '' ? '' : ': ' . $detail
        );

        $retryAfter = self::retryAfter($response);

        // The status decides the type, with one exception. A 200 carrying `success: false`
        // reaches here as a 200, and none of the statuses below fits it - so the code is
        // what classifies that case, and an unrecognised one falls to ClientException rather
        // than being mistaken for a success.
        $exception = match (true) {
            // Before the generic 400, deliberately: this is a rejected credential wearing a
            // validation failure's status. See CODE_INVALID_REQUEST_HEADERS.
            $status === 400 && self::namesCode($errors, self::CODE_INVALID_REQUEST_HEADERS)
                => NotAuthenticatedException::class,
            $status === 400 => ValidationException::class,
            $status === 401 => NotAuthenticatedException::class,
            $status === 403 => NotPermittedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => TooManyRequestsException::class,
            $status >= 500 => ServerException::class,
            $status < 400 && self::namesCode($errors, self::CODE_INVALID_TOKEN) => NotAuthenticatedException::class,
            default => ClientException::class,
        };

        return new $exception($message, $status, $errors, $body, $retryAfter);
    }

    /**
     * Every error's message, in order, for a caller that wants to show them rather than
     * branch on them.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (ApiError $error): string => $error->message, $this->errors);
    }

    /**
     * Every error's code.
     *
     * @return list<int>
     */
    public function codes(): array
    {
        return array_map(static fn (ApiError $error): int => $error->code, $this->errors);
    }

    /**
     * Whether Cloudflare reported this particular code - the test to write in preference to
     * matching on a message, which is prose and can be reworded.
     */
    public function hasCode(int $code): bool
    {
        return self::namesCode($this->errors, $code);
    }

    /**
     * The errors that named a field, keyed by it - which is the shape a form wants.
     *
     * Keys are the JSON pointer flattened to a path: `/data/tag` becomes `data.tag`. An
     * error that named nothing is not in here, because there is nowhere on a form to put it.
     *
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        $fields = [];

        foreach ($this->errors as $error) {
            $field = $error->field();

            if ($field !== null) {
                $fields[$field][] = $error->message;
            }
        }

        return $fields;
    }

    /**
     * Whether any error named this field. Takes the flattened form - `content`, `data.tag`.
     */
    public function concerns(string $field): bool
    {
        foreach ($this->errors as $error) {
            if ($error->field() === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ApiError>  $errors
     */
    private static function namesCode(array $errors, int $code): bool
    {
        foreach ($errors as $error) {
            if ($error->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds to wait, from `Retry-After`.
     *
     * Cloudflare documents this header as sent only when a limit has actually been exceeded,
     * which - unlike some APIs that send one on every response - makes its presence meaningful
     * on its own.
     *
     * Half measured: it was absent from every successful response on 12 September 2026, which
     * is consistent. A 429 was not provoked, so the value carried on one is still the
     * documentation's word rather than an observation.
     */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        return $header !== '' && ctype_digit($header) ? (int) $header : null;
    }
}
