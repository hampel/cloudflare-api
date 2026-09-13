# Cloudflare API client for PHP

[![Tests](https://github.com/hampel/cloudflare-api/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/cloudflare-api/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/cloudflare-api.svg?style=flat-square)](https://packagist.org/packages/hampel/cloudflare-api)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/cloudflare-api.svg?style=flat-square)](https://packagist.org/packages/hampel/cloudflare-api)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/cloudflare-api.svg?style=flat-square)](https://github.com/hampel/cloudflare-api/issues)
[![License](https://img.shields.io/packagist/l/hampel/cloudflare-api.svg?style=flat-square)](https://packagist.org/packages/hampel/cloudflare-api)

By [Simon Hampel](mailto:simon@hampelgroup.com)

A PHP client for the [Cloudflare API](https://developers.cloudflare.com/api/), built on
**PSR-18**. It covers DNS management — zones and records — and API token verification.

## Installation

```bash
composer require hampel/cloudflare-api
```

You also need a PSR-18 client and a PSR-17 factory. Guzzle provides both, 7 or 8:

```bash
composer require guzzlehttp/guzzle
```

## Usage

```php
use GuzzleHttp\Client as Guzzle;
use Hampel\Cloudflare\Api\Client;
use Hampel\Cloudflare\Api\Entity\DnsRecord;

$cloudflare = Client::withToken('MY-API-TOKEN', new Guzzle());

$cloudflare->verify();                       // does this token work?

$zone = $cloudflare->zones()->getByName('example.com');

foreach ($cloudflare->zones()->records($zone->id)->each() as $record) {
    echo $record->describe(), "\n";
}

$cloudflare->zones()->records($zone->id)
    ->create(DnsRecord::a($zone->fqdn('www'), '203.0.113.10')->proxy());
```

`withToken()` finds a PSR-17 factory for you — Guzzle's, Nyholm's or Diactoros', whichever is
installed. The long form names everything:

```php
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\Cloudflare\Api\Authentication\ApiToken;
use Hampel\Cloudflare\Api\Config;

$factory = new HttpFactory();   // PSR-17, fills both the request and stream roles

$cloudflare = new Client(
    new Config(pageSize: 50),   // 5-50 if you set it at all - see Pagination
    new ApiToken('MY-API-TOKEN'),
    new Guzzle(),
    $factory,
    $factory,
    $logger,                    // PSR-3, optional
);
```

Requests are logged at `debug` and failures at `error`; a write to DNS and a nearly-spent rate
limit are `warning`. The token is never logged: `ApiToken` keeps it out of `__toString()`,
`var_dump()` and stack traces.

The PSR-18 client is always passed and never discovered — which HTTP client issues the request
is a decision a host application may need to keep.

## Tokens, not the Global API Key

Only API tokens are supported. Create one at
[dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens) as a
**Custom Token**:

| To | Permission |
|---|---|
| read zones and records | `Zone / Zone / Read` and `Zone / DNS / Read` |
| change records | `Zone / DNS / Edit` |
| read accounts (optional) | `Account / Account Settings / Read` |

Restrict **Zone Resources** to the zones the token needs.

The legacy Global API Key is not supported. It cannot be scoped to a zone, cannot be verified,
and cannot be revoked without breaking everything else that holds it.
`Authentication` is an interface, so an implementation can be supplied if one is ever needed.

## Verifying a token

```php
$token = $cloudflare->verify();

$token->isActive();                 // real, and usable right now
$token->expiresWithinDays(30);
$token->summary();                  // one line, carrying no part of the secret
```

An invalid token raises `NotAuthenticatedException` rather than returning an object saying so —
whichever of the two ways Cloudflare refuses it. See below.

**Cloudflare reports a token's permissions nowhere** — not on this endpoint, not in a response
header. Verification says the credential is real and live, and nothing about what it may do.
The only way to find out is to try, which is why `Accounts::first()` returns `null` instead of
raising when the token cannot read accounts.

## Zones

Read-only. Listing and fetching are here; creating, deleting and reconfiguring a zone are not.

```php
$cloudflare->zones()->list();                     // one page
$cloudflare->zones()->each();                     // a generator over every page
$cloudflare->zones()->all();
$cloudflare->zones()->get($zoneId);
$cloudflare->zones()->find($zoneId);              // null when absent
$cloudflare->zones()->findByName('example.com');  // null when absent
$cloudflare->zones()->getByName('example.com');   // raises when absent
```

`findByName()` verifies the returned zone's name matches what was asked for. A filter the
server ignored would otherwise come back as a 200 carrying the wrong zone.

A zone id is a 32-character hex string, not the domain name.

```php
$zone->isActive();      // Cloudflare is answering for this domain
$zone->isPending();     // nameservers not yet pointed at Cloudflare
$zone->isPaused();      // zone-wide: every record served DNS-only
$zone->nameServers;
$zone->fqdn('www');     // www.example.com
$zone->fqdn('');        // example.com - the apex
```

**A pending zone accepts every DNS change and serves none of them.** Nothing errors. Check
`isActive()` before trusting a change to have taken effect.

## DNS records

```php
$records = $cloudflare->zones()->records($zoneId);

$records->list();
$records->each();
$records->all();
$records->ofType(RecordType::MX);
$records->named('www.example.com');
$records->get($recordId);
$records->find($recordId);              // null when absent
$records->create($record);
$records->patch($recordId, ['ttl' => 300]);
$records->replace($recordId, $record);
$records->delete($recordId);
$records->export();                     // the zone as a BIND file
```

The same operations are on `$cloudflare->records()` with the zone id as the first argument.

### Record names are absolute

`www.example.com`, not `www`. The apex is the zone name itself. `Zone::fqdn()` builds one from
a label.

### `patch()` and `replace()` are not interchangeable

`patch()` is an HTTP PATCH: the fields you give it change and the rest are left alone.

`replace()` is an HTTP PUT, and **every field not in the payload is reset to its default** —
the comment cleared, the tags dropped, the proxy turned off, the TTL returned to automatic.
The call answers 200 and reports none of it.

`patch()` is what almost every caller wants.

### Building records

```php
DnsRecord::a('www.example.com', '203.0.113.10');
DnsRecord::aaaa('www.example.com', '2001:db8::1');
DnsRecord::cname('blog.example.com', 'example.ghost.io');
DnsRecord::mx('example.com', 'mail.example.com', priority: 10);
DnsRecord::txt('_dmarc.example.com', 'v=DMARC1; p=quarantine');
DnsRecord::ns('sub.example.com', 'ns1.elsewhere.com');
DnsRecord::ptr('10.113.0.203.in-addr.arpa', 'www.example.com');
DnsRecord::caa('example.com', CaaTag::Issue, 'letsencrypt.org');
DnsRecord::srv('_sip._tcp.example.com', 'sip.example.com', port: 5060, priority: 10, weight: 5);

DnsRecord::of(RecordType::OPENPGPKEY, $name, $content);   // any other content type
DnsRecord::components(RecordType::TLSA, $name, [...]);    // any other data type
```

Then refine:

```php
$record->withTtl(3600)->withComment('why this exists')->withTags(['production']);  // tags need a paid plan
$record->proxy();     // A, AAAA and CNAME only
$record->unproxy();
```

### Two families of record type

Eight types carry their value in `content` as a string: **A, AAAA, CNAME, MX, NS, OPENPGPKEY,
PTR, TXT**.

The other thirteen carry it in a `data` object of components, and their `content` is
read-only — Cloudflare generates it and refuses any attempt to set it: **CAA, CERT, DNSKEY,
DS, HTTPS, LOC, NAPTR, SMIMEA, SRV, SSHFP, SVCB, TLSA, URI**.

`RecordType::usesData()` is the test. The named constructors already know the answer, and
`toArray()` emits only the field that type is allowed to send — so a record read from the API
can be sent straight back without its generated `content` being rejected.

An SRV record's service and protocol go in its **name**, decorated: `_sip._tcp.example.com`.

### TTL

`1` means automatic, which Cloudflare serves as 300 seconds — not one second. Anything else
must be 60 to 86400 (30 on Enterprise zones), and is checked before the request is sent.

A proxied record has no TTL of its own; Cloudflare forces automatic.

Tags are a paid feature. On a Free zone the quota is zero and a record carrying one is refused
with code `9300`, whose message — "exceeding the quota of 0" — reads like a complaint about
the request rather than about the plan.

```php
Ttl::effective($record->ttl);   // 300 for automatic
Ttl::describe(1);               // "automatic (300s)"
$record->effectiveTtl();
```

### Filtering

Cloudflare filters with query parameters, and every text field takes four predicates:

```php
$query = RecordQuery::make()
    ->type(RecordType::A)
    ->nameEndsWith('.example.com')
    ->contentContains('203.0.113')
    ->proxied(false)
    ->tagged('production')
    ->orderBy('name', 'desc');

$records->all($query);
```

`nameIs()`, `nameContains()`, `nameStartsWith()`, `nameEndsWith()` — and the same four for
`content`, `comment` and `tag`. `matchAny()` switches the query from AND to OR; `tagMatchAny()`
does the same for tag conditions, which combine separately.

An unrecognised filter is not an error on this API — it is ignored, and the whole collection
comes back with a 200. Measured: `?no_such_filter=x` against a zone returned every record in
it. `RecordQuery` refuses an empty condition value and an unorderable field for that reason.

## Pagination

```php
$page = $records->list(page: 2, pageSize: 100);

$page->items;
$page->count();        // on this page
$page->total();        // across every page
$page->hasMore();
$page->currentPage();
$page->lastPage();
```

`each()` walks every page lazily — stopping early stops making requests.

**Page size limits differ per endpoint.** DNS records accept 1 to 5,000,000 and default to 100.
Zones and accounts accept 5 to 50 and default to 20. A size outside the range is refused before
the request is sent.

A walk is a sample, not a snapshot: each page is its own request. Order explicitly, and
de-duplicate by id where completeness matters.

## Errors

Every exception implements `Hampel\Cloudflare\Api\Exception\ExceptionInterface`.

| Exception | Meaning |
|---|---|
| `ValidationException` | 400 — a value was rejected |
| `NotAuthenticatedException` | the credential is missing, wrong, unparseable or revoked |
| `NotPermittedException` | 403 — the token lacks a permission or the resource is outside it |
| `NotFoundException` | 404 — no such DNS record, or no such path |
| `ConflictException` | 409 — a record that cannot coexist with what is there |
| `TooManyRequestsException` | 429 |
| `ServerException` | 5xx |
| `ClientException` | any other failure |
| `MalformedResponseException` | a 2xx whose body is not the JSON envelope |
| `RequestException` | the request never got an answer |
| `InvalidArgumentException` | refused before a request was made |

All but the last two extend `ApiException`:

```php
catch (ValidationException $e) {
    $e->statusCode;
    $e->codes();             // Cloudflare's numeric codes
    $e->messages();
    $e->hasCode(81057);
    $e->fieldErrors();       // ['ttl' => ['Invalid TTL'], 'data.tag' => [...]]
    $e->concerns('ttl');
}
```

Branch on `hasCode()` rather than on a message. The codes are documented and stable; the
messages are prose.

### A bad credential arrives two ways

Cloudflare refuses a token it cannot parse *before* authentication runs, so that failure wears a
`400` rather than the `401` a well-formed but wrong token gets. Measured against the live API:

| the token | HTTP | code |
|---|---|---|
| right shape, wrong value | `401` | `1000` |
| unparseable — a placeholder, a truncated value, a stray `Bearer ` prefix | `400` | `6003` |

Both raise `NotAuthenticatedException`. The second is the likelier failure in practice, and the
only one whose status suggests the request was at fault rather than the credential — so catching
`ValidationException` for it, as the status invites, sends you to inspect a payload that is fine.

### Absence is reported three different ways

Measured against the live API:

| | |
|---|---|
| a DNS record that does not exist | `404`, code `81044` |
| a zone that does not exist, or is not yours | `403`, code `9109` |
| an id that is not even the right shape | `400`, code `7000` |

So a missing zone arrives as `NotPermittedException`, not `NotFoundException` — Cloudflare
will not confirm which zone ids exist to a credential that cannot see them.

`Zones::find()` absorbs the `9109` case and returns `null`. It does **not** absorb a 403
carrying code `10000` — "Authentication error", a token whose permissions or resources do not
cover the zone — because reporting a misconfigured credential as "the zone does not exist"
sends whoever chases it to the wrong place.

### `success: false` on a 200

This API carries its own success flag, and a 2xx whose body says `"success": false` is a real
shape. It is raised as a failure rather than returned as an empty result.

A 2xx whose body is not JSON is raised too, for the same reason: read permissively, a
maintenance page or proxy error document becomes an empty array, which reaches the caller as
"this zone has no records".

## Rate limits

1200 requests per five minutes. Exceeding it blocks every call for the next five minutes,
not only the one that went over.

**The headers are sent per endpoint, not on every response.** `GET /zones` carries
`Ratelimit: "list_zones";r=1200;t=1` and `Ratelimit-Policy: "list_zones";q=1201;w=300`;
`GET /user/tokens/verify` carries neither. The policy is named after the operation, so a
limit read from one endpoint says nothing about another.

```php
$meta = $response->meta;

$meta->rateLimit;
$meta->rateLimitRemaining;
$meta->rateLimitResetsIn;
$meta->isNearingRateLimit();   // false when the headers were absent
$meta->ray;                    // CF-Ray, for a support ticket
```

A missing header reads as unknown, never as exhausted.

## Bringing your own HTTP client

Anything implementing PSR-18 works, which is the point of the package: an application with its
own proxy-aware, SSRF-guarded HTTP stack shares this code rather than needing a second client.
It is also what lets a Laravel integration route this traffic through `Http::fake()`.

## Endpoints not yet wrapped

Most of them. This package covers DNS and token verification; Cloudflare's API has some two
thousand paths. The rest are reachable without waiting for a release:

```php
$cloudflare->connection()->get('zones/' . $zoneId . '/settings/ssl')->object();
```

For anything called more than once, ship an `Endpoint` subclass:

```php
final class Firewall extends Endpoint
{
    public function rules(string $zoneId): \Generator
    {
        return $this->apiEach('zones/' . $zoneId . '/firewall/rules', static fn (array $row) => $row);
    }

    protected function minimumPageSize(): int { return 1; }
    protected function maximumPageSize(): int { return 500; }
    protected function collectionName(): string { return 'firewall rules'; }
}

$cloudflare->endpoint(Firewall::class)->rules($zoneId);
```

There is nothing to register. Pagination, error handling and the envelope come with the base
class.

## Versioning and support

Semantic versioning. `1.0.0` declares the public API stable.

```json
"hampel/cloudflare-api": "^1.0"
```

That is `>=1.0.0 <2.0.0`. **Write `^1.0`, not `~1.0.0`** — the tilde means `>=1.0.0 <1.1.0`,
which resolves only patch releases.

- **PHP 8.3 or later.** Tested against 8.3 (including at the lowest resolvable dependency set),
  8.4 and 8.5, and against Guzzle 7 and 8.
- **1.x is supported.** Fixes land on the current minor.
- **0.x is not.** See the CHANGELOG for the two changes between `0.1.2` and `1.0.0`.

### What "stable" covers, and the one place it deliberately does not

A breaking change to a class, method or method signature in `src/` means `2.0.0`.

**`RecordType` is the exception.** It mirrors Cloudflare's own list of record types, which this
package does not control, so a type Cloudflare adds appears here **in a minor release**.

**Every `match` over `RecordType` needs a `default` arm.** Without one, a new Cloudflare record
type is a fatal `UnhandledMatchError`.

Until that minor lands, a record of the new type reads with `$type` as `null` rather than as
some other type. It can be read — `raw` holds everything Cloudflare sent — and cannot be written
back, because where its value lives, whether it can be proxied and whether it has a priority are
all properties of the type.

`CaaTag`, `ZoneStatus`, `ZoneType` and `TokenStatus` behave the same way: an unrecognised value
is `null`, never a guess and never an exception.

### Cloudflare's payloads: the container is stable, the contents are not

Applies to `Entity::$raw`, `DnsRecord::$data`, `ApiResponse::$envelope` and the numeric codes on
`ApiError`.

**Covered by the major version:**

- `ApiException::$errors` exists on every subclass, is `public readonly`, and is always a
  `list<ApiError>` — `[]` when the response carried no errors or was not JSON at all. Never
  `null`.
- likewise `$statusCode` (`int`), `$body` (`string`, the raw response) and `$retryAfter`
  (`?int`).
- `ApiError::$code` (`int`), `$message` (`string`), `$pointer` and `$documentationUrl`
  (`?string`).
- `$raw` exists on every entity and is an `array<string, mixed>`; `DnsRecord::$data` likewise.

**Not covered:** what is *inside* `$raw`, `$data` and `$envelope`, and which numeric code
Cloudflare uses for which failure. Those are Cloudflare's payload, passed through with no
reshaping beyond dropping entries of the wrong type. A field renamed inside a record's `data`
does not produce a major here. Read them with `??`:

```php
if ($e instanceof ValidationException) {
    foreach ($e->errors as $error) {
        $log->warning($error->message, ['code' => $error->code, 'field' => $error->field()]);
    }
}
```

**This package promises the shape it built, not the shape Cloudflare sent.** Where the two meet
— an enum of Cloudflare's record types, a `data` object of Cloudflare's components — the
container is covered by the major version and the contents are not.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
