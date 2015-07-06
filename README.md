Phpcent
========

Php library to communicate with Centrifuge

Library is published on the Composer: https://packagist.org/packages/sl4mmer/phpcent
```php
{
    "require": {
        "sl4mmer/phpcent":"dev-master",
    }
}
```

For old Centrifuge versions (<0.8) require "sl4mmer/phpcent":"0.5.0"


Full Centrifuge documentation http://centrifuge.readthedocs.org/en/latest/		

Basic Usage


```php
        
        $client = new \phpcent\Client("http://localhost:8000");
        $client->setProject("projectKey", "projectSecret");
        $client->publish("basic:main_feed", ["message" => "Hello Everybody"]);
        $history = $client->history("basic:main_feed")];
        
```
All api methods for managing channels has shortends. You can call other methods trough Client::send()
```php
$client->send("namespace_create",["name"=>"newnamespace"])
```

You can use phpcent to create frontend token

```php
	$token = $client->setProject($pKey, $pSecret)->buildSign($user . $timestamp);         
```

        
In case if your Centrifuge api has invalid SSL certificate, you can use 

```php
\phpcent\Transport::setSafety(\phpcent\Transport::UNSAFE);
```
