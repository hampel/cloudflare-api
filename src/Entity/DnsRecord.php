<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Entity;

use Hampel\Cloudflare\Api\Enum\CaaTag;
use Hampel\Cloudflare\Api\Enum\RecordType;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Support\Cast;
use Hampel\Cloudflare\Api\Support\Ttl;

/**
 * One DNS record.
 *
 * THE NAME IS ABSOLUTE. Cloudflare's record names include the zone - `www.example.com`, not
 * `www` - and the apex is the zone name itself rather than an empty string. The API is
 * forgiving about this and will append the zone to a bare label, which is precisely what
 * makes it worth being strict about here: the forgiving behaviour is undocumented enough
 * that the day it changes, every bare name in a codebase becomes a record in the wrong
 * place. Zone::fqdn() turns a label into a name.
 *
 * THE TYPE DECIDES WHERE THE VALUE GOES. Eight types put it in `content` as a string; the
 * other thirteen put it in a `data` object of components and generate `content` from them.
 * Sending `content` for one of those thirteen is refused. RecordType::usesData() is the test,
 * and the named constructors below already know the answer:
 *
 *     DnsRecord::a('www.example.com', '203.0.113.10')->proxy();
 *     DnsRecord::cname('blog.example.com', 'example.ghost.io');
 *     DnsRecord::mx('example.com', 'mail.example.com', priority: 10);
 *     DnsRecord::txt('_dmarc.example.com', 'v=DMARC1; p=quarantine');
 *     DnsRecord::caa('example.com', CaaTag::Issue, 'letsencrypt.org');
 *     DnsRecord::srv('_sip._tcp.example.com', 'sip.example.com', port: 5060, priority: 10);
 *
 * OPTIONAL FIELDS ARE NULL UNTIL SET, and only the ones that are not null are sent. That is
 * what makes a patch partial. A record READ from the API has every field populated, so
 * sending that back sends all of them - which is the same values it already had, but it is
 * worth knowing which of the two you are holding before choosing patch() over replace().
 */
