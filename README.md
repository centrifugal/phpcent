Phpcent
========

Php library to communicate with Centrifugo > 0.3.0

Library is published on the Composer: https://packagist.org/packages/sl4mmer/phpcent
```php
{
    "require": {
        "sl4mmer/phpcent":"dev-master",
    }
}
```

Full Centrifugo documentation https://fzambia.gitbooks.io/centrifugal/content/

Basic Usage:

```php
        
        $client = new \phpcent\Client("http://localhost:8000");
        $client->setSecret("secret key from Centrifugo");
        $client->publish("main_feed", ["message" => "Hello Everybody"]);
        $history = $client->history("main_feed");
        
```

You can use `phpcent` to create frontend token:

```php
	$token = $client->setSecret($pSecret)->genererateClientToken($user, $timestamp);
```

Or to create private channel sign:

```php
	$sign = $client->setSecret($pSecret)->genererateChannelSign($client, $channel);
```

In case if your Centrifugo server has invalid SSL certificate, you can use:

```php
\phpcent\Transport::setSafety(\phpcent\Transport::UNSAFE);
```
