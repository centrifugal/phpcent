<?php
/**
 * Created by IntelliJ IDEA.
 * User: sl4mmer
 * Date: 03.10.14
 * Time: 16:11
 */
namespace Sl4mmer\Phpcent;
class CentrifugeClient {
    private $host;
    private $projectKey;
    private $projectId;


    public function __construct($host="http://localhost:8000"){
        $this->connectionHost=$host;

    }

    public function setProject($id,$key){
        $this->projectId=$id;
        $this->projectKey=$key;
    }

    public function publish($channel,$data){
        return $this->send("publish",["channel"=>$channel,"data"=>$data]);
    }

    public function send($method,$params){
        $data=json_encode(["method"=>$method,"params"=>$params]);
        $ch=curl_init( "$this->host/api/$this->projectKey");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query(["data"=>$data,"sign"=>$this->buildSign($data)], '', '&'));
        print_r(curl_exec($ch));
    }

    private function buildSign($data){
        $ctx=hash_init("md5",HASH_HMAC,$this->projectKey);
        hash_update($ctx,$this->projectId);
        hash_update($ctx,$data);
        return hash_final($ctx);
    }

 

} 