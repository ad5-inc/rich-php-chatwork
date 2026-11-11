<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Transport;
use RuntimeException;

final class FakeTransport implements Transport
{
    /** @var list<array{token: string, room: string, body: string}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(#[\SensitiveParameter] string $token, string $room, #[\SensitiveParameter] string $body): void
    {
        if ($this->fail) {
            throw new RuntimeException('Chatwork is down');
        }

        $this->sent[] = ['token' => $token, 'room' => $room, 'body' => $body];
    }
}
