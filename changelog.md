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
