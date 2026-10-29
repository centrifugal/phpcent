<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Payload;
use Centrifugal\Phpcent\RawJson;
use Centrifugal\Phpcent\Tests\Support\FakeCentrifugo;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Payloads are carried exactly: spec section 3.4. */
final class PayloadTest extends TestCase
{
    private static function sent(mixed $data): string
    {
        $fake = new FakeCentrifugo();
        FakeCentrifugo::client($fake)->publish(channel: 'c', data: $data);
        $body = $fake->last()['body'];
        \assert(str_starts_with($body, '{"channel":"c","data":') && str_ends_with($body, '}'));

        return substr($body, \strlen('{"channel":"c","data":'), -1);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function natives(): iterable
    {
        yield 'object' => [['text' => 'hi', 'n' => 1], '{"text":"hi","n":1}'];
        yield 'list' => [[1, 'a', null, true], '[1,"a",null,true]'];
        yield 'empty array is an array' => [[], '[]'];
        yield 'empty object' => [new \stdClass(), '{}'];
        yield 'numeric keys' => [(object) ['0' => 'a'], '{"0":"a"}'];
        yield 'string is a JSON string' => ['{"text":"hi"}', '"{\"text\":\"hi\"}"'];
        yield 'float keeps its zero fraction' => [1.0, '1.0'];
        yield 'integer past 2^53' => [9007199254740993, '9007199254740993'];
        yield 'false' => [false, 'false'];
        yield 'unicode and slashes as they are' => ['привет/👋', '"привет/👋"'];
        yield 'JsonSerializable' => [new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['x' => 1];
            }
        }, '{"x":1}'];
    }

    #[DataProvider('natives')]
    public function testNativeValues(mixed $value, string $json): void
    {
        self::assertSame($json, self::sent($value));
    }

    public function testRawJsonIsSentAsItIs(): void
    {
        $text = '{"n": 1.50, "big": 18446744073709551617, "e": 1e400}';
        self::assertSame($text, self::sent(new RawJson($text)));
        // A native value and raw JSON send the same value.
        self::assertSame(self::sent(['a' => [1, 2]]), self::sent(new RawJson('{"a":[1,2]}')));
    }

    public function testRawJsonInsideAValue(): void
    {
        self::assertSame('{"doc":{"n": 1.50},"list":[1,{"big":18446744073709551617}]}', self::sent([
            'doc' => new RawJson('{"n": 1.50}'),
            'list' => [1, new RawJson('{"big":18446744073709551617}')],
        ]));
        // A string looking like a placeholder stays a string.
        self::assertSame('{"s":"centrifugo_raw_json_00_0"}', self::sent(['s' => 'centrifugo_raw_json_00_0']));
        // Outside phpcent's encoding, it is its value.
        self::assertSame('{"a":{"n":1}}', json_encode(['a' => new RawJson('{"n":1}')]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function refused(): iterable
    {
        yield 'NaN' => [\NAN];
        yield 'infinity' => [['x' => -\INF]];
        yield 'bytes' => [['bin' => "\xff\xfe"]];
        yield 'a resource' => [fopen('php://memory', 'r')];
        yield 'too deep' => [array_reduce(range(1, 600), static fn(mixed $c): array => [$c], 1)];
        yield 'a JsonSerializable which throws' => [new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \DomainException('no');
            }
        }];
    }

    #[DataProvider('refused')]
    public function testRefusedBeforeSending(mixed $value): void
    {
        $fake = new FakeCentrifugo();
        try {
            FakeCentrifugo::client($fake)->publish(channel: 'c', data: $value);
            self::fail('sent');
        } catch (InvalidRequestException $e) {
            self::assertStringStartsWith('invalid publish request: data', $e->getMessage());
        }
        self::assertSame([], $fake->requests);
    }

    public function testBytesHint(): void
    {
        $this->expectExceptionMessage('b64data');
        self::sent("\xff");
    }

    public function testInvalidRawJson(): void
    {
        foreach (['', '{', 'NaN', '{"a":1}x', "\"\xff\""] as $bad) {
            try {
                new RawJson($bad);
                self::fail("accepted {$bad}");
            } catch (InvalidRequestException) {
            }
        }
        $this->expectNotToPerformAssertions();
    }

    /**
     * A payload from a reply sent back unchanged is the same JSON value.
     *
     * @return iterable<string, array{string}>
     */
    public static function roundTrips(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'numeric keys' => ['{"0":"a","1":"b"}'];
        yield 'integer past 2^53' => ['9007199254740993'];
        yield 'zero fraction' => ['1.0'];
        yield 'nested' => ['{"a":[{},[],{"0":1.0}],"b":null,"c":"x"}'];
        yield 'empty key' => ['{"":1}'];
    }

    #[DataProvider('roundTrips')]
    public function testReplyRoundTrip(string $json): void
    {
        $fake = new FakeCentrifugo(static fn(string $method): Response => FakeCentrifugo::json(200, $method === 'history'
            ? '{"result":{"publications":[{"data":' . $json . ',"offset":1}]}}'
            : '{"result":{}}'));
        $c = FakeCentrifugo::client($fake);
        $pub = $c->history(channel: 'c')->publications[0];
        $c->publish(channel: 'c', data: $pub->data);
        self::assertSame('{"channel":"c","data":' . $json . '}', $fake->last()['body']);
    }

    public function testReplyPayloadIsTheDecodedValue(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(200, '{"result":{"publications":[{"data":{"text":"hi","n":[1,2]}}]}}'));
        $data = FakeCentrifugo::client($fake)->history(channel: 'c')->publications[0]->data;
        self::assertInstanceOf(\stdClass::class, $data);
        self::assertSame('hi', $data->text);
        self::assertSame([1, 2], $data->n);
    }

    public function testToArray(): void
    {
        $data = json_decode('{"text":"hi","n":[1,{"a":1.0}],"empty":{},"keys":{"0":"a"},"big":9007199254740993,"nil":null}', false);
        self::assertSame(
            json_decode('{"text":"hi","n":[1,{"a":1.0}],"empty":{},"keys":{"0":"a"},"big":9007199254740993,"nil":null}', true),
            Payload::toArray($data),
        );
        self::assertSame('hi', Payload::toArray($data)['text']);
        self::assertSame(1.0, Payload::toArray($data)['n'][1]['a']);
        foreach (['s', 1, 1.5, true, null] as $scalar) {
            self::assertSame($scalar, Payload::toArray($scalar));
        }
        // The payload itself is untouched.
        self::assertInstanceOf(\stdClass::class, $data->empty);
    }

    public function testMapsAndLists(): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        // Numeric map keys stay object keys.
        $c->publish(channel: 'c', data: 1, tags: ['0' => 'a', '1' => 'b']);
        self::assertStringContainsString('"tags":{"0":"a","1":"b"}', $fake->last()['body']);
        try {
            $c->publish(channel: 'c', data: 1, tags: ['k' => 1]);
            self::fail('sent');
        } catch (InvalidRequestException $e) {
            self::assertStringContainsString('tags[k] must be a string', $e->getMessage());
        }
    }
}