final class DnsRecord implements \JsonSerializable
{
    /**
     * @param  RecordType|null  $type  null only for a record READ BACK whose type this package
     *                                 does not model - see fromArray(). Every named constructor
     *                                 supplies one, so a record you built always has a type
     * @param  array<string, mixed>  $data  components, for a type that uses them
     * @param  list<string>  $tags  free-form labels. They have no effect on DNS responses
     * @param  array<string, mixed>  $settings  `ipv4_only` and `ipv6_only`, which apply to
     *                                          proxied CNAMEs and to nothing else
     * @param  bool|null  $proxiable  read-only: whether Cloudflare COULD proxy this record.
     *                                Accounts for the zone's plan and the record's content as
     *                                well as its type, so it is a better answer than
     *                                RecordType::isProxiable() for a record that exists
     * @param  array<string, mixed>  $raw  the payload this was built from, so a field added
     *                                     to the API after this release is still reachable
     */
    public function __construct(
        public readonly ?RecordType $type,
        public readonly ?string $name = null,
        public readonly ?string $content = null,
        public readonly array $data = [],
        public readonly ?string $id = null,
        public readonly ?int $ttl = null,
        public readonly ?bool $proxied = null,
        public readonly ?int $priority = null,
        public readonly ?string $comment = null,
        public readonly array $tags = [],
        public readonly array $settings = [],
        public readonly ?bool $proxiable = null,
        public readonly ?\DateTimeImmutable $createdOn = null,
        public readonly ?\DateTimeImmutable $modifiedOn = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * An IPv4 address record.
     */
    public static function a(string $name, string $address): self
    {
        return new self(RecordType::A, self::name($name), self::required($address, 'An A record needs an IPv4 address.'));
    }

    /**
     * An IPv6 address record.
     */
    public static function aaaa(string $name, string $address): self
    {
        return new self(RecordType::AAAA, self::name($name), self::required($address, 'An AAAA record needs an IPv6 address.'));
    }

    /**
     * An alias.
     *
     * Cloudflare permits a CNAME at the zone apex, which ordinary DNS does not - it flattens
     * it into address records when answering. That is a genuine capability rather than a
     * quirk to work around, and it is why a CNAME here may carry a name that would be
     * illegal on another provider.
     */
    public static function cname(string $name, string $target): self
    {
        return new self(RecordType::CNAME, self::name($name), self::required($target, 'A CNAME needs a target.'));
    }

    /**
     * A mail exchanger. Lower priority wins.
     *
     * `name` is the domain receiving the mail, so for mail addressed at the domain itself it
     * is the zone name - `example.com`, not an empty string.
     */
    public static function mx(string $name, string $mailServer, int $priority = 10): self
    {
        return new self(
            RecordType::MX,
            self::name($name),
            self::required($mailServer, 'An MX record needs a mail server.'),
            priority: self::inRange($priority, 0, 65535, 'priority'),
        );
    }

    /**
     * A text record - SPF, DKIM, DMARC, a domain-verification token.
     *
     * THE VALUE IS PASSED THROUGH VERBATIM, and two things about how Cloudflare stores it are
     * worth knowing before comparing what you sent with what comes back.
     *
     * A TXT record is a sequence of quoted character strings, each at most 255 bytes
     * (RFC 1035). Cloudflare splits anything longer across several strings automatically - so
     * a 2048-bit DKIM key sent as one long value is stored correctly and READ BACK as
     * `"first 255 bytes" "the rest"`. An equality check against the original then fails on a
     * record that is perfectly right.
     *
     * A SHORT VALUE DOES ROUND-TRIP VERBATIM - measured on 2026-09-12, an unquoted
     * 43-byte value came back byte-identical. So the rewriting is not something every TXT
     * record suffers; it is what happens once a value crosses 255 bytes, which is exactly the
     * case a DKIM key falls into and a verification token does not.
     *
     * Compare long TXT content by parsing it, or by asking whether the record resolves, rather
     * than by string equality with what you submitted.
     */
    public static function txt(string $name, string $value): self
    {
        return new self(RecordType::TXT, self::name($name), self::required($value, 'A TXT record needs a value.'));
    }

    /**
     * A name server, delegating a subdomain away from this zone.
     */
    public static function ns(string $name, string $nameServer): self
    {
        return new self(RecordType::NS, self::name($name), self::required($nameServer, 'An NS record needs a name server.'));
    }

    /**
     * A pointer record.
     *
     * Reverse DNS for an address generally belongs in the `in-addr.arpa` zone held by
     * whoever owns the address block, not in a forward zone - so a PTR here is right only if
     * Cloudflare holds that zone for you.
     */
    public static function ptr(string $name, string $target): self
    {
        return new self(RecordType::PTR, self::name($name), self::required($target, 'A PTR record needs a target.'));
    }

    /**
     * A certificate authority authorisation.
     *
     * `name` is the domain the policy applies to - the zone name for a policy covering the
     * whole zone, which is nearly always what is wanted.
     *
     * A `data` type: the components are sent and Cloudflare composes the content.
     */
    public static function caa(string $name, CaaTag $tag, string $value, int $flags = 0): self
    {
        return new self(RecordType::CAA, self::name($name), data: [
            'flags' => self::inRange($flags, 0, 255, 'flags'),
            'tag' => $tag->value,
            'value' => self::required($value, 'A CAA record needs a value - an authority, or a URL for iodef.'),
        ]);
    }

    /**
     * A service record.
     *
     * THE SERVICE AND PROTOCOL GO IN THE NAME, decorated, and not in the data - so
     * `_sip._tcp.example.com`, with both underscores and the zone. Cloudflare's current API
     * takes only port, priority, target and weight as components; an older version of it
     * accepted `service` and `proto` fields, and examples using those are still in
     * circulation.
     *
     * Lower priority wins; among equal priorities, higher weight is preferred.
     */
    public static function srv(
        string $name,
        string $target,
        int $port,
        int $priority = 0,
        int $weight = 0,
    ): self {
        return new self(RecordType::SRV, self::srvName($name), data: [
            'priority' => self::inRange($priority, 0, 65535, 'priority'),
            'weight' => self::inRange($weight, 0, 65535, 'weight'),
            'port' => self::inRange($port, 0, 65535, 'port'),
            'target' => self::required($target, 'An SRV record needs a target.'),
        ]);
    }

    /**
     * Any type whose value is a single string, for one this package has no named constructor
     * for.
     *
     * @throws InvalidArgumentException  when the type is one that uses `data` instead
     */
    public static function of(RecordType $type, string $name, string $content): self
    {
        if ($type->usesData()) {
            throw new InvalidArgumentException(sprintf(
                'A %s record carries its value in `data` components, not in `content` - '
                    . 'Cloudflare generates the content from them. Use components() instead.',
                $type->value
            ));
        }

        return new self($type, self::name($name), self::required($content, sprintf('A %s record needs content.', $type->value)));
    }

    /**
     * Any type whose value is a set of components, for one this package has no named
     * constructor for.
     *
     * @param  array<string, mixed>  $data
     * @throws InvalidArgumentException  when the type uses `content` instead
     */
    public static function components(RecordType $type, string $name, array $data): self
    {
        if (!$type->usesData()) {
            throw new InvalidArgumentException(sprintf(
                'A %s record carries its value in `content`, not in `data`. Use of() instead.',
                $type->value
            ));
        }

        if ($data === []) {
            throw new InvalidArgumentException(sprintf('A %s record needs its data components.', $type->value));
        }

        return new self($type, self::name($name), data: $data);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            RecordType::tryFrom(Cast::string($row['type'] ?? null) ?? ''),
            Cast::string($row['name'] ?? null),
            Cast::string($row['content'] ?? null),
            Cast::object($row['data'] ?? null),
            Cast::string($row['id'] ?? null),
            Cast::int($row['ttl'] ?? null),
            Cast::bool($row['proxied'] ?? null),
            Cast::int($row['priority'] ?? null),
            Cast::string($row['comment'] ?? null),
            Cast::strings($row['tags'] ?? null),
            Cast::object($row['settings'] ?? null),
            Cast::bool($row['proxiable'] ?? null),
            Cast::datetime($row['created_on'] ?? null),
            Cast::datetime($row['modified_on'] ?? null),
            $row,
        );
    }

