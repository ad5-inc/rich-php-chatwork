<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * 本文の並び。地の文の行・[code] の枠・[hr] を順に積み、Chatwork の記法の本文にする
 *
 * 枠の前後には改行を足さない（[/title][code]・[/code]種類: と続ける）。地の文の行は、後ろに何か続くときだけ改行で終える。
 * 中身はここに来る前に Text で通しておく（ここでは逃がさない）
 */
final class Body
{
    public const MAX_LENGTH = 30000;

    /** @var list<array{type: 'text'|'code'|'hr', text: string, block: ?string}> */
    private array $segments = [];

    public function text(string $text, ?string $block = null): self
    {
        $this->segments[] = ['type' => 'text', 'text' => $text, 'block' => $block];

        return $this;
    }

    public function code(string $text, ?string $block = null): self
    {
        $this->segments[] = ['type' => 'code', 'text' => $text, 'block' => $block];

        return $this;
    }

    public function hr(?string $block = null): self
    {
        $this->segments[] = ['type' => 'hr', 'text' => '', 'block' => $block];

        return $this;
    }

    /**
     * $block の塊を落とし、代わりに $replacement の行を置く（null なら何も置かない）
     */
    public function drop(string $block, ?string $replacement = null): self
    {
        $kept = [];
        $replaced = false;
        foreach ($this->segments as $segment) {
            if ($segment['block'] !== $block) {
                $kept[] = $segment;
            } elseif ($replacement !== null && ! $replaced) {
                $kept[] = ['type' => 'text', 'text' => $replacement, 'block' => null];
                $replaced = true;
            }
        }
        $this->segments = $kept;

        return $this;
    }

    public function render(string $title): string
    {
        $body = "[info][title]{$title}[/title]";
        $last = count($this->segments) - 1;

        foreach ($this->segments as $index => $segment) {
            $body .= match ($segment['type']) {
                'text' => $segment['text'] . ($index < $last ? "\n" : ''),
                'code' => "[code]{$segment['text']}[/code]",
                'hr' => '[hr]',
            };
        }

        return $body . '[/info]';
    }
}
