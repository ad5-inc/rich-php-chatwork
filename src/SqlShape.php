<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * DB の例外の SQL を、値をすべて ? にした形にする（MySQL・MariaDB の接続だけ）
 *
 * 元にするのは QueryException::getSql()（値を埋める前の SQL）。それでも値は SQL に直接入りうる
 * （whereIntegerInRaw の数の並び・limit・JSON の列のパス・whereRaw や DB::statement に書いた文字列・スキーマの既定値など）ので、
 * 左から 1 文字ずつ読み、下の字句だけを残す。許可リストに無い字句はすべて ? にする。
 *
 * - バッククォートの識別子で中身が名前の文字だけの物（接続の設定のデータベース名と同じ物は {db}）
 * - 引用符の無い語のうち KEYWORDS の語（小文字にする）
 * - ? と、記号 ( ) , . = < > ! * + - / % | と空白
 *
 * 文字列・数・コメント・ほかの語や文字は ? になる（閉じが無ければ末尾まで飲む）。出す文字に [ ] 引用符・改行が入らないので、
 * [/code]・[To:…] などの記法は字句の段階で作れない。正規表現の後戻りは使わない（長い SQL でも 1 回の読み取りで終わる）。
 *
 * PostgreSQL の $$…$$ のような引用の形は読まない。ほかのドライバーに広げるときは、その引用の形を足してから isMysql の外へ出す
 */
final class SqlShape
{
    /** 読む SQL の長さ（バイト）。後ろは読まず、形の末尾に … を付ける */
    public const READ_BYTES = 20000;

    /** 出す形の表示幅 */
    public const WIDTH = 600;

    public const OMITTED = '…（長いので省いた。全文はログ）';

    /** 括弧の中の ? の並びを縮める数（これより少なければ縮めない） */
    private const LIST_MIN = 4;

    private const IDENTIFIER = '/\A[A-Za-z0-9_$]{1,64}\z/';

    private const SYMBOLS = '(),.=<>!*+-/%|';

    /**
     * 引用符の無いまま残す語。値（whereRaw("token = {$token}") で埋めた物）が残らないよう、SQL のキーワードとよく使う関数・型だけにする。
     * 足すときは、ふつうの値になりうる語（名前・状態の語など）を入れない
     */
    private const KEYWORDS = [
        'select', 'from', 'where', 'and', 'or', 'not', 'xor', 'in', 'is', 'null', 'like', 'between', 'exists', 'as', 'on',
        'join', 'inner', 'left', 'right', 'outer', 'cross', 'natural', 'straight_join', 'using', 'group', 'by', 'order', 'asc', 'desc',
        'having', 'limit', 'offset', 'insert', 'into', 'values', 'value', 'update', 'set', 'delete', 'replace', 'ignore',
        'duplicate', 'key', 'distinct', 'all', 'union', 'case', 'when', 'then', 'else', 'end', 'true', 'false', 'with',
        'recursive', 'for', 'share', 'lock', 'mode', 'skip', 'locked', 'nowait', 'interval', 'escape', 'collate', 'regexp',
        'rlike', 'div', 'mod', 'default', 'over', 'partition', 'window', 'rows', 'unsigned', 'signed', 'binary',
        'count', 'sum', 'avg', 'min', 'max', 'coalesce', 'ifnull', 'nullif', 'if', 'concat', 'group_concat', 'separator',
        'lower', 'upper', 'length', 'char_length', 'substring', 'trim', 'now', 'date', 'year', 'month', 'day', 'cast', 'convert',
        'current_timestamp', 'row_number', 'rand', 'greatest', 'least', 'abs', 'round', 'floor', 'ceil',
        'json_extract', 'json_unquote', 'json_contains', 'json_length', 'json_overlaps', 'json_search', 'json_set', 'json_remove',
        'create', 'table', 'alter', 'drop', 'add', 'column', 'index', 'unique', 'primary', 'foreign', 'references', 'constraint',
        'cascade', 'restrict', 'truncate', 'rename', 'to', 'modify', 'change', 'after', 'auto_increment', 'engine', 'charset',
        'char', 'varchar', 'int', 'integer', 'bigint', 'tinyint', 'smallint', 'decimal', 'double', 'float', 'text', 'longtext',
        'mediumtext', 'json', 'timestamp', 'datetime', 'time', 'boolean', 'enum', 'blob',
    ];

    /** @var array<string, int>|null */
    private static ?array $keywords = null;