    /**
     * The payload for a create or a full replacement.
     *
     * `content` is emitted only for a type that uses it and `data` only for a type that does,
     * whichever happens to be populated - which matters when echoing a record READ from the
     * API straight back: Cloudflare returns a generated `content` on a CAA or an SRV, and
     * sending it back is refused.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $type = $this->modelledType();
        $payload = ['type' => $type->value];

        if ($this->name !== null) {
            $payload['name'] = $this->name;
        }

        if ($type->usesData()) {
            if ($this->data !== []) {
                $payload['data'] = $this->data;
            }
        } elseif ($this->content !== null) {
            $payload['content'] = $this->content;
        }

        if ($this->ttl !== null) {
            $payload['ttl'] = $this->ttl;
        }

        if ($this->proxied !== null) {
            $payload['proxied'] = $this->proxied;
        }

        if ($type->usesPriority() && $this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        if ($this->comment !== null) {
            $payload['comment'] = $this->comment;
        }

        // An empty array is a meaningful value for tags - it is how you remove every one - so
        // it is sent whenever the record came from the API or had tags set on it, and omitted
        // only when it was built from scratch and never given any.
        if ($this->tags !== [] || array_key_exists('tags', $this->raw)) {
            $payload['tags'] = $this->tags;
        }

        if ($this->settings !== []) {
            $payload['settings'] = $this->settings;
        }

        return $payload;
    }

    /**
     * The same payload, for a partial update.
     *
     * Identical to toArray() today, and separate from it deliberately: the two verbs mean
     * genuinely different things on this API - see Connection::put() - and a package that
     * shared one method between them would have nowhere to put the difference on the day one
     * appears.
     *
     * @return array<string, mixed>
     */
    public function toPatchArray(): array
    {
        return $this->toArray();
    }

    /**
     * Put the record behind Cloudflare's proxy.
     *
     * PROXYING AND A TTL ARE MUTUALLY EXCLUSIVE. Cloudflare serves its own anycast address
     * for a proxied record and needs to be able to change it, so the TTL is forced to
     * automatic and setting one alongside is refused. This clears any TTL that was set rather
     * than letting the pair travel to the API and come back a 400.
     *
     * Only A, AAAA and CNAME can be proxied at all.
     */
    public function proxy(): self
    {
        if (!$this->modelledType()->isProxiable()) {
            throw new InvalidArgumentException(sprintf(
                'Cloudflare cannot proxy a %s record - only A, AAAA and CNAME resolve to '
                    . 'something it can stand in front of.',
                $this->modelledType()->value
            ));
        }

        return $this->with(proxied: true, ttl: Ttl::AUTOMATIC);
    }

    /**
     * Serve the record straight from DNS, with no proxy in front of it.
     */
    public function unproxy(): self
    {
        return $this->with(proxied: false);
    }

