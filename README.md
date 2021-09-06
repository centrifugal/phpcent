phpcent
=======

[![Build Status](https://img.shields.io/travis/centrifugal/phpcent.svg?style=flat-square)](https://travis-ci.org/centrifugal/phpcent)
[![Latest Version](https://img.shields.io/github/release/centrifugal/phpcent.svg?style=flat-square)](https://github.com/centrifugal/phpcent/releases)

PHP library to communicate with Centrifugo v3 HTTP API.

Library is published on the Composer: https://packagist.org/packages/centrifugal/phpcent

```bash
composer require centrifugal/phpcent:~4.0
```

See [Centrifugo server API documentation](https://centrifugal.dev/docs/server/server_api).

Basic Usage:

```php
$client = new \phpcent\Client("http://localhost:8000/api");
$client->setApiKey("Centrifugo API key");
$client->publish("channel", ["message" => "Hello World"]);
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
$client = new \phpcent\Client("http://localhost:8000/api", "<API key>", "<secret key>");
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

To use `assoc` option while decoding JSON in response:

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

### DNS Resolution

This error may indicate your system is having trouble resolving IPv6 addresses:

```
cURL error: Resolving timed out after [value] milliseconds
```

By default, both IPv4 and IPv6 addresses will attempt to be resolved. You can force it to only resolve IPv4 addresses with:

```php
$client->forceIpResolveV4();
```

### Testing

Requirements:

* git
* A supported version of PHP
* [composer](http://getcomposer.org/download)
* [Docker](https://www.docker.com/products/docker-desktop)

The provided PHPUnit tests assume that a local [Centrifugo](https://github.com/centrifugal/centrifugo) server is running and available at port 8000. This can be accomplished using Docker and the [official Centrifugo image](https://hub.docker.com/r/centrifugo/centrifugo/).

```shell
# Install package dependencies.
$ composer install

# The following command starts a Centrifugo server running in a background Docker container.
$ docker run -d -p 8000:8000 --name centrifugo centrifugo/centrifugo centrifugo --api_insecure

# Run the test suite.
$ vendor/bin/phpunit

# Shut down the Centrifugo container.
$ docker stop centrifugo
```

Authors
=======

* [Dmitriy Soldatenko](https://github.com/sl4mmer)
* [Dmitriy Tetekin](https://github.com/Tomchanskiy)
