<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\Cmp;
use Centrifugal\Phpcent\DeviceMetaUpdate;
use Centrifugal\Phpcent\DeviceTimezoneUpdate;
use Centrifugal\Phpcent\DeviceUserUpdate;
use Centrifugal\Phpcent\FcmPushNotification;
use Centrifugal\Phpcent\FilterNodeOp;
use Centrifugal\Phpcent\Gen\ConstGroup;
use Centrifugal\Phpcent\Gen\Proto;
use Centrifugal\Phpcent\Gen\Rules;
use Centrifugal\Phpcent\KeyMode;
use Centrifugal\Phpcent\Op;
use Centrifugal\Phpcent\Platform;
use Centrifugal\Phpcent\Provider;
use Centrifugal\Phpcent\Proxy;
use Centrifugal\Phpcent\PushLimitStrategy;
use Centrifugal\Phpcent\PushNotification;
use Centrifugal\Phpcent\PushRecipient;
use Centrifugal\Phpcent\Status;
use Centrifugal\Phpcent\SubscribeOptionOverride;
use Centrifugal\Phpcent\Tests\Support\FakeCentrifugo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the generator reads in the protos' comments, and what the generated
 * code does with it.
 */
final class GeneratorTest extends TestCase
{
    private static function proto(string $name): Proto
    {
        return Proto::parse((string) file_get_contents(__DIR__ . "/../proto/{$name}"), $name);
    }

    /**
     * The presence wrappers and the required fields left unchecked are found
     * in the comments. The sets are pinned here, so that a changed comment
     * shows as a failed test rather than as a field sent or checked
     * differently.
     */
    public function testPresenceFromComments(): void
    {
        $found = static function (Proto $s): array {
            $wrappers = [];
            $unchecked = [];
            foreach ($s->messages as $m) {
                if (Rules::isWrapper($m)) {
                    $wrappers[] = $m->name;
                }
                foreach ($m->fields as $f) {
                    if (Rules::required($f) && Rules::emptyHasMeaning($f)) {
                        $unchecked[] = "{$m->name}.{$f->name}";
                    }
                }
            }

            return [$wrappers, $unchecked];
        };
        self::assertSame([
            ['BoolValue', 'Int32Value', 'DeviceUserUpdate', 'DeviceTimezoneUpdate', 'DeviceLocaleUpdate', 'DeviceMetaUpdate'],
            [
                'InvalidateUserTokensRequest.user',
                'DeviceUserUpdate.user', 'DeviceTimezoneUpdate.timezone', 'DeviceLocaleUpdate.locale', 'DeviceMetaUpdate.meta',
                'UserTopicUpdateRequest.user',
            ],
        ], $found(self::proto('api.proto')));
        self::assertSame([['BoolValue', 'Int32Value'], []], $found(self::proto('proxy.proto')));
    }

    public function testRequiredIsTheWordAndItsPeriod(): void
    {
        $s = Proto::parse(<<<'PROTO'
            message A {
              // a is required. Required.
              string a = 1;
              // b is required unless c is set. Required unless c is set.
              string b = 2;
              // c is needed with d. Required when d is set.
              string c = 3;
              // e clears with an empty value. Required. "" clears it.
              string e = 4;
              // f is a map. Required. An empty map clears it.
              map<string, string> f = 5;
            }
            PROTO, 'test.proto');
        $fields = $s->byName['A']->fields;
        self::assertSame([true, false, false, true, true], array_map(Rules::required(...), $fields));
        self::assertSame([false, false, false, true, true], array_map(Rules::emptyHasMeaning(...), $fields));
    }