    /**
     * How long resolvers may cache this record.
     *
     * `1` IS "AUTOMATIC", NOT ONE SECOND - see Ttl. Anything else must be 60 to 86400, and
     * that is checked here rather than at the API, because a rejected TTL is a round trip
     * spent on something knowable in advance.
     */
    public function withTtl(int $seconds): self
    {
        Ttl::assertValid($seconds);

        if ($this->proxied === true && $seconds !== Ttl::AUTOMATIC) {
            throw new InvalidArgumentException(
                'A proxied record has no TTL of its own: Cloudflare serves its own address '
                    . 'for it and forces automatic. Call unproxy() first if the TTL is what '
                    . 'you want.'
            );
        }

        return $this->with(ttl: $seconds);
    }

    /**
     * Let Cloudflare choose the TTL, which it serves as 300 seconds.
     */
    public function withAutomaticTtl(): self
    {
        return $this->with(ttl: Ttl::AUTOMATIC);
    }

    /**
     * A note about the record. Has no effect on what DNS serves, and is the cheapest way to
     * record why a record exists for whoever finds it in three years.
     */
    public function withComment(string $comment): self
    {
        return $this->with(comment: $comment);
    }

    /**
     * Free-form labels. They have no effect on what DNS serves.
     *
     * TAGS ARE A PAID FEATURE, and the failure says so obliquely. On a Free zone the quota is
     * zero, so a record carrying even one is refused with `400 {"code": 9300, "message": "DNS
     * record has 1 tags, exceeding the quota of 0."}` - measured on 2026-09-12. The
     * number in that message is the plan's allowance, not a count of anything wrong with the
     * request, which is easy to read as the opposite of what it means.
     *
     * Nothing here checks the plan, because a record cannot know which zone it is destined
     * for. Where tagging is optional, send the record without tags and add them in a separate
     * patch that is allowed to fail - the `records` harness exercise does exactly that.
     *
     * @param  list<string>  $tags
     */
    public function withTags(array $tags): self
    {
        return $this->with(tags: array_values($tags));
    }

    public function withName(string $name): self
    {
        return $this->with(name: self::name($name));
    }

