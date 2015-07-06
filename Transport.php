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
    const SAFE = 1;
    const UNSAFE = 2;

    protected static $safety = self::SAFE;

    /**
     * @param mixed $safety
     */
    public static function setSafety($safety)
    {
        self::$safety = $safety;
    }

    public function communicate($host, $projectKey, $data)
    {
        $ch = curl_init("$host/api/$projectKey");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        if (self::$safety === Transport::UNSAFE) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $postData = http_build_query($data, '', '&');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        $headers = curl_getinfo($ch);
        curl_close($ch);

        if (empty($headers["http_code"]) || ($headers["http_code"] != 200)) {
            throw new \Exception("Response code: "
                                 . $headers["http_code"]
                                 . PHP_EOL
                                 . "Body: "
                                 . $response
            );
        }

        $answer = json_decode($response, true);

        return $answer;
    }
}
