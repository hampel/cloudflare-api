<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api;

use Hampel\Cloudflare\Api\Support\Cast;

/**
 * One entry from Cloudflare's `errors` array - or from `messages`, which has the same shape.
 *
 * Every response this API sends carries both, whatever the status:
 *
 *     {"success": false, "errors": [{"code": 81057, "message": "Record already exists."}],
 *      "messages": [], "result": null}
 *
 * THE CODE IS THE PART TO BRANCH ON. Unlike an API that reports failures only in prose,
 * Cloudflare's codes are numeric, documented and stable - 1000 is an invalid token, 81057 a
 * duplicate record, 7003 a path that does not exist. `message` is written for a human and
 * can be reworded without warning; the code cannot. So `hasCode()` is the test, and the
 * message is what you show.
 *
 * `source.pointer` is a JSON pointer into the request body - `/content`, `/data/tag` - which
 * is Cloudflare's way of naming the field at fault. It is absent when the problem is not
 * about one element of the request.
 */
final class ApiError implements \JsonSerializable
{
    public function __construct(
        public readonly int $code,
        public readonly string $message,
        /** A JSON pointer into the submitted body, e.g. `/ttl`. Null when nothing was named. */
        public readonly ?string $pointer = null,
        public readonly ?string $documentationUrl = null,
    ) {
    }

    /**
     * Pull an errors or messages array out of a decoded body.
     *
     * Defensive about every level, because this runs on the failure path: the thing that
     * answered may not have been Cloudflare at all, and an exception raised while building
     * an exception loses the original failure.
     *
     * @param  array<mixed>|null  $decoded
     * @param  string  $key  `errors` or `messages` - one schema, two places it appears
     * @return list<self>
     */
    public static function listFrom(?array $decoded, string $key = 'errors'): array
    {
        $entries = $decoded[$key] ?? null;

        if (!is_array($entries)) {
            return [];
        }

        $parsed = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $message = Cast::string($entry['message'] ?? null);
            $code = Cast::int($entry['code'] ?? null);

            // The schema requires both. An entry carrying neither is not an error report -
            // it is something else in an `errors` key, and reporting it as a failure reason
            // would put noise in front of whoever is reading the real one.
            if (($message === null || trim($message) === '') && $code === null) {
                continue;
            }

            $source = $entry['source'] ?? null;
            $pointer = is_array($source) ? Cast::string($source['pointer'] ?? null) : null;

            $parsed[] = new self(
                $code ?? 0,
                trim($message ?? ''),
                $pointer !== null && trim($pointer) !== '' ? trim($pointer) : null,
                Cast::string($entry['documentation_url'] ?? null) ?: null,
            );
        }

        return $parsed;
    }

    /**
     * The field this error is about, as a plain name rather than a JSON pointer.
     *
     * `/content` becomes `content` and `/data/tag` becomes `data.tag`, which is the shape a
     * form or a log line wants. Null when the error named nothing.
     */
    public function field(): ?string
    {
        if ($this->pointer === null) {
            return null;
        }

        $field = str_replace('/', '.', ltrim($this->pointer, '/'));

        return $field === '' ? null : $field;
    }

    /**
     * The error as one line, for a message a human reads.
     */
    public function describe(): string
    {
        $field = $this->field();
        $prefix = $field === null ? '' : $field . ': ';

        return $this->code === 0
            ? $prefix . $this->message
            : sprintf('%s%s (code %d)', $prefix, $this->message, $this->code);
    }

    /**
     * @return array{code: int, message: string, pointer: string|null, documentation_url: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'pointer' => $this->pointer,
            'documentation_url' => $this->documentationUrl,
        ];
    }
}
