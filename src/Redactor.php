<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * 値を伏せる規則。どれも失敗したら null を返し、呼ぶ側は項目ごと省く（元の値には戻さない）
 *
 * 規則の順番には意味がある。URL の利用者情報と問い合わせ（5・6）をキーと値（8）より先に当てないと、
 * `?token=x` の後ろが URL の外まで伸びる。長い英数字（9）は最後に当て、ほかの規則の印を壊さない
 */
final class Redactor
{
    public const MARK = '（伏せた）';

    private const KEY_WORDS = 'password|passwd|pwd|token|secret|api[_-]?key|private[_-]?key|access[_-]?key|credential|authorization|cookie|session';

    /** キーの後ろの区切り。語の前に境界は求めず（remember_token・XSRF-TOKEN）、語の後ろの続き（password_confirmation）は許す */
    private const KEY = '((?:' . self::KEY_WORDS . ')[A-Za-z0-9_-]*[\'"]?\s*(?:=>|=|:)\s*)';

    private const QUOTED = '"(?:[^"\\\\]|\\\\.)*(?:"|\z)|\'(?:[^\'\\\\]|\\\\.)*(?:\'|\z)';

    private const BARE = '[^\s,;&})\'"][^\s,;&})]*';

    /** URL か、空白・引用符などの直後の / で始まるパスの、? 以降 */
    private const QUERY = '/((?:\b[a-z][a-z0-9+.\-]*:\/\/|(?<=^|[\s"\'(=])\/)[^\s?#"\'<>]*)\?[^\s#"\'<>]+/iu';

    /** 英数字の続き。前がこれらの文字なら長い英数字として扱わない（クラス名・ファイル名・snake_case の一部） */
    private const WORD = '[A-Za-z0-9_\\\\.\-]';

    /** 長い英数字の後ろの境界。. は後ろに英数字が続くときだけ続きとみなす（file.pdf は外し、文の終わりのトークン. は伏せる） */
    private const AFTER = '(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])';



    /**
     * @param list<string> $extraPatterns 使う側が足した規則。既定の規則の後に当てる
     */
    public function __construct(private readonly array $extraPatterns = [])
    {
    }

    /**
     * 形の分からない例外の文言・notice の行と枠
     */
    public function message(string $text): ?string
    {
        $text = $this->copiedQuery($text);
        foreach ([...$this->shapes(true), ...self::personal(), $this->keyValue(false)] as [$pattern, $replacement]) {
            $text = $text === null ? null : Text::replace($pattern, $replacement, $text);
        }
        $text = $text === null ? null : $this->longAlnum($text);

        foreach ($this->extraPatterns as $pattern) {
            $text = $text === null ? null : Text::replace($pattern, self::MARK, $text);
        }

        return $text;
    }

