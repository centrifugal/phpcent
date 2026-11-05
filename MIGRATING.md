# Migrating from phpcent 6 to phpcent 7

phpcent 7 is a rewrite, to the
[Centrifugo server SDK specification](https://github.com/centrifugal/gocent/blob/master/spec.md),
version 0.4, for Centrifugo v6. It needs PHP 8.3 or newer, and lives in the `Centrifugal\Phpcent` namespace
instead of `phpcent`. Both are the `centrifugal/phpcent` package, so an
application moves over at once; one which needs time can stay on
`"centrifugal/phpcent": "^6.0"`, which keeps working.

What changes:

- **Every API method** takes its request's fields as named arguments and
  returns a typed result: `$client->publish(channel: 'news', data: $data)`,
  `$res->offset` instead of `$res->result->offset`. Pass them by name: phpcent
  6's positional order is not kept. Every method of Centrifugo v6's API is
  there, the PRO ones included.
- **Errors are exceptions.** phpcent 6 returned Centrifugo's errors in the
  response, for you to check; phpcent 7 throws them - `ApiException`, with a
  subclass per known code - and throws `BroadcastFailedChannelsException` or
  `BatchFailedCommandsException` when parts of a broadcast or batch failed.
  Network errors and HTTP statuses throw their own exceptions, not
  `\Exception`. **Check every broadcast and batch you send**: see
  [Broadcasts and batches now throw](#broadcasts-and-batches-now-throw).
- **Nested messages are objects**, not arrays: `since` and `recoverSince` take
  a `StreamPosition`, `override` a `SubscribeOptionOverride`, `disconnect` a
  `Disconnect` - see [Arguments which were arrays](#arguments-which-were-arrays).
- **Every call has a timeout.** phpcent 6 set none unless you called
  `setTimeoutOption`, so a stuck Centrifugo held the request for as long as
  PHP let it; phpcent 7's `requestTimeout` is 10 seconds by default and bounds
  the whole call, a `timeout:` argument replacing it for one call. Zero is
  refused: `setTimeoutOption(0)`'s "no timeout" has no equivalent - set a
  large value. A timeout throws `TimeoutException`.
- **Requests are checked before sending.** A field Centrifugo requires given
  empty - `publish(channel: '', ...)`, `history(channel: '')` - a publication
  without `data`, or a payload which is not JSON - NaN, a binary string -
  throws `InvalidRequestException`, and nothing is sent. phpcent 6 sent them,
  and Centrifugo's code 107 came back in the response.
- **Payloads** go back exactly: replies give them as `stdClass`, which you can
  publish again unchanged. `setUseAssoc` is gone - `Payload::toArray($v)`
  gives arrays where you want them.
- **Tokens** are made with `Token::connection` and `Token::subscription`,
  with every claim Centrifugo reads, and HMAC, RSA, ECDSA or Ed25519 keys. An
  expiry is required: `Expiry::Never` for a token accepted forever.
- **New:** proxy handlers, the endpoints client SDKs call, JWKS, Centrifugo
  PRO's API tokens, shared poll signatures, commands for async consumers,
  `broadcastWithRetry`, and any PSR-18 HTTP client instead of curl.

## Broadcasts and batches now throw

This is the change most likely to break code which moved over unchanged.

phpcent 6 returned Centrifugo's response for a broadcast or a batch, and a
channel or command which failed was only an `error` inside it. Code which
didn't look - most code - went on as if everything had been published.

phpcent 7 throws when any part failed, even when the others succeeded:
`BroadcastFailedChannelsException` for a broadcast, `BatchFailedCommandsException`
for a batch. Not catching it means an exception where phpcent 6 was silent - in
a web request, an error page, although most channels got the publication.

Decide, for each broadcast and batch, which you want:

- **A failure is an error** - an unknown channel is a bug, a broker failure
  needs attention: let it throw, and your error reporting sees it.
- **Best effort, as phpcent 6 behaved** - publish where it can, log the rest:
  catch the exception. It holds every part's outcome, and the parts which
  succeeded were published.

```php
try {
    $client->broadcast(channels: $channels, data: $data);
} catch (BroadcastFailedChannelsException $e) {
    foreach ($e->result->failed as $ch) {
        $logger->warning("broadcast to {$ch->channel} failed: {$ch->error->getMessage()}");
    }
}

try {
    $batch->send();
} catch (BatchFailedCommandsException $e) {
    foreach ($e->failed as $f) {
        $logger->warning("batch command #{$f->index} ({$f->method}) failed: {$f->error->getMessage()}");
    }
}
```

Catch these two exceptions only. A broadcast or batch which failed as a whole -
the network, the credentials, Centrifugo down - throws its own exception, as
any call does, and no part of it was published.

To retry the channels which failed for a temporary reason, use
`broadcastWithRetry` instead of `broadcast`: it sends again only those, under
one `idempotencyKey`.

## Arguments which were arrays

phpcent 6 took nested messages as arrays and sent them as they were. phpcent 7
takes the generated classes, which name their fields and check them; an empty
one is not sent:

```php
// phpcent 6
$client->history('news', 10, ['offset' => 5, 'epoch' => $epoch]);
$client->subscribe('news', '42', '', 0, [], [], ['offset' => 5, 'epoch' => $epoch], ['presence' => ['value' => true]]);
$client->disconnect('42', '', ['code' => 4000, 'reason' => 'bye']);

// phpcent 7
use Centrifugal\Phpcent\{Disconnect, StreamPosition, SubscribeOptionOverride};

$client->history(channel: 'news', limit: 10, since: new StreamPosition(offset: 5, epoch: $epoch));
$client->subscribe(channel: 'news', user: '42', recoverSince: new StreamPosition(offset: 5, epoch: $epoch), override: new SubscribeOptionOverride(presence: true));
$client->disconnect(user: '42', disconnect: new Disconnect(code: 4000, reason: 'bye'));
```

A result's `position()` gives the `StreamPosition` to read on from:
`history(channel: 'news', limit: 10, since: $published->position())`, or
`historyAll(...)`, which reads every page.

## Programs

The programs below are complete: each pair prints the same, run against
Centrifugo with `http_api.key` set to `api_key` and `client.token.hmac_secret_key`
to `client_secret`.

### Publish, history and errors

Results are typed objects; Centrifugo's errors are exceptions.

phpcent 6:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$client = new \phpcent\Client('http://localhost:8000/api');
$client->setApiKey('api_key');
$client->setTimeoutOption(5);

$res = $client->publish('news', ['text' => 'hello']);
echo "published at offset {$res->result->offset}\n";

$res = $client->history('news', 1, [], true);
echo "last: {$res->result->publications[0]->data->text}\n";

// Centrifugo's errors come back in the response.
$res = $client->publish('unknown:news', ['text' => 'hello']);
if (isset($res->error)) {
    echo "error {$res->error->code}: {$res->error->message}\n";
}
```

phpcent 7:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Centrifugal\Phpcent\Client;
use Centrifugal\Phpcent\Exception\ApiException;

$client = new Client('http://localhost:8000/api', apiKey: 'api_key', requestTimeout: 5.0);

$res = $client->publish(channel: 'news', data: ['text' => 'hello']);
echo "published at offset {$res->offset}\n";

$res = $client->history(channel: 'news', limit: 1, reverse: true);
echo "last: {$res->publications[0]->data->text}\n";

// Centrifugo's errors are thrown.
try {
    $client->publish(channel: 'unknown:news', data: ['text' => 'hello']);
} catch (ApiException $e) {
    echo "error {$e->getCode()}: {$e->serverMessage}\n";
}
```

### Broadcast

A broadcast which failed in some channels throws, carrying every channel's outcome.

phpcent 6:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$client = new \phpcent\Client('http://localhost:8000/api');
$client->setApiKey('api_key');

$channels = ['news', 'unknown:alerts', 'sport'];
$res = $client->broadcast($channels, ['text' => 'hello']);
foreach ($res->result->responses as $i => $r) {
    if (isset($r->error)) {
        echo "{$channels[$i]}: error {$r->error->code}\n";
    } else {
        echo "{$channels[$i]}: offset {$r->result->offset}\n";
    }
}
```

phpcent 7:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Centrifugal\Phpcent\Client;
use Centrifugal\Phpcent\Exception\BroadcastFailedChannelsException;

$client = new Client('http://localhost:8000/api', apiKey: 'api_key');

try {
    $res = $client->broadcast(channels: ['news', 'unknown:alerts', 'sport'], data: ['text' => 'hello']);
} catch (BroadcastFailedChannelsException $e) {
    // Some channels failed; the others got the publication.
    $res = $e->result;
}
foreach ($res->channels as $ch) {
    if ($ch->error !== null) {
        echo "{$ch->channel}: error {$ch->error->getCode()}\n";
    } else {
        echo "{$ch->channel}: offset {$ch->result->offset}\n";
    }
}
```

### Batch

A batch has the client's methods, each adding a command with its own result.

phpcent 6:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$client = new \phpcent\Client('http://localhost:8000/api');
$client->setApiKey('api_key');

$res = $client->batch(['commands' => [
    ['publish' => ['channel' => 'news', 'data' => ['text' => 'hi']]],
    ['presence_stats' => ['channel' => 'news']],
]]);
echo "published at offset {$res->replies[0]->publish->offset}\n";
echo "clients: " . ($res->replies[1]->presence_stats->num_clients ?? 0) . "\n";
```

phpcent 7:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Centrifugal\Phpcent\Client;

$client = new Client('http://localhost:8000/api', apiKey: 'api_key');

$batch = $client->newBatch();
$pub = $batch->publish(channel: 'news', data: ['text' => 'hi']);
$stats = $batch->presenceStats(channel: 'news');
$batch->send();
echo "published at offset {$pub->result()->offset}\n";
echo "clients: {$stats->result()->numClients}\n";
```

### Tokens

The claims are named; the expiry is a date, or `Expiry::Never`.

phpcent 6:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$client = new \phpcent\Client('http://localhost:8000/api', '', 'client_secret');

$exp = time() + 3600;
$conn = $client->generateConnectionToken('42', $exp, ['name' => 'Alice'], ['news'], ['plan' => 'pro']);
$sub = $client->generateSubscriptionToken('42', 'news', $exp, ['role' => 'reader']);

foreach ([$conn, $sub] as $token) {
    $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    unset($claims['iat'], $claims['exp']);
    ksort($claims);
    echo json_encode($claims), "\n";
}
```

phpcent 7:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Centrifugal\Phpcent\Token\ConnectionClaims;
use Centrifugal\Phpcent\Token\Key;
use Centrifugal\Phpcent\Token\SubscriptionClaims;
use Centrifugal\Phpcent\Token\Token;

$key = Key::hmac('client_secret');

$exp = new DateTimeImmutable('+1 hour');
$conn = Token::connection($key, new ConnectionClaims(
    expiresAt: $exp,
    user: '42',
    info: ['name' => 'Alice'],
    channels: ['news'],
    meta: ['plan' => 'pro'],
));
$sub = Token::subscription($key, new SubscriptionClaims(
    channel: 'news',
    expiresAt: $exp,
    user: '42',
    info: ['role' => 'reader'],
));

foreach ([$conn, $sub] as $token) {
    $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    unset($claims['iat'], $claims['exp']);
    ksort($claims);
    echo json_encode($claims), "\n";
}
```

## Reference

| phpcent 6 | phpcent 7 |
|---|---|
| `new \phpcent\Client($url, $apiKey, $secret)` | `new Client($url, apiKey: $apiKey)`; the secret goes to `Key::hmac($secret)` |
| `setApiKey($key)` | `apiKey:` |
| `setSecret($secret)` | `Key::hmac($secret)`, for `Token::connection` and `Token::subscription` |
| `setTimeoutOption($seconds)` | `requestTimeout:`, or `timeout:` on a call. It bounds the whole request; 10 seconds by default, where phpcent 6 had none |
| `setTimeoutOption(0)`, no timeout | refused: set a large `requestTimeout` |
| `setConnectTimeoutOption($seconds)` | `curlOptions: [CURLOPT_CONNECTTIMEOUT_MS => ...]`; `requestTimeout` already bounds connecting |
| `setSafety(false)` | `curlOptions: [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]` - for development only |
| `setCert($path)`, `setCAPath($dir)` | `curlOptions: [CURLOPT_CAINFO => $path, CURLOPT_CAPATH => $dir]` |
| `forceIpResolveV4()` | `curlOptions: [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]` |
| `setUseAssoc(true)` | gone: results are typed objects, payloads `stdClass`; `Payload::toArray($payload)` for arrays |
| `publish($channel, $data, $skipHistory, $tags, $idempotencyKey, $delta, $version, $versionEpoch)` | `publish(channel: ..., data: ..., skipHistory: ..., tags: ..., idempotencyKey: ..., delta: ..., version: ..., versionEpoch: ...)` |
| `broadcast($channels, $data, ...)` | `broadcast(channels: ..., data: ...)`, or `broadcastWithRetry` |
| `subscribe($channel, $user, $client, $expireAt, $info, $data, $recoverSince, $override, $session)` | `subscribe(channel: ..., user: ..., client: ..., recoverSince: new StreamPosition(...), override: new SubscribeOptionOverride(...))` |
| `history($channel, $limit, $since, $reverse)` | `history(channel: ..., limit: ..., since: new StreamPosition(offset: ..., epoch: ...), reverse: ...)`; `historyAll(...)` for every page |
| `disconnect($user, $client, $disconnect, $whitelist, $session)` | `disconnect(user: ..., client: ..., disconnect: new Disconnect(code: ..., reason: ...), whitelist: ...)` |
| `channels($pattern)` | `channels(pattern: ...)` |
| `unsubscribe`, `presence`, `presenceStats`, `historyRemove`, `info` | the same names, each taking its request's fields by name: `presence(channel: ...)`; `info()` takes none |
| `batch(['commands' => [...]])` | `$batch = $client->newBatch(); $p = $batch->publish(channel: ..., data: ...); $batch->send(); $p->result()` |
| `generateConnectionToken($user, $exp, $info, $channels, $meta)` | `Token::connection($key, new ConnectionClaims(expiresAt: ..., user: ..., info: ..., channels: ..., meta: ...))` |
| `generateSubscriptionToken($user, $channel, $exp, $info)` | `Token::subscription($key, new SubscriptionClaims(channel: ..., expiresAt: ..., user: ..., info: ...))` |
| a token without `$exp`, which never expired | `expiresAt: Expiry::Never`, chosen explicitly |
| `\Exception` with "Response code: ..." | `HttpException` (`UnauthorizedException` for a 401), `NetworkException`, `TimeoutException`, `DecodeException`, all `CentException` |
| `$res->error` in a response | `ApiException`, thrown |
| code 107 in a response for an empty required field | `InvalidRequestException`, before sending |
| a payload `json_encode` cannot encode - NaN, a binary string - sent as no body at all | `InvalidRequestException`, before sending |
| `\Exception` with "json_decode error" for a reply which is not JSON | `DecodeException` |
| `User-Agent: curl/7.39.0` | `User-Agent: phpcent/7`; `headers: ['User-Agent' => ...]` replaces it |

For tests, give the client a fake PSR-18 client instead of mocking it: see
[Testing your code](README.md#testing-your-code).
