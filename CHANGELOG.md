v7.0.0
======

A rewrite to the [Centrifugo server SDK specification](https://github.com/centrifugal/gocent/blob/v4_dev/spec.md).
Migrating from phpcent 6: see [MIGRATING.md](MIGRATING.md).

* PHP 8.3 or newer, in the `Centrifugal\Phpcent` namespace. No dependencies but the PSR HTTP interfaces; ext-curl for the default transport, ext-openssl and ext-sodium for asymmetric tokens.
* Requests and results are classes generated from Centrifugo's `api.proto`, with its documentation: every API method, including the map and shared poll methods and those of Centrifugo PRO.
* One deadline per call - its `timeout`, or `requestTimeout` - over the whole request. Redirects are not followed. Connections are kept alive between calls.
* Any PSR-18 HTTP client instead of curl ([#55](https://github.com/centrifugal/phpcent/issues/55)): Guzzle, Symfony HttpClient, a fake in tests.
* Errors are exceptions: `ApiException` with a subclass per known code, `HttpException`, `UnauthorizedException`, `DecodeException`, `InvalidRequestException`, `InvalidConfigException`, `NetworkException`, `TimeoutException`, `ClosedException`; `Retry::retryable()`; hints in messages; Centrifugo's transport error mode.
* A broadcast or batch in which some parts failed throws `BroadcastFailedChannelsException` or `BatchFailedCommandsException`, carrying every part's outcome. `broadcastWithRetry` retries only the channels which may still succeed, with one idempotency key.
* Payloads: PHP values or `RawJson`, sent as the same JSON value; replies give `stdClass`, which goes back exactly; NaN, infinities and binary strings refused before sending.
* Tokens: connection, subscription and Centrifugo PRO server API tokens with every claim (HS256, RS256, ES256/384/512, EdDSA), JWKS, `ApiTokens`.
* Endpoints for client SDKs (connection and subscription tokens, shared poll signatures, JWKS) and a handler for every proxy type, generated from `proxy.proto`. Each is a framework-free core with PSR-15 and plain PHP adapters.
* `Command::from` for Centrifugo's async consumers; `SharedPoll::sign` for shared poll signatures.

v6.0.0 - v6.0.3
===============

See the [releases page](https://github.com/centrifugal/phpcent/releases).
