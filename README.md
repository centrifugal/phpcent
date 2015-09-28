Phpcent
========

Php library to communicate with Centrifugo

Library is published on the Composer: https://packagist.org/packages/sl4mmer/phpcent
```php
{
    "require": {
        "sl4mmer/phpcent":"dev-master",
    }
}
```

For old Centrifuge versions (<0.8) require "sl4mmer/phpcent":"0.5.0"


Full Centrifugo documentation https://fzambia.gitbooks.io/centrifugal/content/

Basic Usage


```php
        
        $client = new \phpcent\Client("http://localhost:8000");
        $client->setSecret("secret key from Centrifugo");
        $client->publish("basic:main_feed", ["message" => "Hello Everybody"]);
        $history = $client->history("basic:main_feed");
        
```

You can use `phpcent` to create frontend token:

```php
	$token = $client->setSecret($pSecret)->buildSign($user . $timestamp);
```

In case if your Centrifugo server has invalid SSL certificate, you can use:

```php
\phpcent\Transport::setSafety(\phpcent\Transport::UNSAFE);
```
