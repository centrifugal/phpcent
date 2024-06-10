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
        $this->assertNotNull($this->client->setCompatibility(false));
    }
    public function testGetUrl(){
        $client = new \ReflectionClass($this->client);

        $method = $client->getMethod('getUrl');

        $this->client->setCompatibility(true);

        $this->assertEquals("http://localhost:8000/api", $method->invokeArgs($this->client,['publish']));

        $this->client->setCompatibility(false);

        $this->assertEquals("http://localhost:8000/api/publish", $method->invokeArgs($this->client,['publish']));
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
}
