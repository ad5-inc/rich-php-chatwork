<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PlainTestCase;

/**
 * 伏せる規則。規則ごとに、伏せる例と伏せない例を並べる
 */
final class RedactorTest extends PlainTestCase
{
    private const HEX32 = '0123456789abcdef0123456789abcdef';

    private const HEX40 = '0123456789abcdef0123456789abcdef01234567';

    /** 英字と数字が混ざった作り物のトークン（数字は 3 個以上） */
    private const TOKEN = 'ProbeValueAbcDef123GhiJklMnoPqrStu';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redacted(): iterable
    {
        yield 'JSON の password' => ['{"password":"p@ss word"}', '{"password":（伏せた）}'];
        yield '引用符を逃がした値' => ['{"token":"a\"b"}', '{"token":（伏せた）}'];
        yield 'PHP の配列' => ["'password' => 'x'", "'password' => （伏せた）"];
        yield 'PHP の代入' => ["\$secret = 'x'", '$secret = （伏せた）'];
        yield '閉じる引用符が無い' => ['{"access_token":"abc def (truncated...)', '{"access_token":（伏せた）'];
        yield '続きのあるキー' => ['{"password":"x","password_confirmation":"y"}', '{"password":（伏せた）,"password_confirmation":（伏せた）}'];
        yield '大文字のキー' => ['XSRF-TOKEN=abc123; path=/', 'XSRF-TOKEN=（伏せた）; path=/'];
        yield '引用符の無い値' => ['remember_token: probe-value, next', 'remember_token: （伏せた）, next'];
        // 秘密の形の値は、読み取りの道具に本物と間違われないよう、つないで作る
        yield 'JWT' => ['jwt ' . 'eyJ' . 'probeHeader.' . 'eyJ' . 'probeBody.probeSign here', 'jwt （伏せた） here'];
        yield 'bcrypt' => ['hash $2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234 end', 'hash （伏せた） end'];
        yield 'argon2id' => ['hash $argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA end', 'hash （伏せた） end'];
        yield 'Bearer' => ['header Bearer abc.def-ghi end', 'header Bearer （伏せた） end'];
        yield 'メールアドレス' => ['to probe.user@example.test failed', 'to （伏せた） failed'];
        yield 'URL の利用者情報と ? 以降' => ['GET https://user:pass@host.example/x?token=y failed', 'GET https://（伏せた）@host.example/x?（伏せた） failed'];
        yield '16 進 32 文字' => ['key ' . self::HEX32 . ' end', 'key （伏せた） end'];
        yield 'base64 のトークン' => ['key ' . self::TOKEN . '== end', 'key （伏せた） end'];
        yield 'session と 16 進 40 文字' => ['file session_' . self::HEX40 . ' end', 'file （伏せた） end'];
        yield '文の終わりが . のトークン' => ['key is ' . self::TOKEN . '.', 'key is （伏せた）.'];
        yield '文の終わりが . の 16 進' => ['key is ' . self::HEX32 . '. next', 'key is （伏せた）. next'];
        yield '電話番号（区切りあり）' => ['電話 03-1234-5678 まで', '電話 （伏せた） まで'];
        yield '電話番号（区切りなし）' => ['tel:09012345678', 'tel:（伏せた）'];
        yield 'マイナンバー（区切りあり）' => ['番号 1234 5678 9012 を', '番号 （伏せた） を'];
        yield 'マイナンバー（区切りなし）' => ['番号 123456789012 を', '番号 （伏せた） を'];
        yield '口座番号' => ['口座番号: 1234567', '口座番号: （伏せた）'];
        yield 'account の後ろの 7 桁' => ['bank account no. 7654321 failed', 'bank account no. （伏せた） failed'];
        yield '全角の電話番号（NFKC で揃えてから当てる）' => ['電話 ０３－１２３４－５６７８ まで', '電話 （伏せた） まで'];
        yield '+81 の電話番号' => ['tel +81 90-1234-5678 end', 'tel （伏せた） end'];
        yield '括弧の電話番号' => ['tel (03)1234-5678 end', 'tel （伏せた） end'];
        yield '. で区切った電話番号' => ['tel 03.1234.5678 end', 'tel （伏せた） end'];
        yield '長音で区切った電話番号' => ['tel 090ー1234ー5678 end', 'tel （伏せた） end'];
        yield '%40 のメールアドレス' => ['to probe.user%40example.test failed', 'to （伏せた） failed'];
        yield '全角の＠のメールアドレス' => ['to probe.user＠example.test failed', 'to （伏せた） failed'];
        yield 'Authorization の方式ごと' => ['Authorization: Token probe-value next, rest', 'Authorization: （伏せた）, rest'];
        yield 'Authorization の Digest' => ['Authorization: Digest username="probe", response="' . self::HEX32 . '"', 'Authorization: （伏せた）, response="（伏せた）"'];
        yield '全角のコロン' => ['パスワード：probe value', 'パスワード:（伏せた）'];
        yield '日本語のキー' => ['暗証番号: 9876, トークン=probe-value', '暗証番号: （伏せた）, トークン=（伏せた）'];
        yield '引用符の無い値は空白で止めない' => ['password=probe value here, next', 'password=（伏せた）, next'];
        yield '文言の中の鍵の形' => ['key AK' . 'IA' . str_repeat('PROBE', 3) . '1 rejected', 'key （伏せた） rejected'];
    }

