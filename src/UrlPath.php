<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * URL のパスの段の許可リスト。英小文字の段と v1 のような段だけ残し、ほかの段（ID・トークン・Webhook の秘密）は {…} にする。
 * 大文字を許すと英字だけの秘密の段が残りうるので許さない（Telegram の sendMessage も {…} になる）。
 * 組み立て直す HTTP クライアントの文言（KnownMessage）と、文言の中の URL（Redactor）で同じ物を使う
 */
final class UrlPath
{
    private const SEGMENTS = ['/\A[a-z][a-z_-]{0,30}\z/', '/\Av\d{1,2}\z/'];

    /**
     * 段は / と \/（JSON でエスケープした URL）のどちらでも区切り、区切りは元の形で残す（混ざっていてもよい）
     */
    public static function redact(string $path): string
    {
        $parts = preg_split('/(\\\\\/|\/)/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$path];

        return implode('', array_map(
            static fn (string $part): string => $part === '' || $part === '/' || $part === '\\/' || array_any(self::SEGMENTS, static fn (string $pattern): bool => preg_match($pattern, $part) === 1) ? $part : '{…}',
            $parts,
        ));
    }
}
