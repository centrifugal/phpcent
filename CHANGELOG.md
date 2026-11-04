v7.0.0
======

phpcent 7 is a rewrite to the [Centrifugo server SDK specification](https://github.com/centrifugal/gocent/blob/master/spec.md), version 0.3, whose reference implementation is gocent. It needs PHP 8.3 or newer and lives in the `Centrifugal\Phpcent` namespace; it works with Centrifugo v6 and Centrifugo PRO v6 - the map and shared poll methods need v6.8.0 or newer, `ApiException::$temporary` and `TooManyRequestsException` v6.10.0 or newer. Migrating from phpcent 6: see [MIGRATING.md](https://github.com/centrifugal/phpcent/blob/master/MIGRATING.md).

Generated from Centrifugo's protos at [e21aeb50](https://github.com/centrifugal/centrifugo/commit/e21aeb50ab6fc4d525fc817473574f728da6c3ad) on its master, after v6.10.2: the same messages as v6.10.2, with the comments the constants are generated from, and `ConnectResult.profile` for the connect proxy, which Centrifugo PRO newer than v6.10.2 reads.

**Client and calls**

* A method for every API method, the map, shared poll and Centrifugo PRO ones included, generated from Centrifugo's `api.proto` with its documentation. Each takes its request's fields as named arguments - `$client->publish(channel: 'news', data: $data)` - and returns a typed result. `rpc` is not offered: it calls server API extensions, which Centrifugo does not provide.
* No dependencies but the PSR HTTP interfaces. curl by default, keeping connections alive between calls (PHP 8.5's persistent curl share keeps them across requests of a PHP-FPM worker), or any PSR-18 client ([#55](https://github.com/centrifugal/phpcent/issues/55)): Guzzle, Symfony HttpClient, a fake in tests. A client you pass is never changed: credentials and headers go on each request.
* Options checked when the client is created, with `InvalidConfigException` naming the option and the fix: `apiEndpoint` or `apiEndpointFunc`, `apiKey` or `bearerTokenFunc`, `httpClient` and `httpFactory`, `curlOptions`, `requestTimeout`, `headers` (a `User-Agent` replaces `phpcent/7`), `onRequest`. An endpoint ending in a method name, `/api/publish`, is refused with a hint.
* `apiEndpointFunc` and `bearerTokenFunc` are called for each request. An endpoint failing `apiEndpoint`'s rules, or an empty or malformed token, fails the call with `InvalidConfigException` before sending.
* A field the protos mark "Required." is a named argument without a default - in the nested messages' constructors too: `new RateLimitPolicy(rate: 1, intervalMs: 1000)` - and is checked for a value before sending, in a call, a batch and a command: `channel: ''`, `version: 0` or an empty `notification` throws `InvalidRequestException` naming the field's path, `limit_strategy.rate_limit.policies[0].rate is required`. A field whose empty value has a meaning - `invalidateUserTokens(user: '')`, the device updates' values - is not checked; a payload's JSON null, `new RawJson('null')`, is a value.
* A nested message set but empty is not sent - an empty `limitStrategy` is no limit strategy. The presence wrappers are sent whenever set, even empty: `deviceUpdate(userUpdate: new DeviceUserUpdate(user: ''))` detaches the devices from their user, an empty timezone, locale or meta update clears it. `BoolValue` fields are `?bool`: `null` leaves the server's setting alone.
* Redirects are not followed; the error says Centrifugo's API never redirects a call, and to check the endpoint's scheme and path. `close()` refuses further calls with `ClosedException`.
* Results render with Centrifugo's field names under `json_encode` - `num_clients` - and errors in them as `{code, message}`.

**Timeouts**

* `requestTimeout`, 10 seconds by default, bounds every call: connecting, sending and the whole reply. A call's own `timeout` replaces it, longer or shorter. PHP has no deadline a call runs under, so nothing else shortens it. Zero is refused, as a negative is: it is not "no timeout". Any timeout throws `TimeoutException`.
* Guzzle and Symfony's `Psr18Client` get the call's deadline through their own API - Guzzle's `timeout` request option, Symfony's `max_duration` - and a reply whose body ended past it is a `TimeoutException`, not a cut reply. Another PSR-18 client has its own timeouts, which throw `TimeoutException` too.

**Errors**

* Every call throws unless Centrifugo did everything asked, all exceptions implementing `CentException`: `ApiException`, with `getCode()`, `$serverMessage`, `$temporary` and a subclass per known code (`UnknownChannelException`, `BadRequestException`, `NotFoundException`, `NotAvailableException`, `TooManyRequestsException`, `UnrecoverablePositionException`, `ConflictException`, `InternalException`...), an unknown code still an `ApiException`; `HttpException` with `$statusCode`, `$body` and `$headers`, `UnauthorizedException` for refused credentials; `DecodeException`; `InvalidRequestException`; `InvalidConfigException`; `NetworkException`; `TimeoutException`; `ClosedException`.
* `FunctionException`: what `apiEndpointFunc` or `bearerTokenFunc` threw, its message naming the function, `$function` the option, the function's exception its previous one.
* Hints in messages: a Centrifugo PRO method answered by Centrifugo OSS, a 404 from an endpoint without the API prefix, a 401. Centrifugo's transport error mode (`http_api.error_mode: transport`) gives the same exceptions as a 200 reply.

**Retries and idempotency**

* `Retry::retryable($e)`: temporary API errors, network failures, timeouts, 429 and 5xx, a partial failure with a retryable part; a `FunctionException` by the function's own exception. Not retryable: the request's own errors, `InvalidConfigException`, `ClosedException`, `DecodeException`, refused credentials and TLS certificates.
* `Retry::exponentialBackoff($base, $limit)`, with jitter. The docs explain idempotency keys: one per publication or broadcast, kept by the memory, Redis and PostgreSQL brokers, ignored by NATS.

**Broadcast and batch**

* A broadcast or batch in which some parts failed throws `BroadcastFailedChannelsException` or `BatchFailedCommandsException`, whose `$result` holds every part's outcome; `$result->failed` and `$result->replied` are properties. A partial failure is never the `ApiException` of its parts, also with one channel. A failure as a whole throws its own exception, the network's say.
* `retryableChannels()` names the channels a retry should go to. `broadcastWithRetry` retries only those, under one `idempotencyKey`, made when missing: `maxAttempts`, `attemptTimeout` - each attempt's own timeout, replacing `requestTimeout` for it, the call's `timeout` still ending it sooner - and `onAttemptError`, which gets a `BroadcastAttempt` and returns the wait or throws to stop.
* `$client->newBatch(parallel: ...)` has the client's methods, each returning a `Pending`; `send()` once, `BatchSentException` after, `BatchNotSentException` reading a `Pending` before. An invalid command fails the batch before sending, naming its index.
* No automatic batching: a PHP request makes its calls one after another.

**Pages and positions**

* `historyAll`, `deviceListAll`, `deviceTopicListAll`, `userTopicListAll` and `mapReadStateAll` take the method's arguments and return a `Generator` of every item, a page read when the loop reaches it. The request is checked at the call: `historyAll` refuses a `limit` below 1 and `reverse`.
* `historyAll` yields `HistoryItem`s - the publication and its stream's epoch, with `position()` - and `mapReadStateAll` yields `MapStateItem`s - the entry and the `state` position it was read at. `mapReadStateAll` reads every page after the first at the first one's revision, so that a map reset while reading throws `UnrecoverablePositionException` instead of mixing two states.
* The iterators take `pageTimeout`, bounding each page's request in place of `requestTimeout`, and no `timeout`: an iteration lasts as long as the loop.
* `position()` on the results of `publish`, `history`, `mapReadState`, `mapReadStream`, `mapPublish` and `mapRemove` gives the `StreamPosition` to read on from, as `since` or `recoverSince`.

**Constants**

* A class of string constants per field with listed values, named as the spec's table pins them: `Provider::FCM`, `Platform::IOS`, `Op::ADD`, `Status::DELIVERED`, `KeyMode::IF_NEW`, `FilterNodeOp::AND`, `Cmp::EQ`; in `Proxy`, `FilterNodeOp`, `Cmp`, `Protocol::JSON`, `Encoding::BINARY`, `Allow::SUB`, `CapabilityMatch::REGEX`, `ChannelEventType::OCCUPIED`, `KeyMode`. The fields stay strings: a value phpcent does not name is still sent.

**Payloads**

* Payloads are PHP values - arrays, `stdClass`, scalars, `JsonSerializable` - or `RawJson`, sent as its text, also inside a value. Replies give `stdClass`, which goes back as the same JSON value, `{}` and `{"0":"a"}` included; `Payload::toArray()` for arrays. NaN, infinities, strings which are not UTF-8 and invalid JSON are refused before sending.

**Tokens and JWKS**

* `Token::connection` and `Token::subscription` with every claim, `profile` included; HS256, RS256, ES256/384/512 and EdDSA keys (`Key::hmac`, `rsa`, `ecdsa`, `ed25519`, `fromPem`). An expiry is required: a time, or `Expiry::Never`.
* `Token::jwks` and the `Jwks` endpoint publish the public keys, for key rotation without restarting Centrifugo.
* Centrifugo PRO server API tokens: `Token::api`, and `ApiTokenSource`, whose `token` method is a client's `bearerTokenFunc`, reusing a token for half its lifetime.

**Endpoints, shared poll, proxy, commands**

* Endpoints client SDKs call: `ConnectionToken`, `SubscriptionToken`, `SharedPollSignature`, `Jwks`. Their function throws `ForbiddenException` to answer 403, which client SDKs report as their `UnauthorizedError`.
* `SharedPoll::sign` for shared poll signatures, its expiry required.
* A handler for every proxy type, generated from `proxy.proto`: connect, refresh, subscribe, sub refresh, publish, RPC, map publish and remove, shared poll refresh, and Centrifugo PRO's channel state and cache empty notifications. The function returns a result, or throws `ProxyError` or `Disconnect`; anything else is a 500 reported to `onError`.
* Every handler and endpoint is a framework-free core with PSR-15 (`psr15()`, `handlePsr()`) and plain PHP (`serve()`) adapters, with a body limit.
* `Command::publish(...)` and the other methods, for Centrifugo's async consumers: checked as a call is, `method` and `payload` for an outbox table, `toJson()` or `json_encode` for a whole message.

**Observability and testing**

* `onRequest` gets a `RequestInfo` after every request: method, commands, failed, duration, status, error.
* `Pending::resolved` and the public constructors of results and partial-failure exceptions make test doubles; a fake PSR-18 client tests code using phpcent without Centrifugo.

v6.0.0 - v6.0.3
===============

See the [releases page](https://github.com/centrifugal/phpcent/releases).
