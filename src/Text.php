<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Closure;
use Throwable;

/**
 * 本文に入れる値の通し方。項目ごとに 文字を直す → 伏せる → 記法を逃がす → 幅で切る の順に通す
 *
 * 順番を入れ替えると壊れる: 伏せる前に逃がすと規則の当たり方が変わり、切った後に逃がすと幅を超える
 */
final class Text
{
    public const REDACT_FAILED = '（伏せられなかったので省いた。ログを見る）';

    /**
     * @param (Closure(string): ?string)|null $redact 伏せる処理。null を返したら項目ごと省く
     */
    public static function field(string $value, bool $code, ?Closure $redact, int $width): string
    {
        $text = self::scrub($value, $code);
        if ($text !== null && $redact !== null) {
            $text = $redact($text);
        }
        if ($text === null) {
            return self::REDACT_FAILED;
        }

        return self::cut(self::escape($text, $code), $width);
    }

    /**
     * 不正な UTF-8 を直し、制御文字を落とす。タブは空白 4 つ。[code] の外は改行も空白にする
     */
    public static function scrub(string $value, bool $code): ?string
    {
        $text = str_replace("\t", '    ', mb_scrub($value, 'UTF-8'));
        $text = $code ? str_replace(["\r\n", "\r"], "\n", $text) : str_replace(["\r\n", "\r", "\n"], ' ', $text);

        return self::replace('/(?!\n)\p{Cc}/u', '', $text);
    }

    /**
     * [code] の外は [ ] を全角に。[code] の中は記法の始まり（[ の後ろが英字か /）だけを全角にし、['key']・[$i]・[] は残す
     */
    public static function escape(string $text, bool $code): string
    {
        if (! $code) {
            return strtr($text, ['[' => '［', ']' => '］']);
        }

        // 置き換えに失敗したら、残すより全部を全角にする方を選ぶ
        return self::replace('/\[(?=[A-Za-z\/])/u', '［', $text) ?? str_replace('[', '［', $text);
    }

    /**
     * 表示幅（全角は 2）で切る
     */
    public static function cut(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, $width, '…', 'UTF-8');
    }

    /**
     * preg_replace(_callback) の失敗（パターンの誤り・バックトラックの上限）を null で返す。警告も投げさせない
     *
     * @param string|(Closure(array<array-key, string>): string) $replacement
     */
    public static function replace(string $pattern, string|Closure $replacement, string $subject): ?string
    {
        try {
            $result = is_string($replacement)
                ? @preg_replace($pattern, $replacement, $subject)
                : @preg_replace_callback($pattern, $replacement, $subject);
        } catch (Throwable) {
            return null;
        }

        return is_string($result) && preg_last_error() === PREG_NO_ERROR ? $result : null;
    }
}
