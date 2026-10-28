# phpcent

PHP SDK for the [HTTP server API](https://centrifugal.dev/docs/server/server_api)
of [Centrifugo](https://github.com/centrifugal/centrifugo): every API method,
including those of Centrifugo PRO; connection, subscription and server API
tokens; the proxy handlers and the endpoints client SDKs call.

```
composer require centrifugal/phpcent
```

| | Version |
|---|---|
| PHP | 8.3 or newer; ext-curl for the default transport, ext-openssl for RSA and ECDSA tokens, ext-sodium for Ed25519 tokens |
| Centrifugo | v6, any release |
| Map and shared poll methods | Centrifugo v6.8.0 or newer |
| `ApiException::$temporary`, `TooManyRequestsException`, refused requests reported as such rather than as internal errors | Centrifugo v6.10.0 or newer |
| Ed25519 tokens verified without JWKS, by `client.token.ed25519_public_key` | Centrifugo v6.10.2 or newer |
| Methods marked "Centrifugo PRO only" | Centrifugo PRO v6 |

phpcent 7 replaces phpcent 6: see [MIGRATING.md](MIGRATING.md). phpcent
implements the
[Centrifugo server SDK specification](https://github.com/centrifugal/gocent/blob/v4_dev/spec.md),
version 0.2.

## Example

```php
use Centrifugal\Phpcent\Client;

$client = new Client('http://localhost:8000/api', apiKey: getenv('CENTRIFUGO_API_KEY'));

$res = $client->publish(channel: 'news', data: ['text' => 'hello']);
echo "published at offset {$res->offset}\n";
```

There is a method for every API method, generated from Centrifugo's
`api.proto` with its documentation. It takes the fields of the method's
request as named arguments - pass them by name: their order follows
`api.proto` and is not part of the API - and returns its result, throwing
unless Centrifugo did everything asked. Create the client once and reuse it:
it keeps its connection to Centrifugo alive between calls.

## Payloads

`data`, `info`, `meta` and the other payloads are JSON values, given as the
PHP values `json_encode` writes - arrays, `stdClass`, scalars, `null`,
`JsonSerializable`:

```php
$client->publish(channel: 'news', data: ['text' => 'hi', 'tags' => ['a', 'b']]);
```

Replies give payloads as `json_decode` gives them, objects as `stdClass` - so
a payload from a reply sent back unchanged is the same JSON value, `{}` and
`{"0": "a"}` included, which arrays would turn into `[]` and `["a"]`:

```php
$pub = $client->history(channel: 'news', limit: 1)->publications[0];
echo $pub->data->text;
$client->publish(channel: 'archive', data: $pub->data);   // exactly the same
```

To read a payload as arrays, `Payload::toArray()` turns its objects into
associative arrays, as `json_decode($json, true)` would have:

```php
$data = Payload::toArray($pub->data);
echo $data['text'], ' by ', $data['user']['name'];
```

Read the array, but send the payload itself back: an empty object or one with
keys `"0"`, `"1"`... would go out as a JSON array.

A PHP string is a JSON string. JSON you already have, or encode with rules of
your own, goes in `RawJson`, sent as it is - also inside an array:

```php
$client->publish(channel: 'news', data: new RawJson($row['payload_json']));
$client->publish(channel: 'news', data: ['meta' => $meta, 'doc' => new RawJson($json)]);
```

An empty array is a JSON array, `[]`; an empty object is `new \stdClass()`.
A payload which is not JSON - NaN, infinities, a string which is not UTF-8 -
throws `InvalidRequestException` before anything is sent. Binary data goes
base64-encoded in the `b64` fields, such as `b64data`.

Write integers which may pass 2^53 - a 64-bit ID, say - as JSON strings:
browsers and JavaScript backends decode JSON numbers as doubles, and round
larger integers silently. PHP's own `int` stops at 2^63.

## Timeouts

Each call takes a `timeout`, in seconds; without one, `requestTimeout` - ten
seconds by default - bounds it:

```php
$client->info(timeout: 0.5);
```

The timeout bounds the whole call - connecting, sending, the reply's headers
and body - and however a call times out, it throws `TimeoutException`.
`broadcastWithRetry` makes several requests in one call: its `timeout` bounds
them all, waits between them included, and `attemptTimeout` each one.

## Errors

| Exception | Meaning |
|---|---|
| `ApiException` | Centrifugo refused the request or failed to carry it out: `getCode()`, `$serverMessage`, `$temporary`. Known codes are subclasses: `UnknownChannelException`, `NotFoundException`, `BadRequestException`, `NotAvailableException`, `TooManyRequestsException`, `InternalException`... |
| `BroadcastFailedChannelsException`, `BatchFailedCommandsException` | some parts of a broadcast or batch failed - see below |
| `HttpException` | the request did not reach the API method: `$statusCode`, `$body`, `$headers`; `UnauthorizedException` for rejected credentials |
| `DecodeException` | the reply was not Centrifugo's - usually `apiEndpoint` points elsewhere |
| `InvalidRequestException` | the client could tell the request is wrong and did not send it |
| `InvalidConfigException` | the client was not created: the message names the option and the fix |
| `NetworkException`, `TimeoutException`, `ClosedException` | as their names say |

They live in `Centrifugal\Phpcent\Exception`, and all implement
`CentException`. Centrifugo adds error codes over time: an unknown code is
still an `ApiException`, so match the codes you handle and treat the rest by
class.

## Retries

`Retry::retryable($e)` reports whether a new attempt, with time left, may
succeed: the error came from a temporary condition - a broker being
unavailable, a rate limit, the network - not from the request.

A temporary error does not mean nothing was done: after a timeout, or even an
internal error, the publication may have happened. So retry a publication only
with an `idempotencyKey`, and back off between a bounded number of attempts:

```php
$backoff = Retry::exponentialBackoff(0.2, 5.0);
for ($attempt = 1; ; $attempt++) {
    try {
        // The same key on every attempt: a publication which happened in an
        // earlier attempt, even one reported as failed, is not published again.
        $client->publish(channel: 'orders:42', data: $data, idempotencyKey: 'order-42-paid');
        break;
    } catch (CentException $e) {
        if (!Retry::retryable($e) || $attempt === 3) {
            throw $e;
        }
    }
    usleep((int) ($backoff($attempt) * 1e6));
}
```

Choosing keys:

- One key per publication or broadcast, reused only to retry that same one. A
  broadcast needs one key, not one per channel.
- Centrifugo remembers a key per channel, for five minutes by default. A retry
  within that time is answered with the first result and not published again -
  and so is a *different* publication into the same channel under the same
  key. A key built from an ID must name the event too: `order-42-paid`, not
  `order-42`.
- The memory, Redis and PostgreSQL brokers keep keys. The NATS broker ignores
  them, so with it a retry publishes again.

Retry a broadcast with `broadcastWithRetry` rather than a loop: see below.

## Broadcast and batch

`broadcast` publishes the same data into many channels in one request; a batch
sends many commands at once. Both throw unless every part succeeded:

```php
$client->broadcast(channels: ['user:1', 'user:2'], data: ['text' => 'hello'], idempotencyKey: $messageId);

$batch = $client->newBatch();
$pub = $batch->publish(channel: 'news', data: $data);     // the client's methods, without timeout
$stats = $batch->presenceStats(channel: 'news');
$batch->send();
$pub->result()->offset;        // each command has its own result, or throws its own error
$stats->result()->numClients;
```

Parts succeed or fail on their own. When Centrifugo carried the request out
and some parts failed, up to all, it throws `BroadcastFailedChannelsException`
or `BatchFailedCommandsException`: its `$failed` lists them, and its `$result`
holds every part's outcome. Any other exception means the request failed as a
whole - the network, the credentials, an invalid request - and no part got a
reply; after a timeout or a network error Centrifugo may still have carried it
out.

A partial error is not an error of its parts: `catch (UnknownChannelException)`
does not catch a broadcast in which a channel was unknown, nor one into a
single channel - use `publish` for one channel. To go on despite some failed
parts:

```php
try {
    $client->broadcast(channels: $channels, data: $data);
} catch (BroadcastFailedChannelsException $e) {
    $logger->warning('broadcast failed in some channels', ['failed' => $e->result->failed()]);
}
```

Retrying:

- a **broadcast**: `broadcastWithRetry` does it. It sends again only the
  channels which may still succeed, all attempts with one `idempotencyKey` -
  made for the call when none is given - and returns every channel's
  last outcome, as `broadcast` does:

  ```php
  $res = $client->broadcastWithRetry(channels: $channels, data: $data, maxAttempts: 5, attemptTimeout: 2.0, timeout: 10.0);
  ```

  By default it retries once, after about 100 milliseconds. `onAttemptError`
  is called after each failed attempt which will be retried, with a
  `BroadcastAttempt` - the channels to retry and their errors: it returns the
  seconds to wait before the next attempt - or `null` for the default
  backoff - and may log or count meanwhile; it throws to stop. An
  `HttpException` in `$a->error` carries the response's `$headers`: wait at
  least its `retry-after` to honour one.

  ```php
  $backoff = Retry::exponentialBackoff(0.2, 5.0);
  $res = $client->broadcastWithRetry(
      channels: $channels,
      data: $data,
      onAttemptError: function (BroadcastAttempt $a) use ($logger, $backoff): float {
          $logger->info("attempt {$a->attempt}: " . count($a->retry) . ' channels to retry');
          return $backoff($a->attempt);
      },
  );
  ```

  `$res->idempotencyKey` is the key the attempts used: to retry the broadcast
  later, after it gave up - from a job queue, say - send it with that key.
  Repeating `broadcast` on any error would instead send it to every channel
  again, those which already got it included.

  It waits between attempts with `usleep`, in the request calling it. In a web
  request, give it a short `timeout`, so that a Centrifugo which is down does
  not hold the request - and the PHP-FPM worker - for every attempt's
  `requestTimeout`. Retry for longer from a job queue: give the broadcast an
  `idempotencyKey` of its own, so that the job retries under the same key
  whichever way the call failed, and hand the job what failed:

  ```php
  $key = "message-{$messageId}";
  try {
      $client->broadcastWithRetry(channels: $channels, data: $data, idempotencyKey: $key, timeout: 2.0);
  } catch (BroadcastFailedChannelsException $e) {
      if ($e->retryableChannels() !== []) {
          $queue->push(new RetryBroadcast($e->retryableChannels(), $data, $key));
      }
  } catch (CentException $e) {
      if (Retry::retryable($e)) {   // failed as a whole: the network, a timeout
          $queue->push(new RetryBroadcast($channels, $data, $key));
      }
  }
  ```
- a **batch**: build a new one, with the same keys, from the commands
  `$e->failed` lists with a retryable error. A batch is sent once.

`newBatch()` runs the commands one by one, in order; `parallel: true` runs
them concurrently. Centrifugo PRO sends a batch's publications to its broker
together: each channel's publications still take effect in order, but
publications into different channels may not, unless the namespace sets
`publication_grouping_disabled`.

phpcent has no automatic batching of concurrent calls, which the
specification describes for languages where a process makes many calls at
once: a PHP request makes its calls one after another. To send many calls
in one request, use a batch.

## Authentication

`apiKey` is sent in the `X-API-Key` header. `bearerTokenFunc` instead sends a
token as `Authorization: Bearer`, for Centrifugo PRO's JWKS authentication - it
is called for every request, so return a cached token. Leave both unset when
mutual TLS, set up in `curlOptions` or `httpClient`, or a proxy authenticates
instead.

To sign the tokens yourself, `ApiTokens` issues them and reuses each for half
its lifetime:

```php
use Centrifugal\Phpcent\Token\{ApiClaims, ApiTokens, Key};

$apiTokens = new ApiTokens(
    claims: new ApiClaims(audience: 'centrifugo-api', scope: ['centrifugo:api']),
    key: $apiKey,   // its own key - never one of the client tokens
);
$client = new Client('https://centrifugo.example.com/api', bearerTokenFunc: $apiTokens->token(...));
```

Centrifugo PRO accepts a token signed with any key of `http_api.jwks.endpoint`,
and checks the audience, issuer and scope only when configured to. So keep API
tokens apart from the connection and subscription tokens your users hold - or
any user can call the server API with their connection token:

- publish the API keys with `new Jwks(...$apiKeys)` at an endpoint of their
  own, not the one of `client.token.jwks_public_endpoint`;
- set `http_api.jwks.audience` and `http_api.jwks.scope` to values only API
  tokens carry - `Token::api` refuses claims with neither.

The API keys rotate as client keys do: see [Key rotation with JWKS](#key-rotation-with-jwks).

Rejected credentials make every call throw `UnauthorizedException`.

## HTTP transport

Without `httpClient`, phpcent sends requests with ext-curl, keeping its
connection to Centrifugo alive between calls - on PHP 8.5, between the requests
a PHP-FPM worker serves too - closing idle ones after 50 seconds, and never
following redirects. `curlOptions` adds options of your own, as phpcent 6's
setters did:

```php
$client = new Client('https://centrifugo.example.com/api', apiKey: $key, curlOptions: [
    CURLOPT_CAINFO => '/path/to/ca.pem',          // a CA of your own
    CURLOPT_SSLCERT => '/path/to/client.pem',     // mutual TLS
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,       // IPv4 only
]);
```

Any [PSR-18](https://www.php-fig.org/psr/psr-18/) client works instead - Guzzle,
Symfony HttpClient, an instrumented one - with
[PSR-17](https://www.php-fig.org/psr/psr-17/) factories for its requests:

```php
$client = new Client($endpoint, apiKey: $key, httpClient: new \GuzzleHttp\Client(), httpFactory: new \GuzzleHttp\Psr7\HttpFactory());
$client = new Client($endpoint, apiKey: $key, httpClient: new \Symfony\Component\HttpClient\Psr18Client());   // its own factories
```

It is used as it is, and never changed: credentials and headers go on each
request. PSR-18 has no way to give a request a deadline, so with your own
client its timeouts apply, not phpcent's - set them on the client - and so
does its redirect policy.

Tracing comes from the HTTP client: an OpenTelemetry-instrumented PSR-18
client, say. `onRequest` is told of every request - method, commands and how
many failed, duration, status, error - for metrics without a dependency of
phpcent's.

## Testing your code

Give the client a fake PSR-18 client, which answers as Centrifugo would - your
tests then see the requests phpcent really sends and decode replies as phpcent
really does:

```php
use Nyholm\Psr7\{Factory\Psr17Factory, Response};
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};

$centrifugo = new class implements ClientInterface {
    /** @var list<RequestInterface> */
    public array $sent = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        return new Response(200, [], '{"result":{"offset":1,"epoch":"e"}}');
    }
};
$client = new Client('http://centrifugo.test/api', httpClient: $centrifugo, httpFactory: new Psr17Factory());

$notifier = new OrderNotifier($client);
$notifier->paid($order);

self::assertSame('/api/publish', $centrifugo->sent[0]->getUri()->getPath());
self::assertSame('order-42-paid', json_decode((string) $centrifugo->sent[0]->getBody())->idempotency_key);
```

`php-http/mock-client` is a ready-made one. For an error, answer as
Centrifugo does: `{"error":{"code":102,"message":"unknown channel"}}`.

## Shutdown

`$client->close()` refuses new calls with `ClosedException` and closes its
connections. A broadcast being retried stops at its next attempt.

## HTTP handlers

The proxy handlers and the endpoints client SDKs call have a framework-free
core - `$handler->handle($method, $headers, $body)` returning an
`HttpResponse` - and serve PSR-7 and PSR-15 frameworks with
`$handler->handlePsr()` and `$handler->psr15()`, and a plain PHP script with
`$handler->serve()`. Each function receives an `HttpRequest`: the method,
headers, query and, in `raw`, what you passed - the framework's request, or
the user it authenticated.

```php
// Laravel, in routes/api.php
Route::post('/centrifugo/connect', function (Illuminate\Http\Request $request) use ($connectHandler) {
    $res = $connectHandler->handle($request->method(), $request->headers->all(), $request->getContent(), raw: $request->user());
    return response($res->body, $res->status, $res->headers);
});

// Symfony, in a controller
#[Route('/centrifugo/connect', methods: ['POST'])]
public function connect(Request $request): Response
{
    $res = $this->connectHandler->handle($request->getMethod(), $request->headers->all(), $request->getContent(), raw: $this->getUser());
    return new Response($res->body, $res->status, $res->headers);
}

// Slim, Mezzio and other PSR-15 frameworks
$app->post('/centrifugo/connect', $connectHandler->psr15(new Psr17Factory()));

// A plain PHP script, centrifugo_connect.php
require 'vendor/autoload.php';
$connectHandler->serve();
```

## Proxy

The `Centrifugal\Phpcent\Proxy` namespace is the backend side of Centrifugo's
HTTP proxy, which calls your application when a client connects, subscribes,
publishes or makes an RPC call: a handler per proxy type, taking a function.

```php
use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Proxy\{ProxyError, SubscribeHandler, SubscribeRequest, SubscribeResult};

$subscribeHandler = new SubscribeHandler(function (SubscribeRequest $req, HttpRequest $http): SubscribeResult {
    if (!canRead($req->user, $req->channel)) {
        throw new ProxyError(403, 'permission denied');
    }
    return new SubscribeResult();
}, onError: $logProxyError);
```

Return a result to accept the request, throw `ProxyError` to reject it with an
error the client receives, or `Disconnect` to close the client's connection;
any other exception fails the request, and goes to `onError` to be logged.
Payloads arrive as `stdClass`, and go back exactly. Keep proxy endpoints
reachable by Centrifugo only: whoever can call them can claim to be any user.

## Tokens

The `Centrifugal\Phpcent\Token` namespace issues the JWTs Centrifugo
authenticates clients with - connection and subscription tokens, with
Centrifugo's claims, signed with HMAC, RSA, ECDSA or Ed25519 - and the
`Endpoints` namespace serves the endpoints the `getToken` callbacks of client
SDKs call:

```php
use Centrifugal\Phpcent\Endpoints\{ConnectionToken, Unauthorized};
use Centrifugal\Phpcent\Token\{ConnectionClaims, Key, Token};

$key = Key::hmac($secret);   // client.token.hmac_secret_key

$connectionToken = new ConnectionToken(function (HttpRequest $http) use ($key): string {
    $user = sessionUser($http) ?? throw new Unauthorized();   // 403: the client stops asking
    return Token::connection($key, new ConnectionClaims(
        expiresAt: new DateTimeImmutable('+1 hour'),
        user: $user,
    ));
}, onError: $logError);
```

An expiry is required: a `DateTimeInterface`, or `Expiry::Never` for a token
accepted forever. `SubscriptionToken` serves subscription tokens the same way,
with the channel the client asks for. To rotate keys without restarting
Centrifugo, see [Key rotation with JWKS](#key-rotation-with-jwks).

## Key rotation with JWKS

By default Centrifugo verifies tokens with a single key from its
configuration: `client.token.hmac_secret_key`, or an RSA, ECDSA or Ed25519
public key. Changing that key means restarting Centrifugo, and from that
moment it refuses every token signed with the old key. To rotate keys without
that, your application can serve the keys itself, as a JSON Web Key Set (JWKS)
at Centrifugo's `client.token.jwks_public_endpoint`. Each key in the set has an
ID, and Centrifugo verifies a token with the key its `kid` header names:

```php
use Centrifugal\Phpcent\Endpoints\Jwks;

// An ID for each key, never reused for another key: Centrifugo caches keys by ID.
$key = Key::fromPem(file_get_contents('keys/2026-10.pem'))->withKeyId('2026-10');

$jwks = new Jwks($key);   // served at the URL of jwks_public_endpoint
$token = Token::connection($key, new ConnectionClaims(expiresAt: $expiresAt, user: '42'));
```

```json
{"client": {"token": {"jwks_public_endpoint": "https://app.example.com/centrifugo/jwks"}}}
```

Only RSA, ECDSA and Ed25519 keys can go in a set. An HMAC secret has no public
part to publish. Once `jwks_public_endpoint` is set, Centrifugo verifies
tokens with the set only, and refuses tokens signed with the key it was
configured with before. So moving to a JWKS is a one-time restart.

How Centrifugo reads the set:

- when a token names a key ID Centrifugo doesn't know, it fetches the set
  again, at most once every 5 seconds;
- it keeps the keys it fetched for 5 minutes (an hour before Centrifugo
  v6.10.2), so a key dropped from the set stops working within that time;
- when the endpoint can't be reached, it keeps using the keys it already has;
- a token whose key isn't in the set is refused as expired, and the client
  SDK asks for a new token.

Rotate a key in three steps. Each step is one deploy of your application, and
every instance should finish a step before any instance moves to the next:

1. **Publish the new key alongside the current one,** and keep signing with
   the current one: `new Jwks($current, $next)`.
2. **Sign with the new key.** Centrifugo fetches the set the first time it
   sees the new ID.
3. **Drop the old key** once every token it signed has expired: wait longer
   than the longest `expiresAt` you give.

If a key leaks, do all three steps now, without waiting in step 3. Within
5 minutes Centrifugo refuses new connections with the leaked key's tokens.
Clients that connected before stay connected until their tokens expire:
disconnect them with the server API if needed.

Centrifugo PRO's API tokens rotate the same way, from a set of their own at
`http_api.jwks.endpoint` (see [Authentication](#authentication)).
`new ApiTokens(currentKey: ...)` takes the key to sign with from a function,
so it can switch to the new key at step 2. Each API token is reused for half
its `ttl`, so in step 3 wait at least `ttl` after the switch.

## Shared poll signatures

Clients tracking keys of a [shared poll](https://centrifugal.dev/docs/server/shared_poll)
channel need a signature from the backend. `SharedPoll::sign` makes one, and
`SharedPollSignature` serves the endpoint a subscription's `getSignature`
callback calls:

```php
use Centrifugal\Phpcent\Endpoints\SharedPollSignature;
use Centrifugal\Phpcent\SharedPoll;

$signature = new SharedPollSignature(function (HttpRequest $http, string $channel, array $keys) use ($secret): array {
    $user = sessionUser($http);
    $allowed = readable($user, $channel, $keys);   // the rest are revoked
    return [$allowed, SharedPoll::sign($secret, user: $user, channel: $channel, keys: $allowed, expiresAt: new DateTimeImmutable('+1 hour'))];
});
```

Shared poll subscriptions are experimental in Centrifugo, and this part
follows their changes.

## Commands for async consumers

`Command` has the client's methods too, as static methods without `timeout`:
`Command::publish(...)` checks the call as a client would and encodes it, for
Centrifugo's [async consumers](https://centrifugal.dev/docs/server/consumers):
its `method` and `payload` for an outbox table, or `toJson()` for a Kafka
message.

```php
$cmd = Command::publish(channel: 'news', data: ['text' => 'hi']);
$pdo->prepare('INSERT INTO centrifugo_outbox (method, payload, partition) VALUES (?, ?, 0)')
    ->execute([$cmd->method, $cmd->payload]);
```

A consumer keeps commands in order only within a partition: to keep a
channel's commands in order, derive their partition from the channel the same
way in every writer.

## Streaming AI output

Publish a model's output into a channel of its own, in a namespace with history
and recovery, in chunks of what arrived every 50 ms or so, not a publication
per token. See
[Centrifugo for AI apps](https://centrifugal.dev/docs/getting-started/ai_apps),
which also covers sizing history for a whole response.

## More

- [API.md](API.md): the API reference, every class, method and field, made
  from the docblocks by `composer docs`.
- Docs for agents and LLMs: [centrifugal.dev/llms.txt](https://centrifugal.dev/llms.txt),
  an index of the current (v6) documentation.
- Development: see [AGENTS.md](AGENTS.md); `composer check` runs what CI does
  but the integration tests, `composer test-integration` those.

MIT license. Centrifugo PRO, which the methods marked "Centrifugo PRO only"
call, is commercial software under its own
[license](https://centrifugal.dev/license).
