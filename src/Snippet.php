<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use SplFileObject;
use Throwable;

/**
 * アプリのコードで例外に最も近い行と、その前後の行
 *
 * 読むのはアプリのソースだけで、vendor とアプリの外のファイルは読まない
 */
final class Snippet
{
    public const CONTEXT_LINES = 3;

    /** 1 行の長さの上限。ビルドしたファイルなどの長い行で本文が埋まらないようにする */
    private const LINE_WIDTH = 160;

    public function __construct(private readonly Source $source)
    {
    }

    /**
     * 1 行目に ファイル:行、続けて行番号付きで並べ、例外の行に「>」を付ける。読めないときは null
     */
    public function of(Throwable $e): ?string
    {
        $location = $this->source->appLocation($e);

        if ($location === null || ! is_readable($location['file'])) {
            return null;
        }

        ['file' => $file, 'line' => $line] = $location;

        // 行を読み進めながら番号を付け、ファイルの終わりで打ち切るので、副作用のあるループで書く
        try {
            $reader = new SplFileObject($file);
            $first = max(1, $line - self::CONTEXT_LINES);
            $last = $line + self::CONTEXT_LINES;
            $width = strlen((string) $last);
            $rows = [$this->source->relative($file) . ':' . $line];

            $reader->seek($first - 1);
            for ($number = $first; $number <= $last && ! $reader->eof(); $number++) {
                $current = $reader->current();
                $text = rtrim(str_replace("\t", '    ', is_string($current) ? $current : ''), "\r\n");
                $mark = $number === $line ? '>' : ' ';
                $rows[] = sprintf("%s %{$width}d | %s", $mark, $number, mb_strimwidth($text, 0, self::LINE_WIDTH, '…'));
                $reader->next();
            }
        } catch (Throwable) {
            return null;
        }

        return implode("\n", $rows);
    }
}
