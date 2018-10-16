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
     * @var string Certificate file name
     * @since 1.0.5
     */
    private $cert;
    /**
     * @var string Directory containing CA certificates
     * @since 1.0.5
     */
    private $caPath;

    /**
     * @var int|null
     */
    private $connectTimeoutOption;

    /**
     * @var int|null
     */
    private $timeoutOption;

    /**
     * @param mixed $safety
     */
    public static function setSafety($safety)
    {
        self::$safety = $safety;
    }

    /**
     *
     * @param string $host
     * @param mixed $data
     * @return mixed
     * @throws TransportException
     */
    public function communicate($host, $data)
    {
        $ch = curl_init("$host/api/");

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        if ($this->connectTimeoutOption !== null) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeoutOption);
        }
        if ($this->timeoutOption !== null) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutOption);
        }

        if (self::$safety === self::UNSAFE) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } elseif (self::$safety === self::SAFE) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            if (null !== $this->cert) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->cert);
            }
            if (null !== $this->caPath) {
                curl_setopt($ch, CURLOPT_CAPATH, $this->caPath);
            }
        }

        $postData = http_build_query($data, '', '&');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $headers = curl_getinfo($ch);
        curl_close($ch);

        if (empty($headers["http_code"]) || ($headers["http_code"] != 200)) {
            throw new TransportException ("Response code: "
                . $headers["http_code"]
                . PHP_EOL
                . "cURL error: " . $error . PHP_EOL
                . "Body: "
                . $response
            );
        }

        return json_decode($response, true);
    }

    /**
     * @return string|null
     * @since 1.0.5
     */
    public function getCert()
    {
        return $this->cert;
    }

    /**
     * @param string|null $cert
     * @since 1.0.5
     */
    public function setCert($cert)
    {
        $this->cert = $cert;
    }

    /**
     * @return string|null
     * @since 1.0.5
     */
    public function getCAPath()
    {
        return $this->caPath;
    }

    /**
     * @param string|null $caPath
     * @since 1.0.5
     */
    public function setCAPath($caPath)
    {
        $this->caPath = $caPath;
    }

    /**
     * @return int|null
     */
    public function getConnectTimeoutOption()
    {
        return $this->connectTimeoutOption;
    }

    /**
     * @return int|null
     */
    public function getTimeoutOption()
    {
        return $this->timeoutOption;
    }

    /**
     * @param int|null $connectTimeoutOption
     * @return Transport
     */
    public function setConnectTimeoutOption($connectTimeoutOption)
    {
        $this->connectTimeoutOption = $connectTimeoutOption;

        return $this;
    }

    /**
     * @param int|null $timeoutOption
     * @return Transport
     */
    public function setTimeoutOption($timeoutOption)
    {
        $this->timeoutOption = $timeoutOption;

        return $this;
    }
}
