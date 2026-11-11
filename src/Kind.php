<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * 例外の種類ごとの決まった部分。summary は件名の要約、items は「ラベル: 値」の項目（件名に入れた物は inSummary）
 */
final readonly class Kind
{
    /**
     * @param list<array{label: string, value: string, inSummary: bool}> $items
     */
    public function __construct(
        public ?string $summary,
        public array $items,
    ) {
    }
}
