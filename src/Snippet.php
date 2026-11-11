<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use SplFileObject;
use Throwable;

/**
 * アプリのコードで例外に最も近い行と、その前後の行。[code] の中に入れる形（伏せて逃がし、幅で切った物）で返す
 *
 * 読むのはアプリのソースだけで、vendor とアプリの外のファイルは読まない。どのフォルダまで読むかは Reporter が設定で絞る
 */
final class Snippet
{
    public const CONTEXT_LINES = 2;

    /** 1 行の表示幅の上限。ビルドしたファイルなどの長い行で本文が埋まらないようにする */
    private const LINE_WIDTH = 160;

    public function __construct(private readonly Source $source, private readonly ?Redactor $redactor = null)
    {
    }

    public function of(Throwable $e): ?string
    {
        $location = $this->source->appLocation($e);

        return $location === null ? null : $this->at($location['file'], $location['line']);
    }

    /**
     * 行番号付きで並べ、例外の行に「>」を付ける。読めないときは null
     */
    public function at(string $file, int $line): ?string
    {
        if (! is_readable($file)) {
            return null;
        }

        $redact = $this->redactor === null ? null : $this->redactor->snippet(...);

        // 行を読み進めながら番号を付け、ファイルの終わりで打ち切るので、副作用のあるループで書く
        try {
            $reader = new SplFileObject($file);
            $first = max(1, $line - self::CONTEXT_LINES);
            $last = $line + self::CONTEXT_LINES;
            $width = strlen((string) $last);
            $rows = [];

            $reader->seek($first - 1);
            for ($number = $first; $number <= $last && ! $reader->eof(); $number++) {
                $current = $reader->current();
                $text = Text::field(rtrim(is_string($current) ? $current : '', "\r\n"), true, $redact, self::LINE_WIDTH);
                $mark = $number === $line ? '>' : ' ';
                $rows[] = rtrim(sprintf("%s %{$width}d | %s", $mark, $number, $text));
                $reader->next();
            }
        } catch (Throwable) {
            return null;
        }

        return $rows === [] ? null : implode("\n", $rows);
    }
}
