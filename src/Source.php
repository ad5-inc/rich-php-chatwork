<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Throwable;

/**
 * アプリのソースの場所。base_path() から見た相対パスにし、vendor とアプリの外を見分ける
 */
final class Source
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function relative(string $path): string
    {
        $base = $this->basePath . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * 文字の中のどこにあっても、base_path() を外して相対パスにする（文言・クロージャの名前）
     */
    public function relativeIn(string $text): string
    {
        return str_replace($this->basePath . DIRECTORY_SEPARATOR, '', $text);
    }

    /**
     * アプリのコード。vendor とコンパイル済みのビュー（中身が読めない）は外す
     */
    public function isApp(string $path): bool
    {
        $base = $this->basePath . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) && ! $this->isVendor($this->relative($path)) && ! $this->isCompiledView($this->relative($path));
    }

    public function isVendor(string $relative): bool
    {
        return str_starts_with($relative, 'vendor/');
    }

    public function isCompiledView(string $relative): bool
    {
        return str_starts_with($relative, 'storage/framework/views/');
    }

    /**
     * 例外の場所がアプリならそこ、vendor の中なら道筋をたどって最初に出てくるアプリの段
     *
     * @return array{file: string, line: int}|null
     */
    public function appLocation(Throwable $e): ?array
    {
        if ($this->isApp($e->getFile())) {
            return ['file' => $e->getFile(), 'line' => $e->getLine()];
        }

        return $this->firstAppFrame($e->getTrace());
    }

    /**
     * 本文の「アプリの行」。TypeError の called in は呼び出した側にする。$view は包んだビュー（アプリの段が無いときに添える）
     */
    public function appLine(Throwable $e, ?string $view): AppLine
    {
        $thrown = basename($e->getFile()) . ':' . $e->getLine();
        $trace = $e->getTrace();

        if ($e instanceof \TypeError && str_contains($e->getMessage(), 'called in') && isset($trace[0]['file'], $trace[0]['line']) && $this->isApp($trace[0]['file'])) {
            return new AppLine($trace[0]['file'], $trace[0]['line'], $this->relative($trace[0]['file']) . ':' . $trace[0]['line'] . "（定義は {$thrown}）");
        }

        if ($this->isApp($e->getFile())) {
            return new AppLine($e->getFile(), $e->getLine(), $this->relative($e->getFile()) . ':' . $e->getLine());
        }

        $relative = $this->relative($e->getFile());
        $where = match (true) {
            $this->isVendor($relative) => "vendor の {$thrown} で投げた",
            $this->isCompiledView($relative) && $view !== null => "ビュー {$view} の中で投げた",
            default => "{$thrown} で投げた",
        };

        $frame = $this->firstAppFrame($trace);
        if ($frame !== null) {
            return new AppLine($frame['file'], $frame['line'], $this->relative($frame['file']) . ':' . $frame['line'] . "（{$where}）");
        }

        return new AppLine(null, null, $view !== null ? "無し（ビュー {$view} の中）" : "無し（{$where}）");
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @return array{file: string, line: int}|null
     */
    private function firstAppFrame(array $trace): ?array
    {
        // 型を絞った段（file と line がある）を返り値まで保つため、ループで書く
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            if (is_string($file) && is_int($line) && $this->isApp($file)) {
                return ['file' => $file, 'line' => $line];
            }
        }

        return null;
    }
}
