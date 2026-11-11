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

    private const JAPANESE_KEY_WORDS = 'パスワード|暗証番号|トークン|確認番号|確認コード|認証コード|ワンタイム';

    private const KEY_WORDS = 'password|passwd|pwd|token|secret|api[_-]?key|private[_-]?key|access[_-]?key|credential|authorization|cookie|session|otp|one[_-]?time|verification[_-]?code|' . self::JAPANESE_KEY_WORDS;

    /**
     * キーの後ろの区切り。語の前に境界は求めず（remember_token・XSRF-TOKEN）、語の後ろの続き（password_confirmation）は許す。
     * print_r（[password] => x）・角括弧のキー（user[password]=x）・エスケープした JSON（\"password\":）のため、] と \ を許す
     */
    private const KEY = '((?:' . self::KEY_WORDS . ')[A-Za-z0-9_-]*\]?(?:\\\\)?[\'"]?\s*(?:=>|=|:|：)\s*)';

    /**
     * 全角を揃えると値の区切りになる文字。揃える前に私用の文字へ逃がし、値の中として扱う（hun，ter2 の ter2 を残さない）。伏せた後に戻す
     *
     * @var array<string, string>
     */
    private const KEEP_FULLWIDTH = ['，' => "\u{E000}", '；' => "\u{E001}", '＆' => "\u{E002}", '｝' => "\u{E003}"];

    private const QUOTED = '"(?:[^"\\\\]|\\\\.)*(?:"|\z)|\'(?:[^\'\\\\]|\\\\.)*(?:\'|\z)';

    /** 引用符の無い値。空白では止めず、行末か , ; & } ) まで（「password=abc def」の def も伏せる。伏せすぎる側を受け入れる） */
    private const BARE = '[^\s,;&})\'"][^\n,;&})]*';

    /** 前後のコード・文言・notice に当てる鍵の形 */
    private const KEY_SHAPES = [
        ['/-----BEGIN [A-Z ]*PRIVATE KEY-----.*/u', self::MARK],
        ['/sk_live_[A-Za-z0-9]+|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_\-]{35}|xox[abp]-[A-Za-z0-9\-]+|gh[pousr]_[A-Za-z0-9]{20,}/u', self::MARK],
    ];

    /** 電話番号の区切り（NFKC で揃えた後に残る全角の - と長音、空白・.・括弧） */
    private const PHONE_SEPARATOR = '[\-‐−ー－–—\/\s.()]';

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
        $text = $this->copiedQuery(self::normalize(strtr($text, self::KEEP_FULLWIDTH)));
        foreach ([...$this->shapes(true), ...self::personal(), ...self::KEY_SHAPES, self::authorization(), self::japaneseKey(), $this->keyValue(false)] as [$pattern, $replacement]) {
            $text = $text === null ? null : Text::replace($pattern, $replacement, $text);
        }
        $text = $text === null ? null : $this->longAlnum($text);

        foreach ($this->extraPatterns as $pattern) {
            $text = $text === null ? null : Text::replace($pattern, self::MARK, $text);
        }

        return $text === null ? null : strtr($text, array_flip(self::KEEP_FULLWIDTH));
    }

    /**
     * 前後のコード。形のはっきりした物と、値が文字列のキーと値だけ。長い英数字は当てない（識別子が多いため）
     */
    public function snippet(string $text): ?string
    {
        $rules = [
            ...$this->shapes(false),
            ...self::KEY_SHAPES,
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
            ['/[A-Za-z0-9._%+\-]+(?:@|%40)[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/u', self::MARK],
        ]));
    }

    /**
     * 全角の数字・記号・＠ を半角に揃える（伏せる規則を当てる前に）。intl が無ければ mb_convert_kana で英数字・記号・空白・半角カナを揃える
     */
    private static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return mb_convert_kana($text, 'KVas', 'UTF-8');
    }

    /**
     * Authorization の値は方式（Bearer・Token・Digest など）ごと、行末か , まで伏せる
     *
     * @return array{string, string}
     */
    private static function authorization(): array
    {
        return ['/(authorization[\'"]?\s*(?:=>|=|:|：)\s*)[^,\n]*/iu', '$1' . self::MARK];
    }

    /**
     * 日本語のキーに限り、「パスワードは xxx」「確認コード「xxx」」の形にも当てる。値の終わりは空白か 」 か 。
     *
     * @return array{string, string}
     */
    private static function japaneseKey(): array
    {
        return ['/((?:' . self::JAPANESE_KEY_WORDS . ')(?:パスワード)?\s*(?:は|「)\s*)[^\s」。]+/u', '$1' . self::MARK];
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
            // 0[1-9] に絞り、0 埋めの社員コード（0012345678 など）は残す。+81 は頭の 0 の代わり
            ['/(?<![A-Za-z0-9_\-])(?:\(?0[1-9]|\+81' . self::PHONE_SEPARATOR . '*(?:\(0\))?' . self::PHONE_SEPARATOR . '*\(?[1-9])(?:' . self::PHONE_SEPARATOR . '*[0-9]){8,9}(?![A-Za-z0-9_\-])/u', self::MARK],
            ['/(?<![A-Za-z0-9_\-])[0-9]{4}' . self::PHONE_SEPARATOR . '?[0-9]{4}' . self::PHONE_SEPARATOR . '?[0-9]{4}(?![A-Za-z0-9_\-])/u', self::MARK],
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
     *
     * 最初の「(Connection: 」から後ろを伏せる。値に「(Connection: 」があって外れても、伏せすぎる側に倒れる。
     * 前（head）が接続の失敗なら、ドライバーの文言（利用者・IP・ホストを含む）を KnownMessage と同じ固定の言葉にする
     */
    private function copiedQuery(string $text): ?string
    {
        $start = strpos($text, '(Connection: ');

        if ($start === false) {
            $failure = self::connectionFailure($text);
            if ($failure !== null) {
                return rtrim($failure);
            }
            if (preg_match('/SQLSTATE\[[0-9A-Z]{5}\](?: \[\d{1,5}\])?/', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                return substr($text, 0, $match[0][1] + strlen($match[0][0])) . ' ' . self::MARK;
            }

            return $this->driverValues($text);
        }

        $head = substr($text, 0, $start);
        $tail = substr($text, $start);
        $head = $this->copiedHead($head);
        if ($head === null) {
            return null;
        }

        // SQL の手前で切れていたら、接続の詳細（Host など）が途中まで残っているので、そこから全部伏せる
        if (! str_contains($tail, ', SQL: ')) {
            return $head . self::MARK;
        }

        $name = substr($tail, strlen('(Connection: '), max(0, (int) strpos($tail, ',') - strlen('(Connection: ')));
        // 後ろの「 (View: ….php)」の繰り返しはビューの場所なので残す（パスの形のときだけ）
        $views = '';
        while (preg_match('/ \(View: [^()]*\.php\)\z/u', $tail, $match) === 1) {
            $views = $match[0] . $views;
            $tail = substr($tail, 0, -strlen($match[0]));
        }

        $connection = preg_match(Details::CONNECTION, $name) === 1 ? $name : self::MARK;

        return "{$head}(Connection: {$connection}, SQL: （ログ）){$views}";
    }

    /**
     * 写された文言の「(Connection: 」より前。接続の失敗なら固定の言葉、ドライバーの文言が許可リストの形なら値だけ伏せ、
     * どちらでもなければ SQLSTATE（[番号] があればそこまで）より後ろを伏せる（1049 のデータベース名・pgsql の Key (…)=(…) など）
     */
    private function copiedHead(string $head): ?string
    {
        $failure = self::connectionFailure($head);
        if ($failure !== null) {
            return $failure;
        }

        if (preg_match('/\A(.*?)(SQLSTATE\[[0-9A-Z]{5}\])(: [A-Za-z ,\/\-]{1,80}: \d{1,5} )?(.*)\z/s', $head, $match) !== 1) {
            return $this->driverValues($head);
        }

        [, $before, $state, $described, $rest] = $match;
        $allowed = $described === '' ? null : DriverMessage::redact(rtrim($rest));
        if ($allowed !== null) {
            return "{$before}{$state}{$described}{$allowed} ";
        }

        $number = preg_match('/\A \[\d{1,5}\]/', $rest, $bracket) === 1 ? $bracket[0] : '';

        return "{$before}{$state}{$number} " . self::MARK . ' ';
    }

    /**
     * 接続の失敗の文言（SQLSTATE[HY000] [1045] Access denied for user …）なら、その所から後ろを固定の言葉にした物。違えば null
     */
    private static function connectionFailure(string $text): ?string
    {
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\] \[(\d{1,5})\]/', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $state = $match[1][0];
        $driver = (int) $match[2][0];
        if (! Details::isConnectionFailure($state, $driver)) {
            return null;
        }

        $rest = substr($text, $match[0][1]);

        return substr($text, 0, $match[0][1]) . "SQLSTATE[{$state}] [{$driver}] " . KnownMessage::connectionWords($driver, $rest) . '（詳細はログ） ';
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