    #[Test]
    #[DataProvider('redacted')]
    public function 伏せる(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->redactor()->message($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kept(): iterable
    {
        yield 'CSRF の文言' => ['CSRF token mismatch.'];
        yield '検証の文言' => ['The token field is required.'];
        yield '長い snake_case' => ['employee_evaluation_item_score_2024_version_history_id'];
        yield '長いクラス名' => ['App\UseCase\Actions\Employee\Evaluation\VeryLongActionNameForProbe2024Handler'];
        yield 'UUID' => ['id 123e4567-e89b-12d3-a456-426614174000 end'];
        yield 'パスの区切り' => ['storage/app/' . self::HEX32 . '.pdf'];
        yield 'SQLSTATE・HTTP の状態・行番号' => ['state 22001 HTTP 503 at line 1234 at row 1'];
        yield '7 桁でない口座の後ろ' => ['口座の数 12 件'];
        yield '0 埋めの社員コード' => ['社員 0012345678 の更新'];
        yield 'URL のパス' => ['https://host.example/x'];
        yield '列とキーの名前' => ["for column 'name' and for key 'employees.code'"];
    }

    #[Test]
    #[DataProvider('kept')]
    public function 伏せない(string $text): void
    {
        $this->assertSame($text, $this->redactor()->message($text));
    }

    #[Test]
    public function 写されたDBの文言は_entryの値だけを伏せる(): void
    {
        $this->assertSame(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '（伏せた）' for key 'employees.code' (Connection: mysql, SQL: （ログ）)",
            $this->redactor()->message("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '試験太郎-2026' for key 'employees.code' (Connection: mysql, SQL: insert into employees (code) values ('試験太郎-2026'))"),
        );
        $this->assertSame(
            "Duplicate entry '（伏せた）' for key 'employees.code'",
            $this->redactor()->message("Duplicate entry 'O'Probe-2026' for key 'employees.code'"),
        );
    }

    #[Test]
    public function 写されたDBの文言は_nearの後ろと値を伏せ_列の名前は残す(): void
    {
        $this->assertSame(
            "You have an error in your SQL syntax; check the manual for the right syntax to use near '（伏せた）' at line 1",
            $this->redactor()->message("You have an error in your SQL syntax; check the manual for the right syntax to use near '= 'secret123'' at line 1"),
        );
        $this->assertSame(
            "Incorrect integer value: '（伏せた）' for column 'age' at row 1",
            $this->redactor()->message("Incorrect integer value: 'probe-value' for column 'age' at row 1"),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function copiedQueryMessages(): iterable
    {
        $head = "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nme' in 'where clause'";
        $details = 'Connection: mysql, Host: db.internal, Port: 3306, Database: probe_db';

        yield '値に ( だけ' => ["{$head} ({$details}, SQL: select * from t where a = 'x(' and b = 1)", "{$head} (Connection: mysql, SQL: （ログ）)"];
        yield '値に ) だけ' => ["{$head} ({$details}, SQL: select * from t where a = 'x)' and b = 1)", "{$head} (Connection: mysql, SQL: （ログ）)"];
        yield 'View の 2 段' => [
            "{$head} ({$details}, SQL: select * from t where a = 'x') (View: resources/views/a.blade.php) (View: resources/views/a.blade.php)",
            "{$head} (Connection: mysql, SQL: （ログ）) (View: resources/views/a.blade.php) (View: resources/views/a.blade.php)",
        ];
        yield '末尾が切れて SQL の途中まで' => ["{$head} ({$details}, SQL: select * from t where a = 'x", "{$head} (Connection: mysql, SQL: （ログ）)"];
        yield '末尾が切れて Connection も無い' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'zz' for ke", 'SQLSTATE[23000] （伏せた）'];
        yield 'Connection の途中で切れた' => ["{$head} (Connection: mysql, Host: db.internal, Port: 33", "{$head} （伏せた）"];
        yield '写された 1045' => [
            "SQLSTATE[HY000] [1045] Access denied for user 'probe-user'@'10.0.0.1' (using password: YES) ({$details}, SQL: select 1)",
            'SQLSTATE[HY000] [1045] 認証に失敗（詳細はログ） (Connection: mysql, SQL: （ログ）)',
        ];
        yield '写された 2002' => [
            "SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db.internal failed: Name or service not known ({$details}, SQL: select 1)",
            'SQLSTATE[HY000] [2002] 名前が引けない（詳細はログ） (Connection: mysql, SQL: （ログ）)',
        ];
        yield '値に Connection と SQL を含む' => [
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'v (Connection: a, SQL: x' for key 'k' ({$details}, SQL: insert into t values ('v (Connection: a, SQL: x'))",
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'v (Connection: a, SQL: （ログ）)",
        ];
    }

    #[Test]
    #[DataProvider('copiedQueryMessages')]
    public function 包む例外の一覧に無い例外が写したDBの文言も伏せ_SQLSTATEは残す(string $message, string $expected): void
    {
        $this->assertSame('Wrapped: ' . $expected, $this->redactor()->message('Wrapped: ' . $message), 'RuntimeException などが写した形');
    }

    #[Test]
    public function 使う側の規則を足せ_失敗したらnullを返す(): void
    {
        $this->assertSame('社員 （伏せた） の更新', new Redactor(['/P-\d{4}/'])->message('社員 P-1234 の更新'));
        $this->assertNull(new Redactor(['/(/'])->message('社員 P-1234 の更新'));
    }

    #[Test]
    public function 前後のコードは形のはっきりした物と_文字列の値だけを伏せる(): void
    {
        $redactor = $this->redactor();

        $this->assertSame('$secret = （伏せた）;', $redactor->snippet("\$secret = 'sk_test_probe';"));
        $this->assertSame("'password' => （伏せた）,", $redactor->snippet("'password' => 'probe-pass',"));
        // 秘密の形の値は、読み取りの道具に本物と間違われないよう、つないで作る
        $this->assertSame('$key = （伏せた）;', $redactor->snippet('$key = sk_' . 'live_ProbeValue123;'));
        $this->assertSame('$aws = （伏せた）;', $redactor->snippet('$aws = AK' . 'IA' . str_repeat('PROBE', 3) . '1;'));
        $this->assertSame('$g = （伏せた）;', $redactor->snippet('$g = AI' . 'za' . str_repeat('Probe', 6) . '12345;'));
        $this->assertSame('$s = （伏せた）;', $redactor->snippet('$s = xo' . 'xb-1234-probe;'));
        $this->assertSame('$h = （伏せた）;', $redactor->snippet('$h = gh' . 'p_' . str_repeat('Probe', 4) . '1234;'));
        $this->assertSame('（伏せた）', $redactor->snippet('-----BEGIN RSA ' . 'PRIVATE KEY-----'));
        $this->assertSame('$password = $request->password;', $redactor->snippet('$password = $request->password;'));
        $this->assertSame("\$tel = '03-1234-5678';", $redactor->snippet("\$tel = '03-1234-5678';"), '電話番号などは前後のコードに当てない');
        $this->assertSame("\$url = '/api/x?page=1';", $redactor->snippet("\$url = '/api/x?page=1';"), 'URL の問い合わせは当てない');
        $this->assertSame('$id = \'' . self::HEX32 . '\';', $redactor->snippet('$id = \'' . self::HEX32 . '\';'), '長い英数字は当てない');
    }

    #[Test]
    public function リクエストはURLの問い合わせだけを伏せる(): void
    {
        $this->assertSame('GET /api/x?（伏せた）', $this->redactor()->request('GET /api/x?token=y&page=2'));
        $this->assertSame('GET /api/users/probe@example.test', $this->redactor()->request('GET /api/users/probe@example.test'));
    }

    private function redactor(): Redactor
    {
        return new Redactor([]);
    }
}
