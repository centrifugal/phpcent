Phpcent
========

Php library to communicate with Centrifugo HTTP API.

Library is published on the Composer: https://packagist.org/packages/sl4mmer/phpcent
```php
{
    "require": {
        "sl4mmer/phpcent":"dev-master",
    }
}
```

Full [Centrifugo documentation](https://fzambia.gitbooks.io/centrifugal/content/)

Basic Usage:

```php
        
        $client = new \phpcent\Client("http://localhost:8000");
        $client->setSecret("secret key from Centrifugo");
        $client->publish("main_feed", ["message" => "Hello Everybody"]);
        $history = $client->history("main_feed");
        
```

You can use `phpcent` to create frontend token:

```php
	$token = $client->setSecret($pSecret)->generateClientToken($user, $timestamp);
```

Or to create private channel sign:

```php
	$sign = $client->setSecret($pSecret)->generateClientToken($client, $channel);
```

In case if your Centrifugo server has invalid SSL certificate, you can use:

```php
\phpcent\Transport::setSafety(\phpcent\Transport::UNSAFE);
```

Since 1.0.3  phpcent has broadcast implementation. 

```php
$client->broadcast(['example:entities', 'example:moar'], ['user_id' => 2321321, 'state' => '1']);
```

Alternative clients
===================

* [php-centrifugo](https://github.com/oleh-ozimok/php-centrifugo) - allows to work with Redis Engine API queue.
