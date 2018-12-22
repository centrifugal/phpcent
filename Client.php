<?php
namespace phpcent;

class Client
{
    private $url;
    private $apikey;
    private $secret;

    private $cert;
    private $caPath;

    private $connectTimeoutOption;
    private $timeoutOption;

    private static $safety = true;

    public function __construct(string $url, string $apikey = '', string $secret = '')
    {
        $this->url = $url;
        $this->apikey = $apikey;
        $this->secret = $secret;
    }

    public function setApiKey(string $key)
    {
        $this->apikey = $key;
        return $this;
    }

    public function setSecret(string $secret)
    {
        $this->secret = $secret;
        return $this;
    }

    public function setSafety($safety)
    {
        $this->safety = $safety;
        return $this;
    }

    public function setCert($cert)
    {
        $this->cert = $cert;
        return $this;
    }

    public function setCAPath($caPath)
    {
        $this->caPath = $caPath;
        return $this;
    }

    public function setConnectTimeoutOption(int $connectTimeoutOption)
    {
        $this->connectTimeoutOption = $connectTimeoutOption;
        return $this;
    }

    public function setTimeoutOption(int $timeoutOption)
    {
        $this->timeoutOption = $timeoutOption;
        return $this;
    }

    public function publish($channel, $data)
    {
        return $this->send('publish', [
            'channel' => $channel,
            'data' => $data,
        ]);
    }

    public function broadcast($channels, $data)
    {
        return $this->send('broadcast', [
            'channels' => $channels,
            'data' => $data,
        ]);
    }

    public function unsubscribe($channel, $user)
    {
        return $this->send('unsubscribe', [
            'channel' => $channel,
            'user' => $user,
        ]);
    }

    public function disconnect($user)
    {
        return $this->send('disconnect', [
            'user' => $user,
        ]);
    }

    public function presence($channel)
    {
        return $this->send('presence', [
            'channel' => $channel,
        ]);
    }

    public function presence_stats($channel)
    {
        return $this->send('presence_stats', [
            'channel' => $channel,
        ]);
    }

    public function history($channel)
    {
        return $this->send('history', [
            'channel' => $channel,
        ]);
    }

    public function history_remove($channel)
    {
        return $this->send('history_remove', [
            'channel' => $channel,
        ]);
    }

    public function channels()
    {
        return $this->send('channels');
    }

    public function info()
    {
        return $this->send('info');
    }

    public function generateConnectionToken(string $userId = '', int $exp = 0, array $info = [])
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = ['sub' => $userId];
        if (!empty($info)) {
            $payload['info'] = $info;
        }
        if ($exp) {
            $payload['exp'] = $exp;
        }
        $segments = [];
        $segments[] = $this->urlsafeB64Encode(json_encode($header));
        $segments[] = $this->urlsafeB64Encode(json_encode($payload));
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $this->secret);
        $segments[] = $this->urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    public function generatePrivateChannelToken(string $client, string $channel, int $exp = 0, array $info = [])
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = ['channel' => $channel, 'client' => $client];
        if (!empty($info)) {
            $payload['info'] = $info;
        }
        if ($exp) {
            $payload['exp'] = $exp;
        }
        $segments = [];
        $segments[] = $this->urlsafeB64Encode(json_encode($header));
        $segments[] = $this->urlsafeB64Encode(json_encode($payload));
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $this->secret);
        $segments[] = $this->urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    private function send($method, $params = [])
    {
        $response = \json_decode($this->request($method, $params));
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception(
            'json_decode error: ' . json_last_error_msg()
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

    private function request(string $method, array $params)
    {
        $ch = curl_init();
        if ($this->connectTimeoutOption) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeoutOption);
        }
        if ($this->timeoutOption) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutOption);
        }
        if (!self::$safety) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } elseif (self::$safety) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if ($this->cert) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->cert);
            }
            if ($this->caPath) {
                curl_setopt($ch, CURLOPT_CAPATH, $this->caPath);
            }
        }
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['method' => $method, 'params' => $params]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_URL, $this->url);
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

    private function getHeaders()
    {
        return [
            'Content-Type: application/json',
            'Authorization: apikey ' . $this->apikey
        ];
    }
}
