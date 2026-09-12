<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Support;

use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;

/**
 * How a DNS record listing is filtered and sorted.
 *
 * IT IS A QUERY STRING, NOT A HEADER OR A JSON DOCUMENT. Cloudflare filters with ordinary
 * query parameters, and the expressive part is in their names: every text field takes four
 * predicates rather than one, written as `name.exact`, `name.contains`, `name.startswith`
 * and `name.endswith`. A dotted parameter name is easy to typo and impossible to notice
 * having typoed - an unrecognised one is IGNORED, so the request succeeds and returns the
 * unfiltered collection. Measured on 2026-09-12: `?no_such_filter=x` against a zone's
 * records answered 200 with every record in it. That is the whole reason this class exists
 * rather than an array - the failure has no error to catch, and whatever walks the result and
 * deletes what it finds has just been handed the entire zone.
 *
 *     $query = RecordQuery::make()
 *         ->type(RecordType::A)
 *         ->nameEndsWith('.example.com')
 *         ->orderBy('name');
 *
 *     foreach ($cloudflare->zones()->records($zoneId)->each($query) as $record) { ... }
 *
 * Immutable - every method returns a new one - so a base query can live in a property and be
 * specialised per call without the specialisations accumulating.
 *
 * CONDITIONS ARE ANDed BY DEFAULT, and `matchAny()` switches the whole query to OR. Tag
 * conditions are combined among themselves by `tagMatch` and joined to everything else by
 * `match`, which is two settings doing what looks like one job and is Cloudflare's design
 * rather than a subtlety this package invented.
 */
final class RecordQuery implements \JsonSerializable
{
    /**
     * What a record listing may be ordered by. Anything else is refused here rather than
     * ignored by the API.
     *
     * @var list<string>
     */
    public const ORDERABLE = ['type', 'name', 'content', 'ttl', 'proxied'];

    /**
     * The text fields that take the four-predicate treatment.
     *
     * @var list<string>
     */
    public const PREDICATE_FIELDS = ['name', 'content', 'comment', 'tag'];

