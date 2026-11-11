<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * ad5jp/php-chatwork で送る。Chatwork が応答しないとき、例外のハンドラーが止まり続けないよう 180 秒で切る
 */
final class PhpChatworkTransport implements Transport
{
    public function __construct(private readonly float $timeout = 180.0)
    {
    }

    public function send(#[\SensitiveParameter] string $token, string $room, #[\SensitiveParameter] string $body): void
    {
        new TimeoutMessage($token)->withTimeout($this->timeout)->room($room)->body($body)->send();
    }
}
