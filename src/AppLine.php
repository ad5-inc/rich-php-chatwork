<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * 本文の「アプリの行」。file・line は前後のコードを読む場所で、アプリの段が無ければ null
 */
final readonly class AppLine
{
    public function __construct(
        public ?string $file,
        public ?int $line,
        public string $text,
    ) {
    }
}
