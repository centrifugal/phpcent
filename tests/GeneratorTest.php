<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\DeviceMetaUpdate;
use Centrifugal\Phpcent\DeviceTimezoneUpdate;
use Centrifugal\Phpcent\DeviceUserUpdate;
use Centrifugal\Phpcent\FcmPushNotification;
use Centrifugal\Phpcent\Gen\Proto;
use Centrifugal\Phpcent\Gen\Rules;
use Centrifugal\Phpcent\PushLimitStrategy;
use Centrifugal\Phpcent\PushNotification;
use Centrifugal\Phpcent\PushRecipient;
use Centrifugal\Phpcent\SubscribeOptionOverride;
use Centrifugal\Phpcent\Tests\Support\FakeCentrifugo;
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
        $c->deviceUpdate(ids: ['d'], userUpdate: new DeviceUserUpdate(user: ''), timezoneUpdate: new DeviceTimezoneUpdate(timezone: ''), metaUpdate: new DeviceMetaUpdate());
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
}