    public function testEmptyPresenceWrapperIsSent(): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        $c->deviceUpdate(ids: ['d'], userUpdate: new DeviceUserUpdate(user: ''), timezoneUpdate: new DeviceTimezoneUpdate(timezone: ''), metaUpdate: new DeviceMetaUpdate(meta: []));
        // Objects, never [], even for an empty map.
        self::assertSame('{"ids":["d"],"user_update":{},"meta_update":{},"timezone_update":{}}', $fake->last()['body']);
        $c->deviceUpdate(ids: ['d']);
        self::assertSame('{"ids":["d"]}', $fake->last()['body']);
        // A BoolValue alike: false is sent, null is not.
        $c->subscribe(channel: 'ch', user: 'u', override: new SubscribeOptionOverride(presence: false));
        self::assertSame('{"channel":"ch","user":"u","override":{"presence":{"value":false}}}', $fake->last()['body']);
    }

    public function testEmptyMessageIsNotSent(): void
    {
        $fake = new FakeCentrifugo();
        FakeCentrifugo::client($fake)->sendPushNotification(
            recipient: new PushRecipient(fcmTokens: ['t']),
            notification: new PushNotification(fcm: new FcmPushNotification(message: ['notification' => ['title' => 't']])),
            limitStrategy: new PushLimitStrategy(),
        );
        // Centrifugo PRO refuses "limit_strategy": {} with code 107.
        self::assertSame('{"recipient":{"fcm_tokens":["t"]},"notification":{"fcm":{"message":{"notification":{"title":"t"}}}}}', $fake->last()['body']);
    }

    /** @return iterable<string, array{list<string>, ?list<string>}> */
    public static function sentences(): iterable
    {
        yield 'one line' => [['op is the operation. Required. One of: add, remove, set.'], ['add', 'remove', 'set']];
        yield 'wrapped' => [['provider is the provider. Required. One of: fcm,', 'hms, apns, webpush.'], ['fcm', 'hms', 'apns', 'webpush']];
        yield 'underscores' => [['key_mode. One of: if_new, if_exists.'], ['if_new', 'if_exists']];
        yield 'none' => [['channel is the channel. Required.'], null];
    }

    /**
     * @param list<string> $doc
     * @param ?list<string> $want
     */
    #[DataProvider('sentences')]
    public function testListedValues(array $doc, ?array $want): void
    {
        self::assertSame($want, Rules::listedValues($doc));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function malformedSentences(): iterable
    {
        yield 'no period' => [['One of: a, b']];
        yield 'not a value' => [['One of: A, b.']];
        yield 'quoted' => [['One of: "a", b.']];
        yield 'listed twice' => [['One of: a, a.']];
        yield 'empty' => [['One of: .']];
    }

    /** @param list<string> $doc */
    #[DataProvider('malformedSentences')]
    public function testMalformedSentenceFails(array $doc): void
    {
        $this->expectException(\RuntimeException::class);
        Rules::listedValues($doc);
    }

    /** @return array<string, list<string>> class => constants, as value => name */
    private static function constClasses(string $proto): array
    {
        $out = [];
        foreach (Rules::constGroups(Proto::parse($proto, 'test.proto'), static fn(string $name): bool => $name !== 'Skipped') as $g) {
            $out[$g->className()] = array_map(ConstGroup::constName(...), $g->values);
        }

        return $out;
    }

    public function testConstantNames(): void
    {
        // The set listed in the most messages keeps the short name.
        self::assertSame([
            'Op' => ['ADD', 'REMOVE'],
            'COp' => ['AND', 'OR'],
            'KeyMode' => ['IF_NEW'],
            'MMatch' => ['WILDCARD'],
        ], self::constClasses(<<<'PROTO'
            message A {
              string op = 1; // One of: add, remove.
            }
            message B {
              string op = 1; // One of: add, remove.
            }
            message C {
              string op = 1; // One of: and, or.
              string key_mode = 2; // One of: if_new.
            }
            message M {
              string match = 1; // A PHP keyword. One of: wildcard.
            }
            message Skipped {
              string op = 1; // Not generated. One of: x, y.
            }
            PROTO));
    }

    public function testConstantNamesTie(): void
    {
        self::assertSame(['AOp' => ['ADD'], 'BOp' => ['AND']], self::constClasses(<<<'PROTO'
            message A {
              string op = 1; // One of: add.
            }
            message B {
              string op = 1; // One of: and.
            }
            PROTO));
    }

    /**
     * Each constant equals its value, and the classes are those the protos
     * list: the proxy's FilterNode op is Op, as no other proxy field is named
     * op, and the API's is FilterNodeOp.
     */
    public function testEveryListedValueIsAConstant(): void
    {
        $seen = [];
        foreach (['api.proto' => 'Centrifugal\\Phpcent\\', 'proxy.proto' => 'Centrifugal\\Phpcent\\Proxy\\'] as $proto => $ns) {
            $s = self::proto($proto);
            foreach (Rules::constGroups($s, static fn(string $name): bool => true) as $g) {
                $class = new \ReflectionClass($ns . $g->className());
                self::assertSame(array_combine(array_map(ConstGroup::constName(...), $g->values), $g->values), $class->getConstants(), $class->getName());
                $seen[] = $class->getName();
            }
        }
        self::assertSame([
            FilterNodeOp::class, Op::class, Cmp::class, Provider::class, Platform::class, Status::class, KeyMode::class,
            Proxy\Op::class, Proxy\Cmp::class, Proxy\Protocol::class, Proxy\Encoding::class, Proxy\Allow::class,
            Proxy\ChannelsCapabilityMatch::class, Proxy\KeyMode::class, Proxy\Type::class,
        ], $seen);
        self::assertSame('if_new', KeyMode::IF_NEW);
        self::assertSame('and', FilterNodeOp::AND);
        self::assertSame('binary', Proxy\Encoding::BINARY);
    }

    public function testValueWithoutAConstantIsSent(): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        $c->deviceRegister(provider: Provider::FCM, platform: Platform::ANDROID, token: 't');
        self::assertSame('{"provider":"fcm","token":"t","platform":"android"}', $fake->last()['body']);
        // The fields stay strings: a value Centrifugo adds is sendable before a sync.
        $c->deviceRegister(provider: 'newpush', platform: 'tv', token: 't');
        self::assertSame('{"provider":"newpush","token":"t","platform":"tv"}', $fake->last()['body']);
    }
}
