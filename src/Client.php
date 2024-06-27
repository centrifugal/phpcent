<?php

namespace phpcent;

/**
 * Centrifugo API Client
 *
 * @package    phpcent
 * @copyright  Copyright (c) 2019 Centrifugal
 * @license    The MIT License (MIT)
 */
class Client
{
    private $url;
    private $apikey;
    private $secret;

    private $cert;
    private $caPath;

    private $connectTimeoutOption;
    private $timeoutOption;
    private $forceIpResolveV4;

    private $safety = true;
    private $useAssoc = false;

    /**
     * Construct new Client instance.
     *
     * @param string $url Centrifugo API endpoint
     * @param string $apikey Centrifugo API key
     * @param string $secret Centrifugo secret key.
     *
     */
    public function __construct($url, $apikey = '', $secret = '')
    {
        $this->url = $url;
        $this->apikey = $apikey;
        $this->secret = $secret;
    }

    /**
     * @param string $key
     * @return Client
     */
    public function setApiKey($key)
    {
        $this->apikey = $key;
        return $this;
    }

    /**
     * @param string $secret
     * @return Client
     */
    public function setSecret($secret)
    {
        $this->secret = $secret;
        return $this;
    }

    /**
     * @param bool $caPath
     * @return Client
     */
    public function setSafety($safety)
    {
        $this->safety = $safety;
        return $this;
    }

    /**
     * @param bool $useAssoc
     * @return Client
     */
    public function setUseAssoc($useAssoc)
    {
        $this->useAssoc = $useAssoc;
        return $this;
    }

    /**
     * @param string $cert
     * @return Client
     */
    public function setCert($cert)
    {
        $this->cert = $cert;
        return $this;
    }

    /**
     * @param string $caPath
     * @return Client
     */
    public function setCAPath($caPath)
    {
        $this->caPath = $caPath;
        return $this;
    }

    /**
     * @param int $connectTimeoutOption
     * @return Client
     */
    public function setConnectTimeoutOption($connectTimeoutOption)
    {
        $this->connectTimeoutOption = $connectTimeoutOption;
        return $this;
    }

    /**
     * @param int $timeoutOption
     * @return Client
     */
    public function setTimeoutOption($timeoutOption)
    {
        $this->timeoutOption = $timeoutOption;
        return $this;
    }

    /**
     * Forces DNS to only resolve IPv4 addresses.
     *
     * @return Client
     */
    public function forceIpResolveV4()
    {
        $this->forceIpResolveV4 = true;
        return $this;
    }

    /**
     * Publish data into channel.
     *
     * @param string $channel
     * @param array $data
     * @param boolean $skipHistory (optional)
     * @return mixed
     */
    public function publish($channel, $data, $skipHistory = false)
    {
        return $this->send('publish', array(
            'channel' => $channel,
            'data' => $data,
            'skip_history' => $skipHistory,
        ));
    }

    /**
     * Broadcast the same data into multiple channels.
     *
     * @param array $channels
     * @param array $data
     * @param boolean $skipHistory (optional)
     * @return mixed
     */
    public function broadcast($channels, $data, $skipHistory = false)
    {
        return $this->send('broadcast', array(
            'channels' => $channels,
            'data' => $data,
            'skip_history' => $skipHistory,
        ));
    }

    /**
     * Subscribe user to channel.
     *
     * @param string $channel
     * @param string $user
     * @param string $client (optional)
     * @return mixed
     */
    public function subscribe($channel, $user, $client = '')
    {
        return $this->send('subscribe', array(
            'channel' => $channel,
            'user' => $user,
            'client' => $client,
        ));
    }

    /**
     * Unsubscribe user from channel.
     *
     * @param string $channel
     * @param string $user
     * @param string $client (optional)
     * @return mixed
     */
    public function unsubscribe($channel, $user, $client = '')
    {
        return $this->send('unsubscribe', array(
            'channel' => $channel,
            'user' => $user,
            'client' => $client,
        ));
    }

    /**
     * Disconnect user.
     *
     * @param string $user
     * @param string $client (optional)
     * @return mixed
     */
    public function disconnect($user, $client = '')
    {
        return $this->send('disconnect', array(
            'user' => $user,
            'client' => $client,
        ));
    }

    /**
     * Get channel presence info.
     *
     * @param string $channel
     * @return mixed
     */
    public function presence($channel)
    {
        return $this->send('presence', array(
            'channel' => $channel,
        ));
    }

    /**
     * Get channel presence stats.
     *
     * @param string $channel
     * @return mixed
     */
    public function presenceStats($channel)
    {
        return $this->send('presence_stats', array(
            'channel' => $channel,
        ));
    }

    /**
     * Get channel history.
     *
     * @param string $channel
     * @param int $limit (optional)
     * @param array $since (optional)
     * @param boolean $reverse (optional)
     * @return mixed
     */
    public function history($channel, $limit = 0, $since = array(), $reverse = false)
    {
        $params = array('channel' => $channel, 'limit' => $limit, 'reverse' => $reverse);
        if (!empty($since)) {
            $params['since'] = $since;
        }
        return $this->send('history', $params);
    }

