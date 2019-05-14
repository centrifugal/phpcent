phpcent
=======

PHP library to communicate with Centrifugo v2 HTTP API.

Library is published on the Composer: https://packagist.org/packages/centrifugal/phpcent

```php
{
    "require": {
        "centrifugal/phpcent":"dev-master",
    }
}
```

See [Centrifugo documentation](https://centrifugal.github.io/centrifugo/)

Basic Usage:

```php
$client = new \phpcent\Client("http://localhost:8000/api");
$client->setApiKey("Centrifugo api key");
$client->publish("channel", ["message" => "Hello Everybody"]);
```

You can use `phpcent` to create connection token (JWT):

```php
$token = $client->setSecret("Centrifugo secret key")->generateConnectionToken($userId);
```

Connection token that will be valid for 5 minutes:

```php
$token = $client->setSecret("Centrifugo secret key")->generateConnectionToken($userId, time() + 5*60);
```

It's also possible to generate private channel subscription token:

```php
$token = $client->setSecret("Centrifugo secret key")->generatePrivateChannelToken($client, $channel);
```

Also API key and secret can be set in constructor:

```php
$client = new \phpcent\Client("http://localhost:8000/api", "Centrifugo api key", "Centrifugo secret key");
```

Timeouts:

```php
$client->setConnectTimeoutOption(0); // Seconds | 0 = never
$client->setTimeoutOption(2); // Seconds
```

All available API methods:

```php
$response = $client->publish($channel, $data);
$response = $client->broadcast($channels, $data);
$response = $client->unsubscribe($channel, $userId);
$response = $client->disconnect($userId);
$response = $client->presence($channel);
$response = $client->presenceStats($channel);
$response = $client->history($channel);
$response = $client->historyRemove($channel);
$response = $client->channels();
$response = $client->info();
```

To use `assoc` option while decoding JSON:

```php
$client->setUseAssoc(true);
```

### SSL

In case if your Centrifugo server has invalid SSL certificate, you can use:

```php
$client->setSafety(false);
```

You can also use self signed certificate in safe manner:

```php
$client = new \phpcent\Client("https://localhost:8000/api");
$client->setCert("/path/to/certificate.pem");
$client->setCAPath("/ca/path"); // if you need.
```

*Note:* Certificate must match with host name in `Client` address (`localhost` in example above).

Authors
=======

* [Dmitriy Soldatenko](https://github.com/sl4mmer)
* [Dmitriy Tetekin](https://github.com/Tomchanskiy)
