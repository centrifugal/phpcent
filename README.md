phpcent
=======

PHP library to communicate with Centrifugo v2 HTTP API.

Library is published on the Composer: https://packagist.org/packages/sl4mmer/phpcent

```php
{
    "require": {
        "sl4mmer/phpcent":"dev-master",
    }
}
```

See [Centrifugo documentation](https://centrifugal.github.io/centrifugo/)

Basic Usage:

```php
$client = new \phpcent\Client("http://localhost:8000/api");
$client->setApiKey("api key from Centrifugo");
$client->publish("channel", ["message" => "Hello Everybody"]);
```

You can use `phpcent` to create connection token (JWT):

```php
$token = $client->setSecret("Centrifugo secret key")->generateConnectionToken($user);
```

Or private channel subscription token:

```php
$token = $client->setSecret("Centrifugo secret key")->generatePrivateChannelToken($client, $channel);
```

Timeouts:

```php
$client->setConnectTimeoutOption(0); // Seconds | 0 = never
$client->setTimeoutOption(2); // Seconds
```

All available API methods:

```php
$response = $client->publish($channel, $messageData);
$response = $client->broadcast($channels, $messageData);
$response = $client->unsubscribe($channel, $userId);
$response = $client->disconnect($userId);
$response = $client->presence($channel);
$response = $client->presence_stats($channel);
$response = $client->history($channel);
$response = $client->history_remove($channel);
$response = $client->channels();
$response = $client->info();
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
* [Tomchanio](https://github.com/Tomchanskiy)
