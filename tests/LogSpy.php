<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

final class LogSpy
{
    /** @var list<string> */
    public array $messages = [];

    /** @var list<array<mixed>> */
    public array $contexts = [];
}
