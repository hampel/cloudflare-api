<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Result;

use Hampel\Cloudflare\Api\ApiError;
use Hampel\Cloudflare\Api\Support\Cast;

/**
 * A successful API response: what was inside the envelope, and what came with it.
 *
 * EVERY ANSWER FROM THIS API IS WRAPPED, and the wrapper is the same whatever the endpoint:
 *
 *     {"success": true, "errors": [], "messages": [], "result": {...}, "result_info": {...}}
 *
 * So `result` is the part a caller wants and the rest is bookkeeping. This class unwraps it
 * once, here, rather than leaving every endpoint to remember - and keeps the envelope
 * reachable, because two parts of it are genuinely useful: `messages`, which is where
 * Cloudflare puts advice about a request that nonetheless succeeded, and `result_info`,
 * which is the pagination.
 *
 * REACHING ONE OF THESE MEANS `success` WAS TRUE. Connection raises for a body that says
 * otherwise, whatever the HTTP status said - see Connection::send().
 */
final class ApiResponse implements \JsonSerializable
{
    /**
     * @param  array<mixed>  $envelope  the whole decoded body
     * @param  list<ApiError>  $messages  the envelope's `messages`, parsed
     */
    public function __construct(
        public readonly array $envelope,
        public readonly int $status,
        public readonly ResponseMeta $meta,
        public readonly array $messages = [],
    ) {
    }

    /**
     * The `result`, whatever shape it is.
     */
    public function result(): mixed
    {
        return $this->envelope['result'] ?? null;
    }

    /**
     * The `result` as an object - what an entity's fromArray() takes.
     *
     * Empty when the result was null, a list, or absent. A DELETE on this API answers
     * `{"result": {"id": "..."}}`, so even that is an object.
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        return Cast::object($this->result());
    }

    /**
     * The `result` as a list - what a collection endpoint answers with.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $result = $this->result();

        if (!is_array($result)) {
            return [];
        }

        $rows = [];

        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = Cast::object($row);
            }
        }

        return $rows;
    }

    /**
     * One key from inside the result object.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        return $this->object()[$key] ?? $default;
    }

    /**
     * The pagination that came with a collection, or null for a response that is not one.
     */
    public function resultInfo(): ?ResultInfo
    {
        $info = $this->envelope['result_info'] ?? null;

        return is_array($info) ? ResultInfo::fromArray(Cast::object($info)) : null;
    }

    /**
     * Whether the result carried nothing at all.
     *
     * Distinct from a result of `[]`: a null result is what an endpoint with nothing to
     * return sends, and an empty list is a collection that is genuinely empty.
     */
    public function isEmpty(): bool
    {
        $result = $this->result();

        return $result === null || $result === [];
    }

    /**
     * Cloudflare's advisory notes about a request that SUCCEEDED.
     *
     * Rarely populated and worth reading when it is - this is where a deprecation, or a
     * value that was accepted but adjusted, is reported. It is not an error, and nothing in
     * this package treats it as one.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        return array_map(static fn (ApiError $message): string => $message->message, $this->messages);
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->envelope;
    }
}
