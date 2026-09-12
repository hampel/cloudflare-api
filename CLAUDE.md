# hampel/cloudflare-api

A PSR-18 client for the Cloudflare API, covering DNS management and token verification.
`README.md` is the usage documentation; this file is what a contributor needs to work on the
package itself.

## Commands

```bash
composer check      # lint, analyse, test - all three
composer test       # phpunit
composer analyse    # phpstan, level 10, across PHP 8.3 to 8.5
composer lint       # pint --test
composer format     # pint
```

## Architecture

The layers, outermost first:

| Class | Job |
|---|---|
| `Client` | entry point; constructs and memoises endpoints |
| `Endpoint\*` | one class per group of API paths; pagination lives in the base class |
| `Connection` | everything that touches HTTP, in one place |
| `Result\*` | what came back: `ApiResponse`, `Page`, `ResultInfo`, `ResponseMeta` |
| `Entity\*` | readonly value objects with `fromArray()` / `toArray()` |
| `Support\*` | `Cast`, `Json`, `Ttl`, `RecordQuery`, `Psr17Discovery` |

`Connection` is the only class that knows about HTTP. `Endpoint` subclasses call its `api*`
helpers — prefixed so subclasses keep `get()`, `create()` and `delete()` for themselves.

Adding an endpoint group means one `Endpoint` subclass and one accessor on `Client`. The base
class requires `minimumPageSize()`, `maximumPageSize()` and `collectionName()`, because
Cloudflare's page size limits differ per endpoint and there is no safe default.

## Things about this API that shaped the code

**Everything is wrapped.** Every response is
`{success, errors[], messages[], result, result_info?}`. `ApiResponse` unwraps `result` once
so no endpoint has to remember.

**The status is not the whole answer.** A 2xx whose body says `"success": false` is a real
shape here, and `Connection::send()` raises for it. So does a 2xx that is not JSON — read
permissively that becomes an empty array, which reaches the caller as "this zone has no
records".

**PUT and PATCH mean different things.** PUT replaces the record and resets every field not
sent; PATCH is partial. Both answer 200. `replace()` and `patch()` keep them apart and
`replace()` logs at warning.

**Two families of record type.** Eight use `content`; thirteen use a `data` object and have a
read-only generated `content`. `RecordType::usesData()` is the test, and `DnsRecord::toArray()`
emits only the one that type accepts — which is what lets a fetched record be sent back.

**Names are absolute.** `www.example.com`, not `www`; the apex is the zone name. The API
appends the zone to a bare label, which is why the entity refuses one — the forgiving
behaviour is what makes the mistake invisible.

**Filters are query parameters with dots in their names.** `name.endswith`, `tag.exact`. An
unrecognised one is ignored rather than refused, so the request succeeds and returns the
unfiltered collection. `RecordQuery` exists because that failure is silent.

**Booleans must be `true`/`false`, not `1`/`0`.** `Config::queryString()` handles it. Same
reason as above.

**Permissions are published nowhere.** No endpoint and no header reports what a token may do.
Verification says the credential is real and live and nothing more; anything that wants to
know what it reaches has to try. `Accounts::first()` degrades rather than raising for this
reason.

**TTL 1 is "automatic", served as 300 seconds.** Not one second. `Ttl` carries the rules.

## Tests

No network and no HTTP mocking library. `StubClient` implements PSR-18 — a one-method
interface — so the seam the package exposes to consumers is the seam the tests drive it
through. Guzzle is present only for its PSR-7 objects.

`TestCase` provides `envelope()`, `collection()` and `failure()` for the response shapes, and
`sentPath()`, `sentQuery()`, `sentParameters()` and `sentBody()` for asserting on the request.

`sentParameters()` splits the query string by hand rather than using `parse_str()`, which
rewrites a dot in a parameter name to an underscore — and every filter on this API is a dotted
name.

## Harness

`hampel/rig` exercises in `harness/`, run with `vendor/bin/rig <name>`. Exercising is not
testing: nothing asserts and nothing returns a verdict. They exist to drive real code against
the real API.

| Exercise | |
|---|---|
| `verify` | the token, the rate limit headers, and what it actually reaches |
| `zones` | find a zone by name; the status and nameservers that decide whether changes resolve |
| `export` | the BIND zone file, and why its line count exceeds the record count |
| `errors` | each failure branch against the live API |
| `records` | the full lifecycle. **Writes to real DNS**, and is opt-in twice over |

`records` needs `CLOUDFLARE_WRITE_RECORDS=yes`, and under an agent also
`CLOUDFLARE_AGENT_MAY_WRITE_RECORDS=1` on the command line. The second exists because the
first lives in the credential owner's `.env` and generally says yes, so an agent would
otherwise inherit an authorisation it never made.

## Version support

PHP 8.3 and up — every version with upstream security support. CI tests the corners: 8.3 with
`--prefer-lowest`, 8.3 current, and 8.5. PHPStan covers the whole range in one pass.

A separate CI job runs `composer-require-checker` with the dev dependencies present, then
PHPStan with them removed. The package must need nothing but the four PSR interfaces; a normal
analysis run has Guzzle installed and would not notice.

Adding a case to `RecordType` is a breaking change: an exhaustive `match` over it in a consumer
starts throwing.