    public function withContent(string $content): self
    {
        if ($this->modelledType()->usesData()) {
            throw new InvalidArgumentException(sprintf(
                'A %s record\'s content is generated by Cloudflare from its `data` components '
                    . 'and cannot be set. Use withData().',
                $this->modelledType()->value
            ));
        }

        return $this->with(content: $content);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withData(array $data): self
    {
        if (!$this->modelledType()->usesData()) {
            throw new InvalidArgumentException(sprintf(
                'A %s record has no `data` components; its value is `content`.',
                $this->modelledType()->value
            ));
        }

        return $this->with(data: $data);
    }

    /**
     * Lower wins. MX and URI only - an SRV's priority is a component inside `data`.
     */
    public function withPriority(int $priority): self
    {
        if (!$this->modelledType()->usesPriority()) {
            throw new InvalidArgumentException(sprintf(
                'A %s record has no top-level priority.%s',
                $this->modelledType()->value,
                $this->type === RecordType::SRV
                    ? ' An SRV carries its priority inside `data` - set it with withData() or '
                        . 'build the record with srv().'
                    : ''
            ));
        }

        return $this->with(priority: self::inRange($priority, 0, 65535, 'priority'));
    }

    public function isProxied(): bool
    {
        return $this->proxied === true;
    }

    /**
     * The TTL this record is actually served with, in seconds, with automatic resolved.
     *
     * What to print beside a record rather than the raw field.
     */
    public function effectiveTtl(): int
    {
        return Ttl::effective($this->ttl ?? Ttl::AUTOMATIC);
    }

    /**
     * One component out of a `data` record - `$record->component('tag')` on a CAA.
     */
    public function component(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * The record as one line, for a log or a diff.
     */
    public function describe(): string
    {
        return sprintf(
            '%s %s %s %s%s',
            $this->name ?? '(unnamed)',
            Ttl::describe($this->ttl ?? Ttl::AUTOMATIC),
            $this->type->value ?? (Cast::string($this->raw['type'] ?? null) ?? '?'),
            $this->content ?? ($this->data === [] ? '' : json_encode($this->data, JSON_UNESCAPED_SLASHES)),
            $this->isProxied() ? ' (proxied)' : ''
        );
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : $this->toArray();
    }

    /**
     * The type, or a refusal.
     *
     * A record whose type this package does not model cannot be reasoned about: whether its
     * value lives in `content` or `data`, whether it can be proxied, whether it has a
     * top-level priority are all properties of the type. Guessing any of them is how a record
     * gets written back mangled, which is what the old `?? RecordType::TXT` fallback in
     * fromArray() did - silently, and to the one field that decides all three.
     *
     * So reading such a record is fine and writing it is refused. `raw` still holds everything
     * Cloudflare sent, and describe() still renders it.
     */
    private function modelledType(): RecordType
    {
        if ($this->type === null) {
            throw new InvalidArgumentException(sprintf(
                'This record came back as type "%s", which this package does not model. It can '
                    . 'be read - `raw` has everything Cloudflare sent - but not built on or '
                    . 'written back, because the rules for that type are unknown here. Upgrade '
                    . 'to a release that has it.',
                Cast::string($this->raw['type'] ?? null) ?? 'unknown'
            ));
        }

        return $this->type;
    }

    /**
     * Every wither goes through here, and it is written out in full rather than reflecting
     * over the properties: `readonly` cannot be reassigned on a clone before PHP 8.5, so a
     * new instance is the only way, and building it from an array of changes would trade
     * fifteen typed arguments for fifteen `mixed` ones that static analysis cannot check.
     *
     * Passing nothing keeps the current value. There is no way to set a field back to null
     * through this, which is deliberate: on a partial update, null and absent mean the same
     * thing to the API, so "unset it" is not an operation it offers - an empty array clears a
     * list, and an empty string clears a comment.
     *
     * @param  array<string, mixed>|null  $data
     * @param  list<string>|null  $tags
     */
    private function with(
        ?string $name = null,
        ?string $content = null,
        ?array $data = null,
        ?int $ttl = null,
        ?bool $proxied = null,
        ?int $priority = null,
        ?string $comment = null,
        ?array $tags = null,
    ): self {
        return new self(
            $this->type,
            $name ?? $this->name,
            $content ?? $this->content,
            $data ?? $this->data,
            $this->id,
            $ttl ?? $this->ttl,
            $proxied ?? $this->proxied,
            $priority ?? $this->priority,
            $comment ?? $this->comment,
            $tags ?? $this->tags,
            $this->settings,
            $this->proxiable,
            $this->createdOn,
            $this->modifiedOn,
            $this->raw,
        );
    }

    /**
     * A record name, trimmed of the trailing dot a zone file would carry.
     *
     * `@` is accepted as the apex because it is what a zone file writes and what anyone who
     * has edited one will reach for - but it cannot be resolved to a zone name from here, so
     * it is refused with the reason rather than sent.
     */
    private static function name(string $name): string
    {
        $name = trim($name, ". \t\n\r\0\x0B");

        if ($name === '') {
            throw new InvalidArgumentException(
                'A record name is required, and on this API it is the full name including the '
                    . 'zone - "example.com" for the apex, not an empty string. Zone::fqdn() '
                    . 'builds one from a label.'
            );
        }

        if ($name === '@') {
            throw new InvalidArgumentException(
                'Cloudflare has no "@" for the zone apex: write the zone name itself. '
                    . 'Zone::fqdn("") returns it.'
            );
        }

        return $name;
    }

    /**
     * An SRV name has to carry the service and the protocol, both underscore-prefixed. The
     * check is for the leading underscore only - the rest is the API's to judge - because the
     * common mistake is passing `sip.example.com`, which is accepted as an ordinary name and
     * creates a record nothing looking for the service will ever find.
     */
    private static function srvName(string $name): string
    {
        $name = self::name($name);

        if (!str_starts_with($name, '_')) {
            throw new InvalidArgumentException(sprintf(
                'An SRV record name carries the service and protocol, decorated - '
                    . '"_sip._tcp.example.com", not "%s". Cloudflare takes them from the name, '
                    . 'not from the data components, and would otherwise store this as an '
                    . 'ordinary record that no service lookup finds.',
                $name
            ));
        }

        return $name;
    }

    private static function required(string $value, string $message): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($message);
        }

        return trim($value);
    }

    private static function inRange(int $value, int $minimum, int $maximum, string $field): int
    {
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'A record\'s %s must be between %d and %d; %d was given.',
                $field,
                $minimum,
                $maximum,
                $value
            ));
        }

        return $value;
    }
}
