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

    public function isApp(string $path): bool
    {
        $base = $this->basePath . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) && ! str_starts_with($path, $base . 'vendor' . DIRECTORY_SEPARATOR);
    }

    public function isVendor(string $relative): bool
    {
        return str_starts_with($relative, 'vendor/');
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

        // isset で絞った段の型（file と line がある）を返り値まで保つため、ループで書く
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line']) && $this->isApp($frame['file'])) {
                return ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return null;
    }
}
