v7.0.0
======

A rewrite to the [Centrifugo server SDK specification](https://github.com/centrifugal/gocent/blob/master/spec.md).
Migrating from phpcent 6: see [MIGRATING.md](MIGRATING.md).

* PHP 8.3 or newer, in the `Centrifugal\Phpcent` namespace. No dependencies but the PSR HTTP interfaces; ext-curl for the default transport, ext-openssl and ext-sodium for asymmetric tokens.
* A method for every API method, including the map and shared poll methods and those of Centrifugo PRO, generated from Centrifugo's `api.proto` with its documentation: it takes the request's fields as named arguments - `$client->publish(channel: 'news', data: $data)` - and returns a typed result. A batch has the same methods: `$batch->publish(...)` returns a `Pending`.
* One deadline per call - its `timeout`, or `requestTimeout` - over the whole request. Redirects are not followed. Connections are kept alive between calls.
* Any PSR-18 HTTP client instead of curl ([#55](https://github.com/centrifugal/phpcent/issues/55)): Guzzle, Symfony HttpClient, a fake in tests.
* Errors are exceptions: `ApiException` with a subclass per known code, `HttpException`, `UnauthorizedException`, `DecodeException`, `InvalidRequestException`, `InvalidConfigException`, `NetworkException`, `TimeoutException`, `ClosedException`; `Retry::retryable()`; hints in messages; Centrifugo's transport error mode.
* **Breaking:** a broadcast or batch in which some parts failed throws `BroadcastFailedChannelsException` or `BatchFailedCommandsException`, carrying every part's outcome - phpcent 6 returned the response with the errors inside. Catch them for best-effort sends: see [MIGRATING.md](MIGRATING.md#broadcasts-and-batches-now-throw). `broadcastWithRetry` retries only the channels which may still succeed, with one idempotency key.
* Payloads: PHP values or `RawJson`, sent as the same JSON value; replies give `stdClass`, which goes back exactly; NaN, infinities and binary strings refused before sending.
* Tokens: connection, subscription and Centrifugo PRO server API tokens with every claim (HS256, RS256, ES256/384/512, EdDSA), JWKS, `ApiTokens`.
* Endpoints for client SDKs (connection and subscription tokens, shared poll signatures, JWKS) and a handler for every proxy type, generated from `proxy.proto`. Each is a framework-free core with PSR-15 and plain PHP adapters.
* `Command::publish(...)` and the other methods, for Centrifugo's async consumers - `json_encode($command)` gives the payload as an object; `SharedPoll::sign` for shared poll signatures, its expiry required: a time, or `Expiry::Never`.
* Fields the protos mark required are required named arguments. Results render with Centrifugo's field names under `json_encode`, errors as `{code, message}`; `$result->failed` is a property.
* With a PSR-18 client, its timeouts throw `TimeoutException` and its TLS certificate errors are not retryable. `Pending::resolved` and the public constructors of results and partial-failure exceptions make test doubles.

Spec 0.3, changed since the earlier v7 development builds:

* phpcent implements spec 0.3 of the [Centrifugo server SDK specification](https://github.com/centrifugal/gocent/blob/master/spec.md). The protos are synced from Centrifugo's master, with the `One of:` sentences and `ConnectResult::$profile`: **they must be synced again from a Centrifugo release tag before phpcent's release.**
* **Changed:** a field the protos mark "Required." is checked for a value before sending, in a call, a batch and a command: `channel: ''`, `version: 0`, an empty `notification` or `new RateLimitPolicy(rate: 0, ...)` now throw `InvalidRequestException` naming the field's path - `limit_strategy.rate_limit.policies[0].rate is required` - where Centrifugo refused them with code 107. Fields whose empty value has a meaning (`invalidateUserTokens`'s and `userTopicUpdate`'s `user`, the device updates' values) are not checked; a payload's JSON null, `new RawJson('null')`, is a value.
* **Changed:** the nested messages take their required fields as constructor arguments without a default: `new RateLimitPolicy(rate: 1, intervalMs: 1000)`, `new DeviceTopicsUpdate(op: Op::ADD)`, `new FcmPushNotification(message: ...)`, `new DeviceUserUpdate(user: '')`.
* **Changed:** `deviceUpdate`'s `userUpdate`, `timezoneUpdate`, `localeUpdate` and `metaUpdate` are sent whenever set, even empty - `new DeviceUserUpdate(user: '')` detaches the devices from their user, an empty timezone, locale or meta clears it - where an empty one was left out. Any other message set but empty is still left out.
* Constants for the fields with listed values, a class per name of the spec's table: `Provider::FCM`, `Platform::IOS`, `Op::ADD`, `Status::DELIVERED`, `KeyMode::IF_NEW`, `FilterNodeOp::AND`, `Cmp::EQ`; in `Proxy`, `FilterNodeOp::AND`, `Cmp::EQ`, `Protocol::JSON`, `Encoding::BINARY`, `Allow::SUB`, `CapabilityMatch::REGEX`, `ChannelEventType::OCCUPIED`, `KeyMode::IF_NEW`. The names are pinned, never derived; the fields stay strings.
* `historyAll`, `deviceListAll`, `deviceTopicListAll`, `userTopicListAll` and `mapReadStateAll`: a `Generator` of every item, a page read when reached; `mapReadStateAll` reads later pages at the first one's revision. `position()` on the results of `publish`, `history`, `mapReadState`, `mapReadStream`, `mapPublish` and `mapRemove`.
* `ConnectionClaims::$profile`, the Centrifugo PRO compression profile claim.
* Docs: the redirect hint says Centrifugo's API never redirects a call - check the endpoint's scheme and path; commands' payloads go apart as JSON, never as a string of it; what Centrifugo makes of a proxy response with none of result, error and disconnect.

v6.0.0 - v6.0.3
===============

See the [releases page](https://github.com/centrifugal/phpcent/releases).
