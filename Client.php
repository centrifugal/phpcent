<?php
namespace phpcent;

class Client
{
    protected $secret;
    private   $host;
    /**
     * @var ITransport $transport
     */
    private $transport;
    private $_su = false;

    public function __construct($host = "http://localhost:8000")
    {
        $this->host = $host;

    }

    public function getHost()
    {
        return $this->host;
    }

    public function setSecret($secret)
    {
        $this->secret = $secret;
        return $this;
    }

    /**
     * send message into channel of namespace. data is an actual information you want to send into channel
     *
     * @param       $channel
     * @param array $data
     * @return mixed
     */
    public function publish($channel, $data = [])
    {
        return $this->send("publish", ["channel" => $channel, "data" => $data]);
    }

    /**
     * unsubscribe user with certain ID from channel.
     *
     * @param $channel
     * @param $userId
     * @return mixed
     */
    public function unsubscribe($channel, $userId)
    {
        return $this->send("unsubscribe", ["channel" => $channel, "user" => $userId]);
    }

    /**
     * disconnect user by user ID.
     *
     * @param $userId
     * @return mixed
     */
    public function disconnect($userId)
    {
        return $this->send("disconnect", ["user" => $userId]);
    }

    /**
     * get channel presence information (all clients currently subscribed on this channel).
     *
     * @param $channel
     * @return mixed
     */
    public function presence($channel)
    {
        return $this->send("presence", ["channel" => $channel]);
    }

    /**
     * get channel history information (list of last messages sent into channel).
     *
     * @param $channel
     * @return mixed
     */
    public function history($channel)
    {
        return $this->send("presence", ["channel" => $channel]);
    }

    /**
     * get channels information (list of currently active channels).
     *
     * @return mixed
     */
    public function channels()
    {
        return $this->send("channels", []);
    }

    /**
     * get stats information about running server nodes.
     *
     * @return mixed
     */
    public function stats()
    {
        return $this->send("stats", []);
    }

    /**
     * @param string $method
     * @param array  $params
     * @return mixed
     * @throws \Exception
     */
    public function send($method, $params = [])
    {
        if (empty($params)) {
            $params = new \StdClass();
        }
        $data = json_encode(["method" => $method, "params" => $params]);

        return
            $this->getTransport()
                 ->communicate(
                     $this->host,
                     ["data" => $data, "sign" => $this->generateApiSign($data)]
                 );
    }

    /**
     * Check that secret key set
     * @throws \Exception
     */
    private function checkKey()
    {
        if ($this->secret == null)
            throw new \Exception("Secret must be set");
    }

    /**
     * @param $data
     * @return string $hash
     * @throws \Exception if required data not specified
     */
    public function generateApiSign($data)
    {
        $this->checkKey();
        $ctx = hash_init("sha256", HASH_HMAC, $this->secret);
        hash_update($ctx, $data);

        return hash_final($ctx);
    }

    /**
     * Generate client connection token
     *
     * @param string $user
     * @param string $timestamp
     * @param string $info
     * @return string
     */
    public function generateClientToken($user, $timestamp, $info = "")
    {
        $this->checkKey();
        $ctx = hash_init("sha256", HASH_HMAC, $this->secret);
        hash_update($ctx, $user);
        hash_update($ctx, $timestamp);
        hash_update($ctx, $info);
        return hash_final($ctx);
    }
    /**
     * @param string $client
     * @param string $channel
     * @param string $info
     * @return string
     */
    public function generateChannelSign($client, $channel, $info = "")
    {
        $this->checkKey();
        $ctx = hash_init("sha256", HASH_HMAC, $this->secret);
        hash_update($ctx, $client);
        hash_update($ctx, $channel);
        hash_update($ctx, $info);
        return hash_final($ctx);
    }

    /**
     * @return ITransport
     */
    private function getTransport()
    {
        if ($this->transport == null) {
            $this->setTransport(new Transport());
        }

        return $this->transport;
    }

    /**
     * @param ITransport $transport
     */
    public function setTransport(ITransport $transport)
    {
        $this->transport = $transport;
    }

}
