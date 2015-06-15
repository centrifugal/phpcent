<?php
/**
 * Created by IntelliJ IDEA.
 * User: sl4mmer
 * Date: 07.10.14
 * Time: 15:29
 */

namespace phpcent;

class Transport implements ITransport
{

    public function communicate($host, $projectKey, $data)
    {
        $ch = curl_init("$host/api/$projectKey");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        $response = curl_exec($ch);
        $headers = curl_getinfo($ch);
        curl_close($ch);
        if (empty($headers["http_code"]) || ($headers["http_code"] != 200)) {
            throw new \Exception("Response code: " . $headers["http_code"] . PHP_EOL . "Body: " . $response);
        }
        $answer = json_decode($response, true);

        return $answer;
    }
}