    /**
     * Remove channel history.
     *
     * @param string $channel
     * @return mixed
     */
    public function historyRemove($channel)
    {
        return $this->send('history_remove', array(
            'channel' => $channel,
        ));
    }

    /**
     * Get all active channels.
     * 
     * @param string $pattern (optional)
     * @return mixed
     */
    public function channels($pattern = '')
    {
        return $this->send("channels", array(
            'pattern' => $pattern,
        ));
    }

    /**
     * Get server info.
     *
     * @return mixed
     */
    public function info()
    {
        return $this->send('info');
    }

    /**
     * Generate connection JWT. See https://centrifugal.dev/docs/server/authentication.
     * Keep in mind that this method does not support all claims of Centrifugo JWT connection
     * token at this point. You can use any JWT library to generate Centrifugo tokens.
     *
     * @param string $userId - current user ID as string.
     * @param int $exp - time in the future as unix seconds for token expiration.
     * @param array $info
     * @param array $channels
     * @param array $meta
     * @return string
     */
    public function generateConnectionToken($userId, $exp = 0, $info = array(), $channels = array(), $meta = array())
    {
        $header = array('typ' => 'JWT', 'alg' => 'HS256');
        $payload = array('sub' => (string) $userId);
        if (!empty($info)) {
            $payload['info'] = $info;
        }
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }
        if (!empty($channels)) {
            $payload['channels'] = $channels;
        }
        if ($exp) {
            $payload['exp'] = $exp;
        }
        $segments = array();
        $segments[] = $this->urlsafeB64Encode(json_encode($header));
        $segments[] = $this->urlsafeB64Encode(json_encode($payload));
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $this->secret);
        $segments[] = $this->urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    /**
     * Generate subscription JWT. See https://centrifugal.dev/docs/server/channel_token_auth.
     * Keep in mind that this method does not support all claims of Centrifugo JWT subscription
     * token at this point. You can use any JWT library to generate Centrifugo tokens.
     *
     * @param string $userId - current user ID as string.
     * @param string $channel - channel token generated for.
     * @param int $exp - time in the future as unix seconds for token expiration.
     * @param array $info
     * @return string
     */
    public function generateSubscriptionToken($userId, $channel, $exp = 0, $info = array())
    {
        $header = array('typ' => 'JWT', 'alg' => 'HS256');
        $payload = array('channel' => (string)$channel, 'sub' => (string)$userId);
        if (!empty($info)) {
            $payload['info'] = $info;
        }
        if ($exp) {
            $payload['exp'] = $exp;
        }
        $segments = array();
        $segments[] = $this->urlsafeB64Encode(json_encode($header));
        $segments[] = $this->urlsafeB64Encode(json_encode($payload));
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $this->secret);
        $segments[] = $this->urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    /*
    * Function added for backward compatibility with PHP version < 5.5
    */
    public function _json_last_error_msg()
    {
        if (function_exists('json_last_error_msg')) {
            return json_last_error_msg();
        }
        static $ERRORS = array(
            JSON_ERROR_NONE => 'No error',
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'State mismatch (invalid or malformed JSON)',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded'
        );

        $error = json_last_error();
        return isset($ERRORS[$error]) ? $ERRORS[$error] : 'Unknown error';
    }

    private function send($method, $params = array())
    {
        $response = \json_decode($this->request($method, $params), $this->useAssoc);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception(
                'json_decode error: ' . $this->_json_last_error_msg()
            );
        }
        return $response;
    }

    private function urlsafeB64Encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private function sign($msg, $key)
    {
        return hash_hmac('sha256', $msg, $key, true);
    }

    private function request($method, $params)
    {
        $ch = curl_init();
        if ($this->connectTimeoutOption) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeoutOption);
        }
        if ($this->timeoutOption) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutOption);
        }
        if ($this->forceIpResolveV4) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
        if (!$this->safety) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } elseif ($this->safety) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if ($this->cert) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->cert);
            }
            if ($this->caPath) {
                curl_setopt($ch, CURLOPT_CAPATH, $this->caPath);
            }
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'curl/7.39.0');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode((object)$params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_URL, $this->getUrl($method));
        $data = curl_exec($ch);
        $error = curl_error($ch);
        $headers = curl_getinfo($ch);
        curl_close($ch);
        if (empty($headers["http_code"]) || ($headers["http_code"] != 200)) {
            throw new \Exception(
                "Response code: "
                    . $headers["http_code"]
                    . PHP_EOL
                    . "cURL error: " . $error . PHP_EOL
            );
        }
        return $data;
    }

    private function getUrl($method)
    {
       return $this->url.'/'.$method;
    }

    private function getHeaders()
    {
        $headers = [
            'Content-Type: application/json',
            'X-API-Key: '.$this->apikey
        ];

        return $headers;
    }
}