    /**
     * SQL の形。MySQL・MariaDB の接続でなければ、または組み立てに失敗したら null（本文は今までどおり「SQL: （ログ）」になる）
     */
    public static function of(QueryException $e): ?string
    {
        try {
            return Details::isMysql($e) ? self::shape($e->getSql(), Details::database($e)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param ?string $database 接続の設定のデータベース名。同じ名前の識別子を {db} にする
     */
    public static function shape(string $sql, ?string $database = null): ?string
    {
        $truncated = strlen($sql) > self::READ_BYTES;
        $tokens = self::tokens(substr($sql, 0, self::READ_BYTES), $database);
        $text = trim(implode('', self::repeatedTuples(self::lists($tokens))));
        if ($text === '') {
            return null;
        }
        if ($truncated) {
            $text .= '…';
        }

        return mb_strwidth($text) > self::WIDTH ? mb_strimwidth($text, 0, self::WIDTH, self::OMITTED, 'UTF-8') : $text;
    }

    /**
     * 字句の並び。空白は 1 つの ' '、? にした物が続けば（空白を挟んでも）1 つにする
     *
     * @return list<string>
     */
    private static function tokens(string $sql, ?string $database): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (str_contains(" \t\n\r\f\v\0", $char)) {
                $i = self::skipWhile($sql, $i, static fn (string $c): bool => str_contains(" \t\n\r\f\v\0", $c));
                self::push($tokens, ' ');
            } elseif ($char === '`') {
                [$i, $name] = self::backquoted($sql, $i);
                self::push($tokens, match (true) {
                    $name === null || preg_match(self::IDENTIFIER, $name) !== 1 => '?',
                    $database !== null && $name === $database => '{db}',
                    default => "`{$name}`",
                });
            } elseif ($char === "'" || $char === '"') {
                $i = self::quoted($sql, $i);
                self::push($tokens, '?');
            } elseif ($char === '#' || ($char === '-' && $next === '-')) {
                // MySQL の -- は後ろに空白が要るが、どちらでも ? に飲む（伏せすぎる側）
                $i = self::skipWhile($sql, $i, static fn (string $c): bool => $c !== "\n" && $c !== "\r");
                self::push($tokens, '?');
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                self::push($tokens, '?');
            } elseif (self::isDigit($char)) {
                $i = self::number($sql, $i);
                self::push($tokens, '?');
            } elseif (self::isWordStart($char)) {
                $end = self::skipWhile($sql, $i, static fn (string $c): bool => self::isWordStart($c) || self::isDigit($c) || $c === '$');
                $word = strtolower(substr($sql, $i, $end - $i));
                $i = $end;
                self::push($tokens, isset(self::keywords()[$word]) ? $word : '?');
            } elseif ($char === '?') {
                $i++;
                $tokens[] = '?';
            } elseif (str_contains(self::SYMBOLS, $char)) {
                $i++;
                $tokens[] = $char;
            } else {
                // @・:・;・[・{・\・全角（UTF-8 の各バイト）など
                $i++;
                self::push($tokens, '?');
            }
        }

        return $tokens;
    }

    /**
     * ? にした字句と空白を積む。? にした物が続くときは 1 つにする（プレースホルダーの ? は push を通さないので、プレースホルダー同士は縮まない）
     *
     * @param list<string> $tokens
     */
    private static function push(array &$tokens, string $token): void
    {
        $last = $tokens === [] ? null : $tokens[array_key_last($tokens)];
        if ($token === ' ') {
            if ($last !== ' ') {
                $tokens[] = ' ';
            }

            return;
        }
        if ($token === '?') {
            $before = count($tokens) >= 2 ? $tokens[count($tokens) - 2] : null;
            if ($last === '?') {
                return;
            }
            if ($last === ' ' && $before === '?') {
                array_pop($tokens);

                return;
            }
        }
        $tokens[] = $token;
    }

    /**
     * バッククォートの識別子。`` は中身の ` として読む。閉じが無ければ末尾まで読み、名前は null
     *
     * @return array{int, ?string}
     */
    private static function backquoted(string $sql, int $start): array
    {
        $length = strlen($sql);
        $name = '';
        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] !== '`') {
                $name .= $sql[$i];
            } elseif (($sql[$i + 1] ?? '') === '`') {
                $name .= '`';
                $i++;
            } else {
                return [$i + 1, $name];
            }
        }

        return [$length, null];
    }

    /**
     * 引用符の文字列の終わりの次。\ の後ろの 1 文字と、引用符の重ね（''）は中身として読む。
     * NO_BACKSLASH_ESCAPES の DB では長く読みすぎるが、? に飲む側（伏せすぎる側）なので受け入れる
     */
    private static function quoted(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i + 1;
                }
                $i++;
            }
        }

        return $length;
    }

    /**
     * 数の終わりの次（1・1.5・1e-5・0x1f・0b01 など。数字で始まる語も含む）
     */
    private static function number(string $sql, int $start): int
    {
        $length = strlen($sql);
        $i = $start;
        while ($i < $length) {
            $char = $sql[$i];
            if (self::isDigit($char) || self::isWordStart($char) || $char === '.') {
                $i++;
            } elseif (($char === '+' || $char === '-') && ($sql[$i - 1] === 'e' || $sql[$i - 1] === 'E') && self::isDigit($sql[$i + 1] ?? '')) {
                $i++;
            } else {
                break;
            }
        }

        return $i;
    }

    /**
     * @param \Closure(string): bool $continues
     */
    private static function skipWhile(string $sql, int $start, \Closure $continues): int
    {
        $length = strlen($sql);
        $i = $start;
        while ($i < $length && $continues($sql[$i])) {
            $i++;
        }

        return $i;
    }

    /**
     * 括弧の中が ? と , だけで、? が LIST_MIN 個以上なら (? ×N) に縮める（in の並び・whereIntegerInRaw）。
     * values の後ろの括弧（insert の 1 行）は列と対になるので縮めない
     *
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private static function lists(array $tokens): array
    {
        $rows = self::tupleOpenings($tokens);
        $result = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i] !== '(' || isset($rows[$i])) {
                $result[] = $tokens[$i];
                continue;
            }

            $marks = 0;
            $expect = '?';
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ')'; $j++) {
                if ($tokens[$j] === ' ') {
                    continue;
                }
                if ($tokens[$j] !== $expect) {
                    break;
                }
                $marks += $expect === '?' ? 1 : 0;
                $expect = $expect === '?' ? ',' : '?';
            }

            if ($j < $count && $tokens[$j] === ')' && $expect === ',' && $marks >= self::LIST_MIN) {
                $result[] = "(? ×{$marks})";
                $i = $j;
            } else {
                $result[] = $tokens[$i];
            }
        }

        return $result;
    }

    /**
     * values の後ろで、同じ形の括弧が , で 2 つ以上続けば「values (…) ×N」に縮める（insert の複数行）
     *
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private static function repeatedTuples(array $tokens): array
    {
        $result = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $result[] = $tokens[$i];
            if ($tokens[$i] !== 'values' && $tokens[$i] !== 'value') {
                continue;
            }

            $groups = [];
            $last = null;
            foreach (self::tuples($tokens, $i) as [$open, $close]) {
                $groups[] = implode('', array_slice($tokens, $open, $close - $open + 1));
                $last = $close;
            }

            if ($last !== null && count($groups) >= 2 && count(array_unique($groups)) === 1) {
                $result[] = ' ' . $groups[0] . ' ×' . count($groups);
                $i = $last;
            }
        }

        return $result;
    }

    /**
     * values の後ろの括弧の始まりの位置
     *
     * @param list<string> $tokens
     *
     * @return array<int, true>
     */
    private static function tupleOpenings(array $tokens): array
    {
        $openings = [];
        foreach ($tokens as $i => $token) {
            if ($token === 'values' || $token === 'value') {
                foreach (self::tuples($tokens, $i) as [$open]) {
                    $openings[$open] = true;
                }
            }
        }

        return $openings;
    }

    /**
     * $keyword の後ろに , でつながる括弧の [始まり, 終わり] の並び
     *
     * @param list<string> $tokens
     *
     * @return list<array{int, int}>
     */
    private static function tuples(array $tokens, int $keyword): array
    {
        $tuples = [];
        $open = self::nextSolid($tokens, $keyword);

        while ($open !== null && $tokens[$open] === '(') {
            $close = self::closing($tokens, $open);
            if ($close === null) {
                break;
            }
            $tuples[] = [$open, $close];
            $comma = self::nextSolid($tokens, $close);
            $open = $comma !== null && $tokens[$comma] === ',' ? self::nextSolid($tokens, $comma) : null;
        }

        return $tuples;
    }

    /**
     * @param list<string> $tokens
     */
    private static function nextSolid(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i] !== ' ') {
                return $i;
            }
        }

        return null;
    }

    /**
     * 対になる閉じ括弧の位置。無ければ null
     *
     * @param list<string> $tokens
     */
    private static function closing(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $depth += match ($tokens[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private static function isDigit(string $char): bool
    {
        return $char !== '' && $char >= '0' && $char <= '9';
    }

    private static function isWordStart(string $char): bool
    {
        return $char !== '' && (($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z') || $char === '_');
    }

    /**
     * @return array<string, int>
     */
    private static function keywords(): array
    {
        return self::$keywords ??= array_flip(self::KEYWORDS);
    }
}
