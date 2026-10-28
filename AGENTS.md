# Working on phpcent

phpcent is the PHP SDK for Centrifugo's HTTP server API, written to the
[Centrifugo server SDK specification](https://github.com/centrifugal/gocent/blob/v4_dev/spec.md),
whose reference implementation is gocent. Applications call it on their hot
paths - often once per web request - so it has to be correct and hard to
misuse. Read this before changing code.

## Layout

| Path | What |
|---|---|
| `proto/api.proto`, `proto/proxy.proto` | Copies of Centrifugo's protos, from a released tag (`CENTRIFUGO_REF=v6.10.2 composer sync-proto`) |
| `gen/` | The generator: `Proto.php` parses the protos, `generate.php` writes `generated/`, `api_docs.php` writes `API.md` |
| `generated/` | Generated - never edited: the result and nested message types; `ClientMethods`, `BatchMethods`, `CommandMethods`, the methods taking each request's fields as named arguments; `Internal/` the request classes they build; `Proxy/` types |
| `API.md` | The API reference, generated from the docblocks by `composer docs` - never edited |
| `src/Client.php` | The client: configuration, sending, broadcast, `broadcastWithRetry` |
| `src/Internal/` | Encoding requests (`Encode`), decoding replies (`Decode`, `Reply`), validation, the curl and PSR-18 transports |
| `src/Exception/` | The exceptions; `src/Retry.php` has `retryable` and the backoff |
| `src/Batch.php`, `src/Pending.php`, `src/BroadcastResult.php` | Batches and broadcast results |
| `src/Http/` | The HTTP handlers' framework-free core and its PSR-15 and plain PHP adapters |
| `src/Endpoints/`, `src/Proxy/`, `src/Token/`, `src/SharedPoll.php` | Endpoints for client SDKs, proxy handlers, tokens, shared poll signatures |
| `tests/`, `tests/Integration/` | Unit tests against a fake Centrifugo; integration tests against a real one |
| `testdata/` | The Centrifugo config and the Centrifugo PRO compose file the integration tests use |

## Invariants

These are not preferences. A change that breaks one is wrong even if every test
passes; if you find yourself about to break one, stop and say so instead.

1. **The generated files are never edited.** Change `proto/` or `gen/`, then
   `composer generate`; `composer generate-check` fails when they are stale.
   After a change to a docblock or the public API, `composer docs`
   regenerates `API.md`; `composer check` fails when it is stale.
2. **A call throws unless it did everything asked.** A broadcast or batch in
   which some parts failed throws `BroadcastFailedChannelsException` or
   `BatchFailedCommandsException`, whose `result` holds every part's outcome.
   A partial error is never an `ApiException` of its parts - also with one
   channel.
3. **Errors follow Centrifugo's model.** A Centrifugo error is an
   `ApiException`, thrown as the subclass of its code when the code is known,
   and as `ApiException` itself otherwise. Centrifugo's transport error mode
   gives the same exception as a 200 reply.
4. **One deadline per call.** Its `timeout`, or `requestTimeout`, bounds the
   whole call: curl's `CURLOPT_TIMEOUT_MS` covers connecting, sending and the
   reply. A PSR-18 client the caller passed has its own timeouts - PSR-18
   cannot give a request a deadline - which the docs say. Any timeout throws
   `TimeoutException`.
5. **A payload is carried exactly.** Replies are decoded as objects
   (`json_decode($json, false)`), never associative arrays, so that a payload
   sent back unchanged is the same JSON value - `{}` and `{"0":"a"}`
   included. `RawJson` is sent as its text, also inside a value; NaN,
   infinities, strings which are not UTF-8 and invalid JSON never leave the
   client. Payloads are encoded apart and spliced into the request's JSON.
6. **A caller's HTTP client is never changed**: credentials and headers go on
   each request.
7. **A handler answers with exactly one of result, error and disconnect,** as
   its proxy type can carry them; anything else is a 500 reported to
   `onError`.
8. **PHP 8.3 is the floor.** PHPStan checks at `phpVersion: 80300`; no
   function or constant newer than 8.3 without a check (`array_any` is 8.4,
   `curl_share_init_persistent` 8.5 - guarded).

## Running things

```
composer install          # the dev tools
composer check            # generated code, API.md, php-cs-fixer, PHPStan at max, unit tests: what CI runs
composer test-integration # against Centrifugo on :8100
```

The integration tests need a Centrifugo set up as `testdata/` says:

```
docker compose -f testdata/centrifugo-pro.compose.yml up -d --wait
PHPCENT_TEST_PRO=1 composer test-integration
```

They serve the proxy handlers on port 3100 and an API JWKS on 3101 of the host,
with `php -S`, which Centrifugo reaches as `host.docker.internal`.

## Updating to a new Centrifugo API

1. `CENTRIFUGO_REF=vX.Y.Z composer sync-proto` - always a released tag.
2. Read the diff of `proto/`: a removed field is a breaking change.
3. `composer check`, then the integration tests against the new release.
4. Bump the Centrifugo image in `.github/workflows/ci.yml` and
   `testdata/centrifugo-pro.compose.yml`, and the versions table in the README
   when a feature needs the new release.

## Releasing

Push a tag, `v7.0.0` say: Packagist picks it up through its GitHub hook, and
`release.yml` checks the tag against the code, then creates the GitHub
release. `composer.json` has no `version`: the tag is the version.

## Things that look wrong but are deliberate

- **Named arguments, not request objects**: spec 0.2's "unified meaning,
  idiomatic spelling" - `$client->publish(channel: ..., data: ...)`,
  `$batch->publish(...)`, `Command::publish(...)`, generated with the same
  parameters. The request classes are internal. PHP cannot make arguments
  named-only: the docs say their order is not part of the API, and a field
  added to a proto may shift it - before `$timeout`, too.
- **Payloads in replies are `stdClass`.** Arrays would turn `{}` into `[]`
  when sent on: spec 3.4's PHP row.
- **curl by default, PSR-18 optional.** curl enforces the one deadline and
  keeps connections alive; PSR-18 is how applications bring their own client,
  and how they test code using phpcent (issue #55). `Client` stays `final`.
- **No automatic batching and no async client**: a PHP request makes its calls
  one after another (spec 8 allows leaving it out).
- **`ext-curl` is suggested, not required**: an application passing its own
  PSR-18 client needs no curl. Without either, creating a client fails with the
  fix in the message.
- **`rpc` is not offered**: it calls server API extensions, which Centrifugo
  does not provide.
- **`BoolValue` fields are `?bool`.** `null` leaves the server's setting alone,
  which `false` cannot say.
- **An oversized body is a 400**, as in gocent, not a 413.
