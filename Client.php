<?php
namespace phpcent;

class Client
{
    private $host;
    private $projectKey;
    private $projectId;

    /**
     * @var string secret api key from configuration file. Required for superuser mode
     */

    private $apiSecret;
    /**
     * @var ITransport $transport
     */
    private $transport;
    private $_su = false;

    public function __construct($host = "http://localhost:8000")
    {
        $this->host = $host;

    }

    /**
     * Enables superuser mode for next request
     * Don't use it. Will be available in next commit
     *
     * @todo Broken =)
     * @return $this
     */
    public function su()
    {
        $this->_su = true;

        return $this;
    }

    public function setApiSecret($secret)
    {
        $this->apiSecret = $secret;
    }

    public function getHost()
    {
        return $this->host;
    }

    public function setProject($id, $key, $apisecret = null)
    {
        if ($apisecret) {
            $this->apiSecret = $apisecret;
        }
        $this->projectId = $id;
        $this->projectKey = $key;

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
        if ($this->_su) {
            $params["_project"] = $this->projectId;
        }
        $data = json_encode(["method" => $method, "params" => $params]);
        try {
            $result = $this->getTransport()
                           ->communicate($this->host, $this->projectId, ["data" => $data, "sign" => $this->buildSign($data)]);
        } catch (\Exception $exception) {
            $this->_su = false;
            throw $exception;
        }
        $this->_su = false;

        return $result;
    }

    /**
     * @param $data
     * @return string $hash
     * @throws \Exception if required data not specified
     */
    public function buildSign($data)
    {
        if ($this->projectKey == null) {
            throw new \Exception("Project key should nod be empty");
        }
        if ($this->projectId == null) {
            throw new \Exception("Project id should not be empty");
        }
        if ($this->_su && $this->apiSecret == null) {
            throw new \Exception("Api secret is required for superuser mode");
        }
        $ctx = hash_init("sha256", HASH_HMAC, ($this->_su) ? $this->apiSecret : $this->projectKey);
        hash_update($ctx, ($this->_su) ? "_" : $this->projectId);
        hash_update($ctx, $data);

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
