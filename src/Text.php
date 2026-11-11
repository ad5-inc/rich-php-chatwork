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
     * Chatwork が [code] の外で絵文字に替える記号。大文字小文字を区別する（Model::Delete の :D は替わり、report:daily の :d は替わらない）
     */
    private const EMOTICONS = [
        ':)', ':(', ':D', '8-)', ':o', ';)', ';(', ':|', ':*', ':p', ':^)', '|-)', ']:)', '8-|', ':#',
        '(sweat)', '(blush)', '(inlove)', '(talk)', '(yawn)', '(puke)', '(emo)', '(nod)', '(shake)', '(^^;)', '(whew)',
        '(clap)', '(bow)', '(roger)', '(flex)', '(dance)', '(:/)', '(gogo)', '(think)', '(please)', '(quick)', '(anger)',
        '(devil)', '(lightbulb)', '(*)', '(h)', '(F)', '(cracker)', '(eat)', '(^)', '(coffee)', '(beer)', '(handshake)', '(y)',
    ];

    /** 絵文字の記号の 1 文字目の後ろに挟む、見えない文字（WORD JOINER。改行の位置も作らない） */
    private const JOINER = "\u{2060}";

    /**
     * [code] の外は [ ] を全角にし、絵文字の記号を崩す。[code] の中は記法の始まり（[ の後ろが英字か /）だけを全角にし、
     * ['key']・[$i]・[] は残す（[code] の中は絵文字に替わらない）
     */
    public static function escape(string $text, bool $code): string
    {
        if (! $code) {
            return self::breakEmoticons(strtr($text, ['[' => '［', ']' => '］']));
        }

        // 置き換えに失敗したら、残すより全部を全角にする方を選ぶ
        return self::replace('/\[(?=[A-Za-z\/])/u', '［', $text) ?? str_replace('[', '［', $text);
    }

    /**
     * 絵文字の記号の 1 文字目の後ろに見えない文字を挟む（]:) は ] を全角にした後なので、中の :) で崩れる）
     */
    public static function breakEmoticons(string $text): string
    {
        $symbols = self::EMOTICONS;
        $pattern = '/' . implode('|', array_map(static fn (string $symbol): string => preg_quote($symbol, '/'), $symbols)) . '/u';

        return self::replace($pattern, static fn (array $match): string => mb_substr($match[0], 0, 1) . self::JOINER . mb_substr($match[0], 1), $text) ?? $text;
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
