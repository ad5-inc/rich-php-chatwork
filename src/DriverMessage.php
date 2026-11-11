<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * DB のドライバーの文言（PDO の errorInfo[2]）の許可リスト。当たった形だけを、値を伏せて出す
 *
 * 値は「終わりの語」に向けて最長一致で取るので、値に ' があっても残らない（O'Brien など）。
 * 「なし」の形（列名だけを含む）は引用符の中に ' を許さず、当たらなければ許可リストの外として扱う。
 * 形を足すときは、IN_PLACE（別の例外が写した文言に当てる版）も一緒に足す
 */
final class DriverMessage
{
    /**
     * 形 => 伏せるか。伏せる形は $1（前）$2（値）$3（後ろ）に分ける
     *
     * @var array<string, bool>
     */
    private const SHAPES = [
        "/\\AData too long for column '[^']*' at row \\d+\\z/iu" => false,
        "/\\A(Duplicate entry ')(.*)(' for key '[^']*')\\z/isu" => true,
        "/\\A(Incorrect \\w+ value: ')(.*)(' for column '[^']*'(?: at row \\d+)?)\\z/isu" => true,
        "/\\A(Truncated incorrect \\w+ value: ')(.*)(')\\z/isu" => true,
        "/\\AUnknown column '[^']*' in '[^']*'\\z/iu" => false,
        "/\\A([^']*? near ')(.*)(' at line \\d+)\\z/isu" => true,
        '/\A(Cannot add or update a child row: a foreign key constraint fails \()(.*)(\))\z/isu' => true,
        "/\\AColumn '[^']*' cannot be null\\z/iu" => false,
    ];

    /** 写された文言の中で、その場で伏せる版（$1・$3 を残し $2 を伏せる） */
    public const IN_PLACE = [
        "/(Duplicate entry ')(.*)(' for key ')/isu",
        "/(Incorrect \\w+ value: ')(.*)(' for column ')/isu",
        "/(Truncated incorrect \\w+ value: ')(.*)(')/isu",
        "/( near ')(.*)(' at line \\d+)/isu",
        '/(a foreign key constraint fails \()(.*)(\))/isu',
    ];

    /**
     * 許可リストに当たれば値を伏せた文言、当たらなければ null
     */
    public static function redact(string $message): ?string
    {
        foreach (self::SHAPES as $pattern => $hasValue) {
            if (preg_match($pattern, $message) !== 1) {
                continue;
            }

            return $hasValue ? Text::replace($pattern, '$1' . Redactor::MARK . '$3', $message) : $message;
        }

        return null;
    }
}
