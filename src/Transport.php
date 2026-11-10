<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * Chatwork に本文を送るところ。テストでは差し替える
 */
interface Transport
{
    public function send(string $token, string $room, string $body): void;
}
