<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use AD5jp\PhpChatwork\Message;

/**
 * ad5jp/php-chatwork で送る
 *
 * php-chatwork はタイムアウトを指定できない（Guzzle の既定は無期限）。Chatwork が応答しないと、
 * 例外のハンドラーがここで止まる。困ったら、php-chatwork に Client を渡す口を足すか、この実装を Http クライアントに替える
 */
final class PhpChatworkTransport implements Transport
{
    public function send(string $token, string $room, string $body): void
    {
        new Message($token)->room($room)->body($body)->send();
    }
}