    /**
     * @param  array<string, scalar>  $conditions
     */
    private function __construct(
        private readonly array $conditions = [],
        private readonly ?string $orderBy = null,
        private readonly ?string $direction = null,
        private readonly ?string $match = null,
        private readonly ?string $tagMatch = null,
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * The common case as a one-liner: every record with this exact name.
     */
    public static function name(string $name): self
    {
        return self::make()->nameIs($name);
    }

    /**
     * Every record of one type.
     */
    public static function ofType(RecordType $type): self
    {
        return self::make()->type($type);
    }

    public function type(RecordType $type): self
    {
        return $this->with('type', $type->value);
    }

    /**
     * The record's full name, exactly - `www.example.com`, not `www`.
     *
     * Cloudflare's names are absolute everywhere in this API. The match is case-insensitive
     * at its end, so no normalisation happens here.
     */
    public function nameIs(string $name): self
    {
        return $this->with('name.exact', self::text($name, 'name'));
    }

    public function nameContains(string $fragment): self
    {
        return $this->with('name.contains', self::text($fragment, 'name'));
    }

    public function nameStartsWith(string $prefix): self
    {
        return $this->with('name.startswith', self::text($prefix, 'name'));
    }

    /**
     * Every name under a suffix - `->nameEndsWith('.example.com')` for a whole zone, or
     * `->nameEndsWith('.staging.example.com')` for a branch of one.
     */
    public function nameEndsWith(string $suffix): self
    {
        return $this->with('name.endswith', self::text($suffix, 'name'));
    }

    /**
     * The record's content, exactly - an IP for an address record, a hostname for a CNAME.
     *
     * FOR A TYPE WHOSE CONTENT CLOUDFLARE COMPOSES - CAA, SRV and the rest of the `data`
     * family - this matches the formatted string it built, not the components you set. Those
     * are not filterable individually.
     */
    public function content(string $content): self
    {
        return $this->with('content.exact', self::text($content, 'content'));
    }

    public function contentContains(string $fragment): self
    {
        return $this->with('content.contains', self::text($fragment, 'content'));
    }

    public function contentStartsWith(string $prefix): self
    {
        return $this->with('content.startswith', self::text($prefix, 'content'));
    }

    public function contentEndsWith(string $suffix): self
    {
        return $this->with('content.endswith', self::text($suffix, 'content'));
    }

    /**
     * Whether the record is proxied. Both values are a filter - `proxied(false)` asks for
     * the DNS-only records, which is not the same as not asking at all.
     */
    public function proxied(bool $proxied = true): self
    {
        return $this->with('proxied', $proxied);
    }

    public function comment(string $comment): self
    {
        return $this->with('comment.exact', self::text($comment, 'comment'));
    }

    public function commentContains(string $fragment): self
    {
        return $this->with('comment.contains', self::text($fragment, 'comment'));
    }

    /**
     * Only records that carry a comment at all.
     */
    public function hasComment(): self
    {
        return $this->with('comment.present', '');
    }

    /**
     * Only records that carry no comment - the ones nobody has explained yet.
     */
    public function hasNoComment(): self
    {
        return $this->with('comment.absent', '');
    }

    /**
     * A tag by name, whatever its value.
     */
    public function tagged(string $tag): self
    {
        return $this->with('tag.present', self::text($tag, 'tag'));
    }

    public function notTagged(string $tag): self
    {
        return $this->with('tag.absent', self::text($tag, 'tag'));
    }

    /**
     * A tag with a particular value, written `name:value`.
     */
    public function tagIs(string $nameAndValue): self
    {
        return $this->with('tag.exact', self::pair($nameAndValue));
    }

    public function tagContains(string $nameAndValue): self
    {
        return $this->with('tag.contains', self::pair($nameAndValue));
    }

    /**
     * Cloudflare's free-text search across several properties at once.
     *
     * ITS BEHAVIOUR IS DELIBERATELY UNSPECIFIED and Cloudflare says so in the specification -
     * it is meant for a person typing into a box, and may change. Do not build anything on
     * it that has to keep working; use the predicates above, which are defined.
     */
    public function search(string $terms): self
    {
        return $this->with('search', self::text($terms, 'search'));
    }

    /**
     * Satisfy at least one condition rather than all of them.
     */
    public function matchAny(): self
    {
        return new self($this->conditions, $this->orderBy, $this->direction, 'any', $this->tagMatch);
    }

    public function matchAll(): self
    {
        return new self($this->conditions, $this->orderBy, $this->direction, 'all', $this->tagMatch);
    }

    /**
     * How the TAG conditions combine with each other, which is a separate setting from
     * match() and does not follow it.
     */
    public function tagMatchAny(): self
    {
        return new self($this->conditions, $this->orderBy, $this->direction, $this->match, 'any');
    }

    public function tagMatchAll(): self
    {
        return new self($this->conditions, $this->orderBy, $this->direction, $this->match, 'all');
    }

    /**
     * Sort the listing. Only the five fields in ORDERABLE may be named.
     *
     * ORDER EXPLICITLY BEFORE WALKING A LARGE ZONE. Each page is its own request, so an
     * unordered collection has no promise of stability between them - see Endpoint::apiEach().
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $field = strtolower(trim($field));

        if (!in_array($field, self::ORDERABLE, true)) {
            throw new InvalidArgumentException(sprintf(
                'DNS records can be ordered by %s, not "%s".',
                implode(', ', self::ORDERABLE),
                $field
            ));
        }

        return new self($this->conditions, $field, self::direction($direction), $this->match, $this->tagMatch);
    }

    public function descending(): self
    {
        return $this->reordered('desc');
    }

    public function ascending(): self
    {
        return $this->reordered('asc');
    }

    public function isEmpty(): bool
    {
        return $this->toQuery() === [];
    }

    /**
     * The query parameters to send.
     *
     * `match` and `tag_match` are omitted when nothing was set, so the API's own default of
     * `all` applies rather than this package restating it - which keeps a request readable
     * and means a change of default is Cloudflare's to make.
     *
     * @return array<string, scalar>
     */
    public function toQuery(): array
    {
        $query = $this->conditions;

        if ($this->orderBy !== null) {
            $query['order'] = $this->orderBy;
            $query['direction'] = $this->direction ?? 'asc';
        }

        if ($this->match !== null) {
            $query['match'] = $this->match;
        }

        if ($this->tagMatch !== null) {
            $query['tag_match'] = $this->tagMatch;
        }

        return $query;
    }

    /**
     * @return array<string, scalar>
     */
    public function jsonSerialize(): array
    {
        return $this->toQuery();
    }

    private function with(string $parameter, string|bool $value): self
    {
        return new self(
            [...$this->conditions, $parameter => $value],
            $this->orderBy,
            $this->direction,
            $this->match,
            $this->tagMatch
        );
    }

    private function reordered(string $direction): self
    {
        if ($this->orderBy === null) {
            throw new InvalidArgumentException(
                'A direction means nothing without a field to order by: call orderBy() first, '
                    . 'or pass the direction to it.'
            );
        }

        return new self($this->conditions, $this->orderBy, self::direction($direction), $this->match, $this->tagMatch);
    }

    private static function direction(string $direction): string
    {
        $direction = strtolower(trim($direction));

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException(sprintf(
                'A listing can be ordered "asc" or "desc", not "%s".',
                $direction
            ));
        }

        return $direction;
    }

    /**
     * An empty condition is worse than no condition: `name.exact=` matches nothing, and the
     * request succeeds, so an empty variable that reached here would come back as "the zone
     * has no such record" rather than as a mistake.
     */
    private static function text(string $value, string $field): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s condition needs a value; an empty one matches nothing and reports it as '
                    . 'an empty result rather than an error.',
                $field
            ));
        }

        return trim($value);
    }

    /**
     * A tag condition of the form `name:value`.
     */
    private static function pair(string $value): string
    {
        $value = self::text($value, 'tag');

        if (!str_contains($value, ':')) {
            throw new InvalidArgumentException(sprintf(
                'A tag value condition is written "name:value"; "%s" names no value. Use '
                    . 'tagged() to match on the tag name alone.',
                $value
            ));
        }

        return $value;
    }
}