    /**
     * 前後のコード。形のはっきりした物と、値が文字列のキーと値だけ。長い英数字は当てない（識別子が多いため）
     */
    public function snippet(string $text): ?string
    {
        $rules = [
            ...$this->shapes(false),
            ['/-----BEGIN [A-Z ]*PRIVATE KEY-----.*/u', self::MARK],
            ['/sk_live_[A-Za-z0-9]+|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_\-]{35}|xox[abp]-[A-Za-z0-9\-]+|gh[pousr]_[A-Za-z0-9]{20,}/u', self::MARK],
            $this->keyValue(true),
        ];

        foreach ($rules as [$pattern, $replacement]) {
            $text = $text === null ? null : Text::replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * リクエストの行。URL の問い合わせだけ
     */
    public function request(string $text): ?string
    {
        return Text::replace(self::QUERY, '$1?' . self::MARK, $text);
    }

    /**
     * 規則 2〜7（JWT・ハッシュ・Bearer・URL の利用者情報・問い合わせ・メールアドレス）。前後のコードには問い合わせ（6）を当てない
     *
     * @return list<array{string, string}>
     */
    private function shapes(bool $query): array
    {
        return array_values(array_filter([
            ['/eyJ[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]*/u', self::MARK],
            ['/\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]+|\$argon2(?:id|i|d)\$[A-Za-z0-9=,$+\/.\-]+/u', self::MARK],
            ['/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/iu', '$1 ' . self::MARK],
            ['/\/\/[^\/\s@:]*:[^\/\s@]*@/u', '//' . self::MARK . '@'],
            $query ? [self::QUERY, '$1?' . self::MARK] : null,
            ['/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/u', self::MARK],
        ]));
    }

    /**
     * 規則 7b（電話番号・マイナンバー・口座番号）。メッセージと notice だけに当てる。
     * 前後が英数字・_・- の所は外す（16 進のトークンの頭・UUID の最後の 12 桁・ファイル名の中の数字を伏せない）
     *
     * @return list<array{string, string}>
     */
    private static function personal(): array
    {
        return [
            ['/(?<![A-Za-z0-9_\-])0(?:[- ]?[0-9]){9,10}(?![A-Za-z0-9_\-])/u', self::MARK],
            ['/(?<![A-Za-z0-9_\-])[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}(?![A-Za-z0-9_\-])/u', self::MARK],
            ['/((?:口座|account).{0,20}?)(?<![0-9])[0-9]{7}(?![0-9])/iu', '$1' . self::MARK],
        ];
    }

    /**
     * 規則 8（キーと値）。$quotedOnly なら値が引用符で始まるときだけ（$request->password のような変数は残す）
     *
     * @return array{string, string}
     */
    private function keyValue(bool $quotedOnly): array
    {
        $value = $quotedOnly ? self::QUOTED : self::QUOTED . '|' . self::BARE;

        return ['/' . self::KEY . '(?:' . $value . ')/iu', '$1' . self::MARK];
    }

    /**
     * 規則 1（写された DB の文言）。QueryException の文言を別の例外が写したときの取りこぼし用
     */
    private function copiedQuery(string $text): ?string
    {
        $start = strrpos($text, '(Connection: ');

        if ($start !== false && str_contains(substr($text, $start), ', SQL: ')) {
            $head = substr($text, 0, $start);
            $tail = substr($text, $start);
            $name = substr($tail, strlen('(Connection: '), max(0, (int) strpos($tail, ',') - strlen('(Connection: ')));

            // 後ろの「 (View: …)」の繰り返しはビューの場所なので残す
            $views = '';
            while (preg_match('/ \(View: [^()]*\)\z/u', $tail, $match) === 1) {
                $views = $match[0] . $views;
                $tail = substr($tail, 0, -strlen($match[0]));
            }

            $connection = preg_match('/\A[A-Za-z0-9_.\-]{1,64}\z/', $name) === 1 ? $name : self::MARK;
            $head = $this->driverValues($head);

            return $head === null ? null : "{$head}(Connection: {$connection}, SQL: （ログ）){$views}";
        }

        if ($start === false && preg_match('/SQLSTATE\[[0-9A-Z]{5}\]/', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            return substr($text, 0, $match[0][1] + strlen($match[0][0])) . ' ' . self::MARK;
        }

        return $this->driverValues($text);
    }

    /**
     * ドライバーの文言の許可リストの「伏せる所」を、文言の中のその場で伏せる
     */
    private function driverValues(string $text): ?string
    {
        foreach (DriverMessage::IN_PLACE as $pattern) {
            $text = $text === null ? null : Text::replace($pattern, '$1' . self::MARK . '$3', $text);
        }

        return $text;
    }

    /**
     * 規則 9（長い英数字）。16 進は 32 文字以上、base64 は英字と数字を両方含み数字が 3 個以上の 32 文字以上
     */
    private function longAlnum(string $text): ?string
    {
        $text = Text::replace('/(?<!' . self::WORD . ')[0-9a-fA-F]{32,}' . self::AFTER . '/u', self::MARK, $text);

        return $text === null ? null : Text::replace(
            '/(?<!' . self::WORD . ')[A-Za-z0-9+_\-]{32,}={0,2}' . self::AFTER . '/u',
            static function (array $match): string {
                $token = rtrim($match[0], '=');
                $digits = preg_match_all('/\d/', $token);
                $uuid = preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $token) === 1;
                // どの区切りも 16 文字未満の snake_case は識別子。session_{40 文字の 16 進} は外さない
                $snake = preg_match('/\A[a-z0-9]{1,15}(?:_[a-z0-9]{1,15})+\z/', $token) === 1;

                return preg_match('/[A-Za-z]/', $token) === 1 && $digits >= 3 && ! $uuid && ! $snake ? self::MARK : $match[0];
            },
            $text,
        );
    }
}
