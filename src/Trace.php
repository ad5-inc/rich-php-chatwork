<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Throwable;

/**
 * 引数の値を含めない道筋
 *
 * getTraceAsString() は php.ini の zend.exception_ignore_args が Off だと引数の値（人の名前やアドレス）を含むので使わない。
 * vendor の中を続けて通る段は 1 行にまとめ、アプリの段を FRAMES まで並べる。名前は Names を通す（匿名クラス・クロージャのパス）
 */
final class Trace
{
    public const FRAMES = 10;

    private readonly Names $names;

    public function __construct(private readonly Source $source)
    {
        $this->names = new Names($source);
    }

    public function of(Throwable $e): string
    {
        $lines = [];
        $shown = 0;
        $vendor = 0;
        $frames = $e->getTrace();

        // 段を数えながら途中で打ち切り、vendor の段をまとめるので、副作用のあるループで書く
        foreach ($frames as $index => $frame) {
            $file = isset($frame['file']) ? $this->source->relative($frame['file']) : null;

            if ($file !== null && $this->source->isVendor($file)) {
                $vendor++;
                continue;
            }
            if ($shown === self::FRAMES) {
                $lines[] = sprintf('（以下 %d 段はログ）', count($frames) - $index + $vendor);
                $vendor = 0;
                break;
            }
            if ($vendor > 0) {
                $lines[] = "（vendor の {$vendor} 段）";
                $vendor = 0;
            }

            $call = $this->names->class($frame['class'] ?? '') . ($frame['type'] ?? '') . $this->names->function($frame['function']) . '()';
            $lines[] = "#{$index} " . ($file === null ? $call : "{$file}:" . ($frame['line'] ?? '?') . " {$call}");
            $shown++;
        }
        if ($vendor > 0) {
            $lines[] = "（vendor の {$vendor} 段）";
        }

        return implode("\n", $lines);
    }
}
