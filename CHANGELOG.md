CHANGELOG
=========

0.1.0 (2026-09-13)
------------------

First release.

* add `Client`, built on PSR-18 and PSR-17, with no HTTP library of its own. PSR-17 factories
  are discovered when none are passed
* add `ApiToken` authentication. The Global API Key is not supported; `Authentication` is an
  interface
* add `Tokens::verify()`, and `verifyForAccount()` for an account-scoped token
* add `Zones`, read-only: `list`, `each`, `all`, `get`, `find`, `findByName`, `getByName`.
  `findByName()` verifies the zone that comes back is the one asked for
* add `DnsRecords`: `list`, `each`, `all`, `ofType`, `named`, `get`, `find`, `create`,
  `patch`, `replace`, `delete` and `export`, and `BoundDnsRecords` to bind the zone id
* add `Accounts`. `first()` and `find()` return null where the token cannot read them
* add `DnsRecord`, with a named constructor per record type, covering both the eight types
  that carry their value in `content` and the thirteen that carry it in `data` components
* add `RecordQuery` for the query-parameter filters, with four predicates per text field
* add `Ttl`. A TTL of `1` means automatic and is served as 300 seconds; anything else must be
  60-86400, and is validated before a request is sent
* add an exception per failure a caller can act on, each carrying Cloudflare's numeric error
  codes and the JSON pointers naming the fields at fault
* `patch()` is a partial update. `replace()` is an HTTP PUT and resets every field not in the
  payload
* a 2xx reporting `"success": false` is raised as a failure, as is a 2xx whose body is not the
  JSON envelope
* page size limits are validated per endpoint: 1-5,000,000 for DNS records, 5-50 for zones and
  accounts
* add a `hampel/rig` harness: `verify`, `zones`, `export`, `errors` and `records`. `records`
  writes to real DNS and is opt-in
* requires PHP 8.3 or later. Tested against PHP 8.3, 8.4 and 8.5, and Guzzle 7 and 8
