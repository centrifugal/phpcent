<?php
class ClientTest extends PHPUnit\Framework\TestCase
{
    protected $client;
    public function setUp(): void
    {
        $this->client = new \phpcent\Client("http://localhost:8000/api");
    }
    public function testSetters()
    {
        $this->assertNotNull($this->client->setSecret('secret'));
        $this->assertNotNull($this->client->setApiKey('apikey'));
        $this->assertNotNull($this->client->setSafety(true));
        $this->assertNotNull($this->client->setUseAssoc(true));
        $this->assertNotNull($this->client->setCert(""));
        $this->assertNotNull($this->client->setCAPath(""));
        $this->assertNotNull($this->client->setConnectTimeoutOption(1));
        $this->assertNotNull($this->client->setTimeoutOption(1));
    }

    public function testGetUrl(){
        $client = new \ReflectionClass($this->client);

        $method = $client->getMethod('getUrl');

        $method->setAccessible(true);

        $this->assertEquals("http://localhost:8000/api/publish", $method->invokeArgs($this->client,['publish']));
    }
   
    public function testGetHeaders(){

        $phpcent = new \phpcent\Client("http://localhost:8000/api");

        $apiKey = '12w5ec80-1bd0-4c0x-a2e9-2c96501d2123';
        
        $phpcent->setApiKey($apiKey);

        $client = new \ReflectionClass($phpcent);

        $method = $client->getMethod('getHeaders');

        $method->setAccessible(true);

        $this->assertEquals(
        [
            'Content-Type: application/json',
            'X-API-Key: '.$apiKey
        ]
        , $method->invoke($phpcent));
    }

    public function testPublish()
    {
        $this->client->setUseAssoc(true);
        $res = $this->client->publish('channel', ["message" => "Hello World"]);
        $this->assertNotNull($res);
        $this->assertEquals(["result" => array()], $res);
    }
    public function testPublishCentrifugoError()
    {
        $this->client->setUseAssoc(true);
        $res = $this->client->publish('namespace:channel', ["message" => "Hello World"]);
        $this->assertNotNull($res);
        $this->assertEquals(["error" => ["code" => 102, "message" => "unknown channel"]], $res);
    }
    public function testPublishNetworkError()
    {
        $this->expectException(Exception::class);
        $client = new \phpcent\Client("http://localhost:9000/api");
        $res = $client->publish('channel', ["message" => "Hello World"]);
    }

    public function testInfo()
    {
        $res = $this->client->info();
        $this->assertNotNull($res);
        $this->assertTrue(is_array($res->result->nodes));
    }
}
