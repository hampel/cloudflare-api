# Changelog

All notable changes to `hampel/cloudflare-api` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Client`, built on PSR-18 and PSR-17, with no HTTP library of its own. Factories are
  discovered when none are passed.
- `ApiToken` authentication. The Global API Key is not supported; `Authentication` is an
  interface so one can be added.
- `Tokens::verify()` — `GET /user/tokens/verify`, plus `verifyForAccount()` for an
  account-scoped token.
- `Zones`, read-only: `list`, `each`, `all`, `get`, `find`, `findByName`, `getByName`.
  `findByName()` verifies the returned zone matches what was asked for.
- `DnsRecords`: `list`, `each`, `all`, `ofType`, `named`, `get`, `find`, `create`, `patch`,
  `replace`, `delete`, `export`. `BoundDnsRecords` binds the zone id.
- `Accounts`, for diagnostics. `first()` and `find()` return null where the token lacks the
  permission, because Cloudflare publishes a token's permissions nowhere.
- `DnsRecord` with a named constructor per type, covering both families — the eight types
  that use `content` and the thirteen that use `data` components.
- `RecordQuery` for the query-parameter filters, including the four predicates each text
  field accepts.
- `Ttl`, where 1 means automatic rather than one second, and 60–86400 is validated before a
  request is sent.
- An exception per failure a caller can act on, each carrying Cloudflare's numeric codes and
  the JSON pointers naming the fields at fault.
- A `hampel/rig` harness: `verify`, `zones`, `export`, `errors`, and `records`, which is
  opt-in because it writes to real DNS.

### Notes

- A 2xx whose body reports `"success": false` is raised as a failure, as is a 2xx whose body
  is not the JSON envelope.
- `replace()` is an HTTP PUT and resets every field not in the payload. `patch()` is the
  partial update.
- Page size limits differ per endpoint and are validated per endpoint: 1–5,000,000 for DNS
  records, 5–50 for zones and accounts.
- Booleans are written into the query string as `true`/`false`. An unreadable filter is not
  an error on this API — it is ignored, and the whole collection comes back with a 200.
- The rate limit headers are parsed from their documented form. Whether they are present on
  an ordinary response is unverified; the `verify` exercise prints the raw headers.
