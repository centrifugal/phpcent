<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\BatchResult;
use Centrifugal\Phpcent\BroadcastResult;
use Centrifugal\Phpcent\ChannelResult;
use Centrifugal\Phpcent\Command;
use Centrifugal\Phpcent\Exception\ApiException;
use Centrifugal\Phpcent\Exception\BatchFailedCommandsException;
use Centrifugal\Phpcent\Exception\BroadcastFailedChannelsException;
use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Exception\NetworkException;
use Centrifugal\Phpcent\Exception\UnknownChannelException;
use Centrifugal\Phpcent\FailedCommand;
use Centrifugal\Phpcent\Pending;
use Centrifugal\Phpcent\PresenceStatsResult;
use Centrifugal\Phpcent\PublishResult;
use Centrifugal\Phpcent\RawJson;
use Centrifugal\Phpcent\Tests\Support\FakeCentrifugo;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ResultsJsonTest extends TestCase
{
    public function testResultsRenderWithWireNames(): void
    {
        self::assertSame('{"num_clients":2,"num_users":1}', json_encode(new PresenceStatsResult(numClients: 2, numUsers: 1)));
        self::assertSame('{"stats":{"num_clients":2}}', json_encode(['stats' => new PresenceStatsResult(numClients: 2)]));
    }

    public function testBroadcastAndBatchResultsRender(): void
    {
        $ok = new ChannelResult('news', new PublishResult(offset: 1, epoch: 'e'), attempts: 1);
        $bad = new ChannelResult('unknown:x', error: ApiException::fromCode(102, 'unknown channel'), attempts: 1);
        $down = new ChannelResult('chat', error: new NetworkException('publish: refused'), attempts: 2);
        self::assertSame(
            '{"channels":['
            . '{"channel":"news","result":{"offset":1,"epoch":"e"},"attempts":1},'
            . '{"channel":"unknown:x","error":{"code":102,"message":"unknown channel"},"attempts":1},'
            . '{"channel":"chat","error":{"message":"publish: refused"},"attempts":2}],'
            . '"idempotency_key":"k","attempts":2,"replied":true}',
            json_encode(new BroadcastResult([$ok, $bad, $down], 'k', 2, true)),
        );
        $failed = [new FailedCommand(1, 'publish', ApiException::fromCode(102, 'unknown channel'))];
        self::assertSame(
            '{"replied":true,"failed":[{"index":1,"method":"publish","error":{"code":102,"message":"unknown channel"}}]}',
            json_encode(new BatchResult(true, $failed)),
        );
        self::assertSame('{"replied":true,"failed":[]}', json_encode(new BatchResult(true)));
    }

    public function testCommandRendersItsPayloadAsAnObject(): void
    {
        $cmd = Command::publish(channel: 'news', data: ['n' => 1.0]);
        self::assertSame('{"method":"publish","payload":{"channel":"news","data":{"n":1.0}}}', json_encode($cmd, \JSON_PRESERVE_ZERO_FRACTION));
        self::assertSame('{"method":"publish","payload":{"channel":"news","data":{"n":1.0}}}', $cmd->toJson());
        // Inside a payload phpcent encodes, exactly as its text.
        self::assertSame('{"channel":"outbox","data":{"cmd":{"method":"publish","payload":{"channel":"news","data":{"n":1.0}}}}}', Command::publish(channel: 'outbox', data: ['cmd' => $cmd])->payload);
    }

    public function testNullHint(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage("to publish JSON null, pass data: new RawJson('null')");
        FakeCentrifugo::client(new FakeCentrifugo())->publish(channel: 'news', data: null);
    }

    public function testReadmeTesting(): void
    {
        $replies = [
            'publish' => '{"result":{"offset":1,"epoch":"e"}}',
            'broadcast' => '{"result":{"responses":[{"result":{"offset":1}},{"error":{"code":102,"message":"unknown channel"}}]}}',
            'batch' => '{"replies":[{"publish":{"offset":1}},{"error":{"code":102,"message":"unknown channel"}}]}',
        ];
        $c = FakeCentrifugo::client(new FakeCentrifugo(static fn(string $method): Response => FakeCentrifugo::json(200, $replies[$method])));
        self::assertSame(1, $c->publish(channel: 'news', data: 1)->offset);
        try {
            $c->broadcast(channels: ['news', 'unknown:x'], data: 1);
            self::fail('no error');
        } catch (BroadcastFailedChannelsException $e) {
            self::assertSame('unknown:x', $e->failed[0]->channel);
        }
        $b = $c->newBatch();
        $p1 = $b->publish(channel: 'news', data: 1);
        $p2 = $b->publish(channel: 'unknown:x', data: 1);
        try {
            $b->send();
            self::fail('no error');
        } catch (BatchFailedCommandsException $e) {
            self::assertSame(1, $e->failed[0]->index);
        }
        self::assertSame(1, $p1->result()->offset);
        try {
            $p2->result();
            self::fail('no error');
        } catch (UnknownChannelException) {
        }

        // Test doubles.
        $ok = new ChannelResult('news', new PublishResult(offset: 1));
        $bad = new ChannelResult('unknown:x', error: ApiException::fromCode(102, 'unknown channel'));
        $e = new BroadcastFailedChannelsException([$bad], 2, new BroadcastResult([$ok, $bad], replied: true));
        self::assertSame([$bad], $e->result->failed);
        self::assertSame(1, Pending::resolved(new PublishResult(offset: 1))->result()->offset);
        try {
            Pending::resolved(error: ApiException::fromCode(102, 'unknown channel'))->result();
            self::fail('no error');
        } catch (UnknownChannelException) {
        }
        $failed = [new FailedCommand(1, 'publish', ApiException::fromCode(102, 'unknown channel'))];
        self::assertSame($failed, (new BatchFailedCommandsException($failed, 2, new BatchResult(true, $failed)))->failed);
        self::assertSame('null', json_encode(new RawJson('null')));
    }
}
