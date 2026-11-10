<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\TimeoutMessage;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

/**
 * 送るところ。タイムアウトを付けて、php-chatwork と同じ形で Chatwork の API に送る
 */
final class TimeoutMessageTest extends TestCase
{
    #[Test]
    public function タイムアウトを付けて部屋に本文を送る(): void
    {
        /** @var \ArrayObject<string, mixed> $seen */
        $seen = new \ArrayObject();
        $handler = static function (RequestInterface $request, array $options) use ($seen): PromiseInterface {
            $seen['url'] = (string) $request->getUri();
            $seen['token'] = $request->getHeaderLine('X-ChatWorkToken');
            $seen['body'] = (string) $request->getBody();
            $seen['timeout'] = $options['timeout'] ?? null;
            $seen['connect_timeout'] = $options['connect_timeout'] ?? null;

            return new FulfilledPromise(new Response(200, [], '{"message_id":"1"}'));
        };

        new TimeoutMessage('probe-token')->withTimeout(2.5)->withHandler($handler)->room('12345')->body('本文')->send();

        $this->assertSame('https://api.chatwork.com/v2/rooms/12345/messages', $seen['url']);
        $this->assertSame('probe-token', $seen['token']);
        $this->assertSame('body=' . rawurlencode("\n本文"), $seen['body']);
        $this->assertSame(2.5, $seen['timeout']);
        $this->assertSame(2.5, $seen['connect_timeout']);
    }

    #[Test]
    public function 既定のタイムアウトは3秒(): void
    {
        /** @var \ArrayObject<string, mixed> $seen */
        $seen = new \ArrayObject();
        $handler = static function (RequestInterface $request, array $options) use ($seen): PromiseInterface {
            $seen['timeout'] = $options['timeout'] ?? null;

            return new FulfilledPromise(new Response(200, [], '{}'));
        };

        new TimeoutMessage('probe-token')->withHandler($handler)->room('1')->body('b')->send();

        $this->assertSame(3.0, $seen['timeout']);
    }
}
