<?php
namespace phpcent;


class Client {
    private $host;
    private $projectKey;
    private $projectId;
    /**
     * @var ITransport $transport
     */
    private $transport;



    public function __construct($host="http://localhost:8000"){
        $this->host=$host;

    }

    public function setProject($id,$key){
        $this->projectId=$id;
        $this->projectKey=$key;
        return $this;
    }

    /**
     * send message into channel of namespace. data is an actual information you want to send into channel
     * @param $channel
     * @param array $data
     * @return mixed
     */
    public function publish($channel,$data=[]){
        return $this->send("publish",["channel"=>$channel,"data"=>$data]);
    }

    /**
     * unsubscribe user with certain ID from channel.
     * @param $channel
     * @param $userId
     * @return mixed
     */
    public function unsubscribe($channel,$userId){
        return $this->send("unsubscribe",["channel"=>$channel,"user"=>$userId]);
    }

    /**
     * disconnect user by user ID.
     * @param $userId
     * @return mixed
     */
    public function disconnect($userId){
        return $this->send("disconnect",["user"=>$userId]);
    }

    /**
     * get channel presence information (all clients currently subscribed on this channel).
     * @param $channel
     * @return mixed
     */
    public function presence($channel){
        return $this->send("presence",["channel"=>$channel]);
    }

    /**
     * get channel history information (list of last messages sent into channel).
     * @param $channel
     * @return mixed
     */
    public function history($channel){
        return $this->send("presence",["channel"=>$channel]);
    }

    /**
     * @param string $method
     * @param array $params
     * @return mixed
     */
    public function send($method,$params){
        $data=json_encode(["method"=>$method,"params"=>$params]);
        return $this->getTransport()->communicate($this->host,$this->projectId,["data"=>$data,"sign"=>$this->buildSign($data)]);
    }

    /**
     *
     * @param $data
     * @return string $hash
     */
    public function buildSign($data){
        $ctx=hash_init("md5",HASH_HMAC,$this->projectKey);
        hash_update($ctx,$this->projectId);
        hash_update($ctx,$data);
        return hash_final($ctx);
    }

    /**
     * @return ITransport
     */
    private function getTransport()
    {
        if ($this->transport==null) $this->transport=new Transport();
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