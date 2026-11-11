<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Source;

/**
 * 作り物のアプリの置き場（一時ディレクトリ）。例の本文を、実在しないアプリのパスと行で組み立てるために使う
 */
final class FixtureApp
{
    public readonly string $base;

    public function __construct()
    {
        $this->base = sys_get_temp_dir() . '/rich-chatwork-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0700, true);
    }

    public function source(): Source
    {
        return new Source($this->base);
    }

    public function path(string $relative): string
    {
        return $this->base . '/' . $relative;
    }

    /**
     * $line の行に $lines を並べたファイルを置く。ほかの行は空にする
     *
     * @param array<int, string> $lines 行番号 => 中身
     */
    public function file(string $relative, array $lines): string
    {
        $path = $this->path($relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        $last = $lines === [] ? 1 : max(array_keys($lines)) + 5;
        $rows = [];
        for ($number = 1; $number <= $last; $number++) {
            $rows[] = $lines[$number] ?? '';
        }
        file_put_contents($path, implode("\n", $rows) . "\n");

        return $path;
    }

    /**
     * vendor の段を $count 個
     *
     * @return list<array<string, mixed>>
     */
    public function vendorFrames(int $count): array
    {
        $frames = [];
        for ($index = 0; $index < $count; $index++) {
            $frames[] = ['file' => $this->path('vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php'), 'line' => 100 + $index, 'function' => 'handle', 'class' => 'Illuminate\Pipeline\Pipeline', 'type' => '->'];
        }

        return $frames;
    }

    public function remove(): void
    {
        $this->removeDirectory($this->base);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
