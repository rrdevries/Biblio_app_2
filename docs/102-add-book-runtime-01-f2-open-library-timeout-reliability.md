# ADD-BOOK-RUNTIME-01-F2 — Open Library timeout reliability

Date: 2026-09-14

Status: **GO / CLOSED**

Task severity: **High**

Product: `v2.001`

Schema: `1024` unchanged

Biblio Core: `2.26.0`

Biblio UI: `0.19.0` unchanged

## 1. Pre-coding audit

The four-second timeout was not a shared provider setting. It was repeated as a
literal in seven `ProviderHttpRequest` constructions across five Open Library
adapters. Google Books independently used its own four-second literals.

The Open Library requests covered exact ISBN lookup, generic text Work search
and its bounded Editions follow-up, top-level Author and Work search, selected
Author to Works and selected Work to Editions. No other current Open Library
HTTP route was found.

`WordPressProviderHttpClient` passes the request timeout directly to
`wp_safe_remote_get`. WordPress/Requests/cURL use it as the response and connect
timeout; no lower built-in boundary reduces six seconds to four. WordPress
filters can still alter request arguments operationally, but no Biblio-owned
filter doing so exists in the checkout.

Only the exact-ISBN provider tests asserted timeout values directly before this
slice. Search and discovery tests already covered routes and typed failure
semantics but did not pin their request timeout.

## 2. Timeout policy and implementation

`OpenLibraryConfiguration::REQUEST_TIMEOUT_SECONDS` now owns one explicit
Open Library default of `6.0` seconds. All seven current Open Library request
sites use it. The shared HTTP abstraction and both Google Books adapters are
unchanged; Google Books remains at `4.0` seconds.

Six seconds is the smallest justified modest increase for the recorded runtime
evidence: the former four-second boundary produced a false failure at about
4.181 seconds, while earlier valid Open Library responses were observed around
3.3 seconds. The new value adds two seconds over the old budget while remaining
below the request object's existing ten-second upper bound and preserving
bounded wait before fallback.

No retry, backoff, second provider request, background polling, user-facing
setting, environment variable or DDEV-only timeout override was added.

## 3. Failure and fallback semantics

Deterministic tests retain `unavailable/timeout` for an explicit timeout result.
The first-sufficient orchestration still calls Google Books after an Open
Library timeout and retains that failure in provider attempts. A sufficient
Open Library candidate still prevents Google fallback. Search provider failures
remain typed and usable local results remain independent.

The WordPress adapter currently maps every `WP_Error`, including a transport
timeout, to `network_failure`; therefore a real WordPress timeout remains the
existing truthful `unavailable/network` result. Distinguishing its underlying
reason would be an observability redesign and is outside this slice.

## 4. Deterministic verification

The focused provider, Add Book orchestration and Search regression set passed:
`131 tests, 562 assertions`.

The tests prove:

- exact ISBN, generic text discovery plus Editions, Author/Work search,
  Author-to-Works and Work-to-Editions use `6.0` seconds;
- both exact-ISBN and text-search Google Books requests remain `4.0` seconds;
- Open Library timeout remains typed and invokes Google fallback exactly once;
- a sufficient Open Library result still invokes Google zero times; and
- Search routes retain their existing success/failure contracts.

## 5. Controlled runtime verification

The requested ISBN `9780670691999` already had a canonical local Edition in the
DDEV checkout. A normal Add Book lookup would therefore correctly stop at the
local-first boundary and make no provider request. No local record was altered
or removed. To exercise the intended live boundary, the production provider
orchestration used by Add Book was invoked directly with the production
configuration and `WordPressProviderHttpClient`; it created no lookup snapshot,
catalog record, Item or materialization.

Observed provider run:

| Provider | Request timeout | Elapsed | Outcome |
|---|---:|---:|---|
| Open Library | 6 s | 5.365 s | response; one incomplete candidate |
| Google Books | 4 s | 0.956 s | response rejected as `isbn_mismatch` |

Google fallback was invoked because the Open Library candidate was incomplete.
The final orchestration status was `candidates`, with one candidate and no
sufficient candidate. The valid Open Library response completed 1.365 seconds
after the old four-second boundary, directly proving that the old false-failure
cutoff no longer terminates this request.

## 6. Open Library Search sanity check

One read-only Work search for `The Green Mile` used the same six-second Open
Library default and completed successfully in 4.860 seconds. It returned ten
items and the existing continuation shape. No Search code or response contract
changed.

## 7. Remaining upstream/runtime limitations

Open Library latency and availability remain external. Calls exceeding six
seconds still fail through the existing typed provider path and normal fallback
behavior. Google Books may still reject an ISBN-mismatching response. The
existing ignored local `OPENSSL_CONF`/`biblio-openssl.cnf` interoperability
workaround is neither changed nor tracked by this slice.

## 8. Independent second review

The complete Core gate passed in 370 seconds: strict Composer metadata and
platform requirements, all PHP syntax, PHPStan, 660 unit tests with 2,668
assertions and the two existing PHPUnit notices, 507 integration tests with
5,878 assertions, WordPress smoke, manifest JSON and staged/unstaged whitespace.

The final diff was reread against the bounded objective, provider-neutral
architecture, timeout/failure contracts, fallback order and excluded scope.
The review confirms one OL-owned default, all and only current OL routes using
it, unchanged Google behavior, no retry or new configuration surface, unchanged
schema/REST/UI/candidate rules and no V1 or local TLS files in scope. No blocker
remains.

## 9. Verdict

**GO / CLOSED**
