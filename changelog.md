# v6.0.0

Since phpcent v6.0.0 we do not maintain CHANGELOG.md file.

All changes may be found on [releases page](https://github.com/centrifugal/phpcent/releases) on Github.

# v5.1.0

* Add meta data support in jwt token [#56](https://github.com/centrifugal/phpcent/pull/56)

# v5.0.0

In this release we adapt phpcent for Centrifugo v4:

* `generatePrivateChannelToken` renamed to `generateSubscriptionToken` and now supports providing `sub` (user ID) claim - according to [channel auth docs](https://centrifugal.dev/docs/server/channel_token_auth) and [v4 subscription token migration docs](https://centrifugal.dev/docs/getting-started/migration_v4#subscription-token-migration).
* In `generateConnectionToken` method `$userId` argument is not optional anymore – it should be explicitly provided. You can still pass empty string explicitly for anonymous users.

# v4.0.0

Adapt to work with Centrifugo v3.

* Deprecated `history_remove` removed (in favour of `historyRemove`)
* Deprecated `presence_stats` removed (in favour of `presenceStats`)
* Drop support for PHP < 7.0.0

# v3.1.1

* option to force IPv4 addresses when resolving hostnames, see [#44](https://github.com/centrifugal/phpcent/issues/44) and [#45](https://github.com/centrifugal/phpcent/pull/45). Thanks [Steve Therrien](https://github.com/SteveTherrien)!

```php
$client->forceIpResolveV4();
```

# v3.1.0

* support `channels` JWT claim in `generateConnectionToken` function - which is an array of server-side channels to subscribe (see [docs](https://centrifugal.github.io/centrifugo/server/server_subs/)). Thanks, [Julius TM](https://github.com/juliustm)

# v3.0.2

* fix `Call to undefined function phpcent\_json_last_error_msg()`

# v3.0.1

* cast user to string when generating connection token - fixes possible regression after updating to v3.0.0

# v3.0.0

* library is now distributed under new name – `centrifugal/phpcent`.

# v2.2.0

* no changes here – just to sync actual version with Packagist.

# v2.1.0

* fix compatibility with older PHP versions
* add `setUseAssoc` method to use `assoc` option while decoding JSON from server

# v2.0.3

* fix safety param check - see https://github.com/centrifugal/phpcent/pull/29

# v2.0.2

* fix setting safety and use set user agent on request - see https://github.com/centrifugal/phpcent/pull/28

# v2.0.1

* fix generating error message - see https://github.com/centrifugal/phpcent/pull/24 

# v2.0.0

* update to work with Centrifugo v2
