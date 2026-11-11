<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\JobEntrance;
use Ad5\RichPhpChatwork\PhpChatworkTransport;
use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\Snippet;
use Ad5\RichPhpChatwork\Trace;
use Ad5\RichPhpChatwork\Transport;
use Ad5\RichPhpChatwork\Throttle;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * 送る条件・本文の並び・伏せる順番・記法の逃がし・長さ・間引き・送れないときの振る舞い
 */
final class ReporterTest extends TestCase
{
    private const SECRET = 'taro@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useEnvironment('production');
        config(['app.name' => 'Probe', 'rich-chatwork.access_token' => 'probe-token', 'rich-chatwork.room_id' => '12345']);
        Context::flush();
        Context::add(['request_id' => 'req-1', 'request' => 'GET /api/reports/1']);
        Reporter::resolveUserUsing(null);
    }

    #[Test]
    public function 送り先があれば送り_同じ例外は間を置くまで送らない(): void
    {
        $e = $this->failWith(self::SECRET);

        $this->reporter()->report($e);
        $this->reporter()->report($e);

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('probe-token', $this->transport->sent[0]['token']);
        $this->assertSame('12345', $this->transport->sent[0]['room']);

        $this->travel(61)->seconds();
        $this->reporter()->report($e);

        $this->assertCount(2, $this->transport->sent);
    }

    #[Test]
    public function 本文に件名と場所と番号を書き_文言と引数の値は書かない(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        // 本番の php.ini しだいで引数が trace に入る。入る設定にしても本文に出ないことを見る
        ini_set('zend.exception_ignore_args', '0');
        try {
            $e = $this->failWith(self::SECRET);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
        $this->assertSame([self::SECRET], $e->getTrace()[0]['args'] ?? null);

        $body = $this->reporter()->body($e);

        $this->assertStringStartsWith('[info][title]Probe（production）RuntimeException[/title]アプリの行: ' . $this->relative(__FILE__) . ':', $body);
        $this->assertStringContainsString("Request ID: req-1（中身はログをこの番号で引く）\n", $body);
        $this->assertStringContainsString("リクエスト: GET /api/reports/1\n", $body);
        $this->assertStringContainsString(self::class . '->failWith()', $body);
        $this->assertStringContainsString('[hr][code]', $body);
        $this->assertStringNotContainsString('taro', $body);
        $this->assertStringNotContainsString('[code]' . $this->relative(__FILE__), $body, '前後のコードに ファイル:行 の見出しを入れない');
        $this->assertStringNotContainsString(dirname(__DIR__), $body, '手元のパスを送らない');
        $this->assertDoesNotMatchRegularExpression('/^種類: /m', $body, '種類の行は項目が無ければ出さない');
    }

    #[Test]
    public function 設定で選べば文言を伏せて件名の直下に添え_長い文言は表示幅で切る(): void
    {
        config(['rich-chatwork.send_message' => true]);
        $short = $this->reporter()->body($this->failWithMessage('鍵 password=probe が無い'));

        config(['rich-chatwork.message_max_length' => 20]);
        $long = $this->reporter()->body($this->failWithMessage(str_repeat('あ', 30)));

        $this->assertStringContainsString('[/title][code]鍵 password=（伏せた）[/code]アプリの行: ', $short);
        $this->assertStringContainsString('[code]' . str_repeat('あ', 9) . '…[/code]', $long, '全角は幅 2');
    }

    #[Test]
    public function 文字を直してから伏せ_逃がしてから切る(): void
    {
        config(['rich-chatwork.send_message' => true]);

        $body = $this->reporter()->body($this->failWithMessage("壊れた\xC3\x28文字\x07と\ttab"));

        $this->assertStringContainsString('[code]壊れた?(文字と    tab[/code]', $body);
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function dumps(): iterable
    {
        $object = new Fixtures\ProbeSecrets();

        yield 'print_r のオブジェクト' => ['probe ' . print_r($object, true), ['probe-pw-6', 'probe-tk-6']];
        yield 'serialize のオブジェクト（protected・private）' => ['probe ' . serialize($object), ['probe-pw-6', 'probe-tk-6']];
        yield 'var_export のオブジェクト' => ['probe ' . var_export($object, true), ['probe-pw-6', 'probe-tk-6']];
        yield 'serialize の長さのずれ' => ['probe s:8:"password";s:1:"probe-pw-7";', ['probe-pw-7']];
        yield '閉じ括弧の数え方' => ['probe {"token":"]}probe-pw-8"}', ['probe-pw-8']];
        yield '入れ子の JSON' => ['payload {"data":{"secret_value":"probe-pw-9"}}', ['probe-pw-9']];
        yield 'JSON の配列' => ['payload ["probe-pw-10"]', ['probe-pw-10']];
        yield 'エスケープした JSON' => ['payload "{\"pin\":\"probe-pw-11\"}"', ['probe-pw-11']];
        yield '=> の書き出し' => ["values 'pin' => 'probe-pw-12'", ['probe-pw-12']];
        yield 'URL の角括弧' => ['query a%5Bpin%5D=probe-pw-13', ['probe-pw-13']];
        yield 'serialize の配列' => ['probe a:1:{i:0;s:11:"probe-pw-15";}', ['probe-pw-15']];
        yield '全角で書いた JSON' => ['payload ｛＂pin＂:＂probe-pw-16＂｝', ['probe-pw-16']];
        yield 'URL エンコードした JSON' => ['body=%7B%22pin%22%3A%22probe-pw-18%22%7D', ['probe-pw-18']];
        yield '1 行目が Array' => ["Array\n(\n    [pin] => probe-pw-19\n)", ['probe-pw-19']];
        yield '1 行目が {' => ["{\n    \"pin\": \"probe-pw-20\"\n}", ['probe-pw-20']];
        yield '1 行目が array (' => ["array (\n  'pin' => 'probe-pw-21',\n)", ['probe-pw-21']];
        yield '1 行目の終わりが {' => ["payload {\n  \"pin\": \"probe-pw-22\"\n}", ['probe-pw-22']];
    }

    /**
     * @param list<string> $secrets
     */
    #[Test]
    #[DataProvider('dumps')]
    public function 値の書き出しの形を含む文言は_伏せずに文言ごと省く(string $message, array $secrets): void
    {
        config(['rich-chatwork.send_message' => true]);

        $body = $this->reporter()->body($this->failWithMessage($message));

        $this->assertStringContainsString('[/title][code]（値の書き出しを含むので省いた。ログを見る）[/code]アプリの行: ', $body);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function processCopies(): iterable
    {
        yield 'プロセスの文言を写した物' => ["The command \"mysqldump -pS3cretPw hito\" failed.\n\nExit Code: 2", ['S3cretPw']];
        yield 'プロセスの時間切れを写した物（1 行）' => ['The process "mysqldump -uroot -pS3cretPw hito" exceeded the timeout of 60 seconds.', ['S3cretPw']];
        yield '前置き付きのプロセスの失敗の写し' => ["Backup failed: The command \"mysqldump -pS3cret db\" failed.\n\nExit Code: 2", ['S3cret']];
        yield '前置き付きの時間切れの写し' => ['Backup failed: The process "mysqldump -pS3cret db" exceeded the timeout of 60 seconds.', ['S3cret']];
        yield '先頭に空白がある写し' => ['  The command "mysqldump -pS3cret db" failed.', ['S3cret']];
        yield '小文字の写し' => ['Backup: the command "mysqldump -pS3cret db" failed.', ['S3cret']];
        yield '引用符を含むコマンドラインの写し' => ['The command "sh -c "mysqldump -pS3cret db"" failed.', ['S3cret']];
    }

    /**
     * @param list<string> $secrets
     */
    #[Test]
    #[DataProvider('processCopies')]
    public function 写したプロセスの文言は_コマンドラインを含むので省く(string $message, array $secrets): void
    {
        config(['rich-chatwork.send_message' => true]);

        $body = $this->reporter()->body($this->failWithMessage($message));

        $this->assertStringContainsString('[/title][code]（コマンドラインを含むので省いた。ログを見る）[/code]アプリの行: ', $body);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sentences(): iterable
    {
        yield 'CSRF' => ['CSRF token mismatch.'];
        yield '配列のキー' => ['Undefined array key "name"'];
        yield '未定義のメソッド' => ['Call to undefined method App\X::y()'];
        yield '短い文' => ['鍵のファイルが無い'];
        yield '無いコマンド' => ['The command "report:dailyy" does not exist.'];
        yield 'コマンドの戻り値' => ['The command "x" must return an integer value'];
        yield '関数の名前' => ['in_array(): Argument #2 ($haystack) must be of type array, null given'];
    }

    #[Test]
    #[DataProvider('sentences')]
    public function 普通の1行の文は省かない(string $message): void
    {
        config(['rich-chatwork.send_message' => true]);

        $body = $this->reporter()->body($this->failWithMessage($message));

        $this->assertStringContainsString('[/title][code]' . $message . '[/code]', $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function multiLine(): iterable
    {
        yield '2 行目の値' => ["first line\npin probe-pw-14", 'first line'];
        yield '末尾の改行' => ["Some message\n", 'Some message'];
        yield 'Carbon の書式の失敗' => ["Unexpected data found.\nTrailing data", 'Unexpected data found.'];
        yield 'Symfony Console の名前の誤り' => ["Command \"report:dail\" is not defined.\n\nDid you mean this?\n    report:daily", 'Command "report:dail" is not defined.'];
        yield 'SMTP の 2 行の応答' => ["Expected response code \"250\" but got code \"550\", with message \"550-5.1.1 The account does not exist.\r\n550 5.1.1 Please check probe-user@example.test\"", 'Expected response code "250" but got code "550", with message "550-5.1.1 The account does not exist.'];
    }

    #[Test]
    #[DataProvider('multiLine')]
    public function 目印が改行だけなら_1行目を伏せて出し_2行目から先を省く(string $message, string $first): void
    {
        config(['rich-chatwork.send_message' => true]);

        $body = $this->reporter()->body($this->failWithMessage($message));

        $this->assertStringContainsString('[/title][code]' . $first . (rtrim($message) === $first ? '' : '（2 行目から先は省いた）') . '[/code]', $body);
        $this->assertStringNotContainsString('probe-user', $body);
        $this->assertStringNotContainsString('probe-pw', $body);
    }

    #[Test]
    public function Guzzleの応答の失敗は_状態を件名に出す(): void
    {
        $e = \GuzzleHttp\Exception\RequestException::create(new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.test/x'), new \GuzzleHttp\Psr7\Response(429));

        $body = $this->reporter()->body($e);

        $this->assertStringStartsWith('[info][title]Probe（production）ClientException: HTTP 429[/title]', $body);
        $this->assertSame('HTTP 429', \Ad5\RichPhpChatwork\Details::of($e)->summary);
    }

    #[Test]
    public function noticeの地の文の行でも_写したプロセスの文言は省く(): void
    {
        $this->reporter()->notice('バックアップ', ['失敗した: The process "mysqldump -pS3cret db" exceeded the timeout of 60 seconds.'], 'backup');

        $this->assertStringContainsString('[/title]（コマンドラインを含むので省いた。ログを見る）[/info]', $this->transport->sent[0]['body']);
        $this->assertStringNotContainsString('S3cret', $this->transport->sent[0]['body']);
    }

    #[Test]
    public function 空白だけの文言は_空の枠を出さない(): void
    {
        config(['rich-chatwork.send_message' => true]);

        $body = $this->reporter()->body($this->failWithMessage(" \n\t "));

        $this->assertStringStartsWith('[info][title]Probe（production）RuntimeException[/title]アプリの行: ', $body);
    }

    #[Test]
    public function noticeの地の文は行ごとに省き_枠は省かない(): void
    {
        $this->reporter()->notice('取り込み', ['3 件が失敗', 'payload {"pin":"probe-pw-17"}'], 'dump', "{\"rows\": 3}");

        $this->assertSame("[info][title]Probe（production）知らせ: 取り込み[/title]3 件が失敗\n（値の書き出しを含むので省いた。ログを見る）\n[code]{\"rows\": 3}[/code][/info]", $this->transport->sent[0]['body']);
    }

    #[Test]
    public function 伏せる正規表現が失敗したら_項目ごと省く(): void
    {
        config(['rich-chatwork.send_message' => true, 'rich-chatwork.message_redact_patterns' => ['/(/']]);

        $this->reporter()->report($this->failWithMessage('社員 P-1234 の更新'));

        $this->assertCount(1, $this->transport->sent);
        $this->assertStringContainsString('[code]（伏せられなかったので省いた。ログを見る）[/code]', $this->transport->sent[0]['body']);
        $this->assertStringNotContainsString('P-1234', $this->transport->sent[0]['body']);
    }

    #[Test]
    public function 記法を逃がす_枠の外は全部_枠の中は始まりの形だけ(): void
    {
        config(['rich-chatwork.send_message' => true]);
        Context::add('request', 'GET /[toall]');

        $body = $this->reporter()->body($this->failWithMessage("[toall] [To:1] [/info] [/code] ['key'] [\$i] [] [TOALL]"));

        $this->assertStringContainsString("[code]［toall] ［To:1] ［/info] ［/code] ['key'] [\$i] [] ［TOALL][/code]", $body);
        $this->assertStringContainsString("リクエスト: GET /［toall］\n", $body);
        $this->assertSame(1, substr_count($body, '[/info]'));
    }

    #[Test]
    public function 包む例外は剥いで中を主に見て_包んだものの行にまとめる(): void
    {
        $inner = new LogicException('probe');
        $view = new ViewException('probe (View: ' . dirname(__DIR__) . '/resources/views/a.blade.php)', 0, 1, __FILE__, __LINE__, $inner);

        $body = $this->reporter()->body($view);

        $this->assertStringStartsWith('[info][title]Probe（production）LogicException[/title]包んだもの: ViewException（ビュー resources/views/a.blade.php）', $body);
    }

    #[Test]
    public function 内側の原因はクラスと場所だけを3行まで出す(): void
    {
        $root = new RuntimeException('root ' . self::SECRET);
        $chain = $root;
        for ($depth = 0; $depth < 4; $depth++) {
            $chain = new LogicException('cause ' . self::SECRET, 0, $chain);
        }
        $top = new RuntimeException('top', 0, $chain);

        $body = $this->reporter()->body($top);

        $this->assertSame(3, preg_match_all('/(?:^|\])原因: LogicException（' . preg_quote($this->relative(__FILE__), '/') . ':\d+）$/m', $body));
        $this->assertStringNotContainsString('taro', $body);
    }

    #[Test]
    public function 匿名クラスとクロージャの名前から_絶対パスとNULを除く(): void
    {
        $e = (static function (): RuntimeException {
            return new class ('probe') extends RuntimeException {
            };
        })();

        $body = $this->reporter()->body($e);

        $this->assertStringStartsWith('[info][title]Probe（production）RuntimeException@anonymous[/title]', $body);
        $this->assertStringNotContainsString("\0", $body);
        $this->assertStringNotContainsString(dirname(__DIR__), $body);
        $this->assertStringContainsString('{closure:', $body);
    }

    #[Test]
    public function 一般的な名前の例外は_件名に名前空間を1段付ける(): void
    {
        $body = $this->reporter()->body(new Fixtures\Service\Exception('probe'));

        $this->assertStringStartsWith('[info][title]Probe（production）Service\Exception[/title]', $body);
    }

    #[Test]
    public function 本文が3万文字を超えたら_呼び出しの並びから落とす(): void
    {
        $long = str_repeat('A', 5000);
        $frames = [];
        for ($index = 0; $index < 8; $index++) {
            $frames[] = ['file' => __FILE__, 'line' => 10 + $index, 'function' => "f{$index}{$long}", 'class' => self::class, 'type' => '->'];
        }
        $e = self::fabricate(new RuntimeException('probe'), __FILE__, __LINE__, $frames);

        $body = $this->reporter()->body($e);

        $this->assertLessThanOrEqual(30000, mb_strlen($body));
        $this->assertStringNotContainsString($long, $body);
        $this->assertStringNotContainsString('[hr]', $body);
        $this->assertStringContainsString('呼び出しの並び: 長いので省いた（ログを見る）', $body);
        $this->assertStringContainsString('> ', $body, '前後のコードは残す');
        $this->assertStringEndsWith('[/info]', $body);
    }

    #[Test]
    public function 間引いた回数を_次の知らせに添え_送れなければ回数を戻す(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-10 14:02:00'));
        $e = new RuntimeException('probe');
        $this->reporter()->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-10 14:02:10'));
        $this->reporter()->report($e);
        $this->reporter()->report($e);

        $this->travelTo(new \DateTimeImmutable('2026-11-10 14:03:30'));
        $this->transport->fail = true;
        $this->reporter()->report($e);
        $this->transport->fail = false;

        $this->travelTo(new \DateTimeImmutable('2026-11-11 09:00:00'));
        $this->reporter()->report($e);

        $this->assertCount(2, $this->transport->sent);
        $this->assertStringContainsString("同じ例外: 11/10 14:02 から、送らずに 3 回（送れなかった分を含む）\n", $this->transport->sent[1]['body']);
    }

    #[Test]
    public function 間引く秒数は60秒を超えたら分と時間で書く(): void
    {
        config(['rich-chatwork.throttle_seconds' => 3600]);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 08:00:00'));
        $e = new RuntimeException('probe');
        $this->reporter()->report($e);
        $this->reporter()->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 09:00:01'));
        $this->reporter()->report($e);

        $this->assertStringContainsString("同じ例外: 前の知らせ（08:00）の後の 1 時間に、送らずに 1 回\n", $this->transport->sent[1]['body']);
    }

    #[Test]
    public function キャッシュが使えなくても送り_一時フォルダの印でプロセスをまたいで間引く(): void
    {
        $this->breakCache();
        $e = new RuntimeException('probe');

        $this->reporter()->report($e);
        $this->otherProcess()->report($e);
        $this->travel(61)->seconds();
        $this->otherProcess()->report($e);

        $this->assertCount(2, $this->transport->sent);
        $this->assertStringContainsString('同じ例外: 前の知らせ（', $this->transport->sent[1]['body']);
        $this->assertStringContainsString('送らずに 1 回', $this->transport->sent[1]['body']);
    }

    #[Test]
    public function バッチではリクエストの代わりにコマンドの名前だけを書く(): void
    {
        Context::add('command', 'report:daily --mail=' . self::SECRET);

        $body = $this->reporter()->body(new RuntimeException('probe'));

        $this->assertStringContainsString("コマンド: report:daily\n", $body);
        $this->assertDoesNotMatchRegularExpression('/^リクエスト: /m', $body);
        $this->assertStringNotContainsString('taro', $body);
    }

    #[Test]
    public function HTTPではルートのテンプレートと利用者を書く_ルートが決まる前はメソッドだけ(): void
    {
        $this->runningOverHttp();
        $request = \Illuminate\Http\Request::create('/api/employees/5?token=probe', 'PUT');
        $this->app?->instance('request', $request);

        $before = $this->reporter()->body(new RuntimeException('probe'));

        $route = new \Illuminate\Routing\Route('PUT', 'api/employees/{employee}', static fn (): null => null);
        $request->setRouteResolver(static fn (): \Illuminate\Routing\Route => $route);
        Reporter::resolveUserUsing(static fn (): string => '42');
        $after = $this->reporter()->body(new RuntimeException('probe'));

        $this->assertStringContainsString("リクエスト: PUT（ルート未確定） ／ 利用者: 未ログイン\n", $before);
        $this->assertStringContainsString("リクエスト: PUT /api/employees/{employee} ／ 利用者: 42\n", $after);
    }

    #[Test]
    public function 件名と利用者はアプリが決められる(): void
    {
        config(['rich-chatwork.title' => '{app}:']);

        $body = $this->reporter()->body(new RuntimeException('probe'));

        $this->assertStringStartsWith('[info][title]Probe:RuntimeException[/title]', $body);
    }

    #[Test]
    public function 送らない環境では送らず_選べば伏せた本文をログに書く(): void
    {
        $this->useEnvironment('local');
        config(['rich-chatwork.log_when_silent' => true, 'rich-chatwork.send_message' => true]);
        $logged = $this->listenLog();

        $this->reporter()->report($this->failWithMessage('to ' . self::SECRET));

        $this->assertSame([], $this->transport->sent);
        $this->assertContains('例外の知らせ（この環境では Chatwork に送らない）', $logged->messages);
        $this->assertStringNotContainsString('taro', (string) json_encode($logged->contexts));
    }

    #[Test]
    public function 送る環境を絞ると_それ以外では送らない(): void
    {
        config(['rich-chatwork.environments' => ['production']]);
        $this->useEnvironment('staging');
        $this->reporter()->report(new RuntimeException('probe'));
        $this->assertSame([], $this->transport->sent);

        $this->useEnvironment('production');
        $this->reporter()->report(new RuntimeException('probe'));
        $this->assertCount(1, $this->transport->sent);
    }

    #[Test]
    public function 送り先が片方でも無ければ送らない(): void
    {
        config(['rich-chatwork.access_token' => null]);
        $this->reporter()->report(new RuntimeException('トークンが無い'));

        config(['rich-chatwork.access_token' => 'probe-token', 'rich-chatwork.room_id' => '']);
        $this->reporter()->report(new RuntimeException('部屋が無い'));

        $this->assertSame([], $this->transport->sent);
    }

    #[Test]
    public function 送れなくても投げず_クラス名だけをログに書く(): void
    {
        $this->transport->fail = true;
        $logged = $this->listenLog();

        $this->reporter()->report($this->failWith(self::SECRET));

        $this->assertContains('例外を Chatwork に送れなかった', $logged->messages);
        $this->assertStringNotContainsString('taro', (string) json_encode($logged->contexts));
    }

    #[Test]
    public function 例外ではない知らせは_同じキーなら間を置くまで1回だけ送り_行と枠を伏せて逃がす(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:00:00'));
        $this->reporter()->notice('ログインの失敗が多い', ['同じ ID で 20 回', '宛先 ' . self::SECRET . ' [toall]'], 'signin:probe', "token=abc\n[/code][toall]");
        $this->reporter()->notice('ログインの失敗が多い', ['同じ ID で 21 回'], 'signin:probe');
        $this->reporter()->notice('ログインの失敗が多い', ['別の ID'], 'signin:other');

        $this->assertCount(2, $this->transport->sent);
        $this->assertSame("[info][title]Probe（production）知らせ: ログインの失敗が多い[/title]同じ ID で 20 回\n宛先 （伏せた） ［toall］\n[code]token=（伏せた）\n［/code]［toall][/code][/info]", $this->transport->sent[0]['body']);

        $this->travel(3601)->seconds();
        $this->reporter()->notice('ログインの失敗が多い', ['同じ ID で 22 回'], 'signin:probe');

        $this->assertCount(3, $this->transport->sent);
        $this->assertStringEndsWith("同じ ID で 22 回\n同じ知らせ: 前の知らせ（10:00）の後の 1 時間に、送らずに 1 回[/info]", $this->transport->sent[2]['body']);
    }

    #[Test]
    public function 例外ではない知らせが3万文字を超えたら_行を後ろから落とす(): void
    {
        $lines = array_fill(0, 200, str_repeat('あ', 400));

        $this->reporter()->notice('多い', $lines, 'many');

        $body = $this->transport->sent[0]['body'];
        $this->assertLessThanOrEqual(30000, mb_strlen($body));
        $this->assertMatchesRegularExpression('/\n（以下 \d+ 行は省いた）\[\/info\]\z/u', $body);
    }

    #[Test]
    public function 例外ではない知らせも_送らない環境と送り先が無いときは送らず_送れなくても投げない(): void
    {
        $this->useEnvironment('local');
        $this->reporter()->notice('probe', ['probe'], 'a');

        $this->useEnvironment('production');
        config(['rich-chatwork.room_id' => null]);
        $this->reporter()->notice('probe', ['probe'], 'b');
        $this->assertSame([], $this->transport->sent);

        config(['rich-chatwork.room_id' => '12345']);
        $this->transport->fail = true;
        $logged = $this->listenLog();
        $this->reporter()->notice('probe', ['probe'], 'c');
        $this->assertContains('知らせを Chatwork に送れなかった', $logged->messages);
    }

    #[Test]
    public function 記録する例外はハンドラーから送られる(): void
    {
        report(new RuntimeException('probe'));

        $this->assertCount(1, $this->transport->sent);
    }

    #[Test]
    public function 例外の行と前後の行を行番号付きで添える(): void
    {
        $e = new RuntimeException('probe');

        $rows = explode("\n", (string) new Snippet(self::source())->of($e));
        $around = Snippet::CONTEXT_LINES;

        $this->assertCount($around * 2 + 1, $rows);
        $this->assertMatchesRegularExpression('/^> +' . $e->getLine() . ' \| +\$e = new RuntimeException\(\'probe\'\);$/', $rows[$around]);
        $this->assertStringContainsString('[code]' . implode("\n", $rows) . '[/code]', $this->reporter()->body($e));
    }

    #[Test]
    public function 前後のコードは許可したパスのファイルだけ(): void
    {
        config(['rich-chatwork.snippet_paths' => ['app/']]);

        $body = $this->reporter()->body(new RuntimeException('probe'));

        $this->assertStringContainsString('アプリの行: ' . $this->relative(__FILE__) . ':', $body);
        $this->assertStringNotContainsString('> ', $body);
    }

    #[Test]
    public function vendorの中で投げられた例外は_呼び出したアプリの行を添える(): void
    {
        $e = $this->failInVendor();
        $this->assertStringContainsString('/vendor/nesbot/carbon/', $e->getFile());

        $body = $this->reporter()->body($e);

        $this->assertMatchesRegularExpression('/^アプリの行: ' . preg_quote($this->relative(__FILE__), '/') . ':\d+（vendor の \w+\.php:\d+ で投げた）$/mu', $body);
        $this->assertMatchesRegularExpression("/^> +\\d+ \\| +CarbonImmutable::parse\\('日付ではない'\\);$/m", $body);
    }

    #[Test]
    public function vendorの段はまとめ_アプリの段は上限まで並べる(): void
    {
        $trace = new Trace(self::source())->of($this->failDeep(Trace::FRAMES + 5));

        $this->assertSame(10, Trace::FRAMES);
        $this->assertSame(Trace::FRAMES, preg_match_all('/^#\d+ /m', $trace));
        $this->assertStringNotContainsString('vendor/', $trace);
        $this->assertMatchesRegularExpression('/^（以下 \d+ 段はログ）$/m', $trace);
    }

    #[Test]
    public function 間引きの時刻はアプリのタイムゾーンで書く(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $e = new RuntimeException('probe');
        $this->travelTo(new \DateTimeImmutable('2026-11-11 05:02:00', new \DateTimeZone('UTC')));
        $this->reporter()->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 05:02:10', new \DateTimeZone('UTC')));
        $this->reporter()->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 05:03:30', new \DateTimeZone('UTC')));
        $this->reporter()->report($e);

        $this->assertStringContainsString('同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 1 回', $this->transport->sent[1]['body']);
    }

    #[Test]
    public function 組み立てに失敗したら_値を含まない最小の知らせを送り_部品の例外を生でログに書く(): void
    {
        $logged = $this->listenLog();
        $reporter = $this->reporterWithBrokenConfig('rich-chatwork.message_max_length');

        $reporter->report($this->failWith(self::SECRET));

        $this->assertSame(['[info][title]Probe（production）RuntimeException: 知らせを組み立てられなかった[/title]Request ID: req-1（中身はログをこの番号で引く）[/info]'], array_column($this->transport->sent, 'body'));
        $index = array_search('例外の知らせを組み立てられなかった', $logged->messages, true);
        $this->assertIsInt($index);
        $this->assertInstanceOf(LogicException::class, $logged->contexts[$index]['exception'] ?? null, '部品の例外を、文言と呼び出しの並びが出る形で渡す');
        $this->assertSame(RuntimeException::class, $logged->contexts[$index]['original'] ?? null);
        $this->assertSame('req-1', $logged->contexts[$index]['request_id'] ?? null);
        $this->assertStringNotContainsString('taro', (string) json_encode($logged->contexts));
    }

    #[Test]
    public function 間引きのキーを作れなくても_辿らずに作ったキーで最小の知らせを送り間引く(): void
    {
        $reporter = $this->reporterWithBrokenConfig('rich-chatwork.wrapper_exceptions');
        $e = new RuntimeException('probe');

        $reporter->report($e);
        $reporter->report($e);

        $this->assertCount(1, $this->transport->sent);
        $this->assertStringStartsWith('[info][title]Probe（production）RuntimeException: 知らせを組み立てられなかった[/title]', $this->transport->sent[0]['body']);
    }

    #[Test]
    public function 例外ではない知らせも_組み立てに失敗したら最小の知らせを送る(): void
    {
        $logged = $this->listenLog();
        $reporter = $this->reporterWithBrokenConfig('rich-chatwork.message_redact_patterns');

        $reporter->notice('ログインの失敗が多い', ['宛先 ' . self::SECRET], 'signin:probe');

        $this->assertSame(['[info][title]Probe（production）知らせ: 知らせを組み立てられなかった[/title]Request ID: req-1（中身はログをこの番号で引く）[/info]'], array_column($this->transport->sent, 'body'));
        $this->assertContains('知らせを組み立てられなかった', $logged->messages);
    }

    #[Test]
    public function 送れなかったら_間引きの印を消してすぐ送り直せるようにし_部品の例外を生でログに書く(): void
    {
        $logged = $this->listenLog();
        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:00:00'));
        $e = new RuntimeException('probe');
        $this->reporter()->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:00:10'));
        $this->reporter()->report($e);
        $this->reporter()->report($e);

        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:01:30'));
        $this->transport->fail = true;
        $this->reporter()->report($e);
        $this->transport->fail = false;
        $this->reporter()->report($e);

        $this->assertCount(2, $this->transport->sent);
        $this->assertStringContainsString('同じ例外: 10:00 から、送らずに 3 回（送れなかった分を含む）', $this->transport->sent[1]['body'], '前の知らせの後の窓と取り違えない言い方');
        $index = array_search('例外を Chatwork に送れなかった', $logged->messages, true);
        $this->assertIsInt($index);
        $failure = $logged->contexts[$index]['exception'] ?? null;
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame('Chatwork is down', $failure->getMessage());
        $this->assertSame(RuntimeException::class, $logged->contexts[$index]['original'] ?? null);
        $this->assertSame('req-1', $logged->contexts[$index]['request_id'] ?? null);
    }

    #[Test]
    public function 全体の上限を超えた分は_次の知らせに多い順3つのクラス名と件数で足す(): void
    {
        config(['rich-chatwork.max_per_minute' => 2]);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:00:00'));

        $this->reporter()->report($this->distinct(1));
        $this->reporter()->report($this->distinct(2));
        $this->reporter()->report($this->distinct(3));
        $this->reporter()->report($this->distinctLogic(4));
        $this->reporter()->report($this->distinctLogic(5));
        $this->reporter()->report($this->distinctType(6));
        $this->reporter()->report($this->distinctDomain(7));
        $this->reporter()->report($this->distinctDomain(8));
        $this->reporter()->report($this->distinctDomain(9));
        $this->reporter()->notice('多い', ['probe'], 'over');
        $this->assertCount(2, $this->transport->sent);

        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:01:00'));
        $this->reporter()->report($this->distinct(10));

        $this->assertCount(3, $this->transport->sent);
        $this->assertStringContainsString("ほかの知らせ・例外: 10:00 から 8 件（DomainException 3・LogicException 2・RuntimeException 1、上限で送らなかった。ログを見る）\n", $this->transport->sent[2]['body']);
    }

    #[Test]
    public function 本番で送り先が空なら_最初の例外で1度だけ警告を書く(): void
    {
        config(['rich-chatwork.access_token' => null]);
        $logged = $this->listenLog();

        $this->reporter()->report(new RuntimeException('probe'));
        $this->reporter()->report(new LogicException('probe'));

        $this->assertSame(1, count(array_filter($logged->messages, static fn (string $message): bool => str_starts_with($message, 'Chatwork の送り先が空'))));

        $this->useEnvironment('local');
        $this->app?->forgetInstance(Reporter::class);
        $quiet = $this->listenLog();
        $this->reporter()->report(new RuntimeException('probe'));
        $this->assertSame([], array_filter($quiet->messages, static fn (string $message): bool => str_starts_with($message, 'Chatwork の送り先が空')), '送らない環境では書かない');
    }

    #[Test]
    public function 上限で落ちたのが送る例外と同じなら_ほかではなく同じ例外の回数に入れる(): void
    {
        config(['rich-chatwork.max_per_minute' => 1]);
        $this->travelTo(new \DateTimeImmutable('2026-11-10 23:59:00'));
        $this->reporter()->report($this->distinct(1));
        $this->reporter()->report($this->distinct(2));
        $this->reporter()->report($this->distinctLogic(3));

        $this->travelTo(new \DateTimeImmutable('2026-11-11 00:00:10'));
        $this->reporter()->report($this->distinct(2));

        $body = $this->transport->sent[1]['body'];
        $this->assertStringContainsString("同じ例外: 上限で送らなかった 1 回（11/10 23:59 から）\n", $body, '前の知らせが無いので、間引きの言い方を書かない');
        $this->assertStringContainsString('ほかの例外: 11/10 23:59 から 1 件（LogicException 1、', $body);
    }

    #[Test]
    public function 間引いた回数と上限で送らなかった回数は_分けて書く(): void
    {
        config(['rich-chatwork.max_per_minute' => 2]);
        $e = $this->distinct(1);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 14:02:00'));
        $this->reporter()->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 14:02:10'));
        $this->reporter()->report($e);
        $this->reporter()->report($e);
        $this->reporter()->report($this->distinct(2));
        $this->travelTo(new \DateTimeImmutable('2026-11-11 14:05:00'));
        $this->reporter()->report($this->distinct(3));
        $this->reporter()->report($this->distinct(4));
        $this->reporter()->report($e);
        $this->reporter()->report($e);
        $this->reporter()->report($e);

        $this->travelTo(new \DateTimeImmutable('2026-11-11 14:06:00'));
        $this->reporter()->report($e);

        $this->assertStringContainsString("同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 2 回。上限で送らなかった 3 回（14:05 から）\n", (end($this->transport->sent) ?: ['body' => ''])['body']);
    }

    #[Test]
    public function 値を持てないキャッシュでも_予備の置き場で間引いて届け_1度だけログに書く(): void
    {
        config(['cache.stores.null' => ['driver' => 'null'], 'rich-chatwork.cache_store' => 'null']);
        $this->clearFallback();
        $logged = $this->listenLog();
        $e = new RuntimeException('probe');

        $this->reporter()->report($e);
        $this->reporter()->report($e);
        $this->reporter()->report(new LogicException('probe'));

        $this->assertCount(2, $this->transport->sent);
        $this->assertSame(1, count(array_filter($logged->messages, static fn (string $m): bool => str_starts_with($m, '間引きのキャッシュが使えない'))));
    }

    #[Test]
    public function 予備の一時フォルダは_自分の物で書き込めるときだけ_権限を絞って使う(): void
    {
        $this->breakCache();
        $directory = Throttle::fallbackDirectory(dirname(__DIR__));

        $this->reporter()->report(new RuntimeException('probe'));

        $files = array_filter(new \Illuminate\Filesystem\Filesystem()->allFiles($directory), static fn (\SplFileInfo $file): bool => $file->isFile());
        $this->assertNotSame([], $files);
        foreach ($files as $file) {
            $this->assertSame('600', substr(sprintf('%o', $file->getPerms()), -3), 'キャッシュのファイルは持ち主だけ');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            new \Illuminate\Filesystem\Filesystem()->deleteDirectory($directory);
            mkdir($directory, 0777);
            chown($directory, 12345);
            $this->app?->forgetInstance(Reporter::class);
            $logged = $this->listenLog();
            $this->reporter()->report(new LogicException('probe'));
            $this->reporter()->report(new \DomainException('probe'));
            $this->assertSame([], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])), '持ち主の違うフォルダには書かない');
            $this->assertSame(1, count(array_filter($logged->messages, static fn (string $m): bool => str_starts_with($m, '予備の一時フォルダが使えない'))));
            rmdir($directory);
        }
    }

    #[Test]
    public function スクリプトの持ち主とプロセスの持ち主が違っても_予備の一時フォルダを使う(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('持ち主を替えるには root が要る');
        }

        $base = '/probe/' . bin2hex(random_bytes(4));
        $script = sys_get_temp_dir() . '/rich-chatwork-owner-' . bin2hex(random_bytes(4)) . '.php';
        copy(__DIR__ . '/bin/fallback-owner.php', $script);
        chown($script, 12345);
        $run = static fn (): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($base) . ' ' . escapeshellarg(dirname(__DIR__) . '/vendor/autoload.php') . ' 2>/dev/null');

        try {
            $this->assertSame('sent', $run());
            $this->assertSame('throttled', $run(), '別のプロセスでも一時フォルダの印で間引く（スクリプトの持ち主で確かめると使えない）');
        } finally {
            unlink($script);
            new \Illuminate\Filesystem\Filesystem()->deleteDirectory(Throttle::fallbackDirectory($base));
        }
    }

    #[Test]
    public function 長さで替えた知らせは_件名の要約を残し_組み立ての失敗と言い分ける(): void
    {
        $oversized = new \ReflectionMethod(Reporter::class, 'oversized');
        $pdo = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 x');
        $pdo->errorInfo = ['23000', 1062, 'x'];

        $this->assertSame("[info][title]Probe（production）QueryException: SQLSTATE 23000[/title]長すぎて省いた（ログを見る）\nRequest ID: req-1（中身はログをこの番号で引く）[/info]", $oversized->invoke($this->reporter(), new \Illuminate\Database\QueryException('mysql', 'select 1', [], $pdo), []));
        $this->assertSame("[info][title]Probe（production）知らせ: 多い[/title]長すぎて省いた（ログを見る）\nRequest ID: req-1（中身はログをこの番号で引く）[/info]", $oversized->invoke($this->reporter(), null, [], '多い'));

        // 要約が長くても、種類（短いクラス名）は件名に残り、要約の後ろから切る
        $long = new \TypeError('App\\Services\\' . str_repeat('Long', 10) . '\\VeryLongServiceNameForProbe::runSomethingVeryLong(): Argument #1 ($employeeId) must be of type int, null given');
        $body = $oversized->invoke($this->reporter(), $long, []);
        $this->assertIsString($body);
        $title = (string) preg_replace('/\A\[info\]\[title\](.*?)\[\/title\].*\z/su', '$1', $body);
        $this->assertStringStartsWith('Probe（production）TypeError: VeryLongServiceNameForProbe::', $title);
        $this->assertLessThanOrEqual(80, mb_strwidth($title));
    }

    #[Test]
    public function メモリ切れで上限を上げるときは_下げない(): void
    {
        $previous = ini_get('memory_limit');
        $room = new \ReflectionMethod(\Ad5\RichPhpChatwork\RichChatworkServiceProvider::class, 'makeRoomForMemoryExhaustion');
        $fatal = new \Symfony\Component\ErrorHandler\Error\FatalError('Allowed memory size of 1024 bytes exhausted', 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => 1]);
        try {
            ini_set('memory_limit', '4G');
            $this->assertTrue($room->invoke(null, $fatal));
            $this->assertSame('4G', ini_get('memory_limit'), '今より大きいときだけ上げる');

            ini_set('memory_limit', '-1');
            $this->assertTrue($room->invoke(null, $fatal));
            $this->assertSame('-1', ini_get('memory_limit'));

            ini_set('memory_limit', (string) (memory_get_usage(true) + 1024 * 1024));
            $this->assertTrue($room->invoke(null, $fatal));
            $this->assertGreaterThanOrEqual(memory_get_usage(true) + 30 * 1024 * 1024, (int) ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', (string) $previous);
        }
    }

    #[Test]
    public function Collisionに包まれたハンドラーにも登録し_二重には登録しない(): void
    {
        $app = $this->app ?? $this->fail('アプリが無い');
        $provider = $app->getProvider(\Ad5\RichPhpChatwork\RichChatworkServiceProvider::class);
        $this->assertInstanceOf(\Ad5\RichPhpChatwork\RichChatworkServiceProvider::class, $provider);
        $adapter = new \NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler($app, new \Illuminate\Foundation\Exceptions\Handler($app));

        $provider->attach($adapter);
        $provider->attach($adapter);
        $adapter->report(new RuntimeException('probe'));

        $this->assertCount(1, $this->transport->sent);
    }

    #[Test]
    public function reportableを持たないハンドラーなら_1度だけ警告する(): void
    {
        $app = $this->app ?? $this->fail('アプリが無い');
        $provider = $app->getProvider(\Ad5\RichPhpChatwork\RichChatworkServiceProvider::class);
        $this->assertInstanceOf(\Ad5\RichPhpChatwork\RichChatworkServiceProvider::class, $provider);
        $logged = $this->listenLog();
        $handler = new class () implements \Illuminate\Contracts\Debug\ExceptionHandler {
            public function report(\Throwable $e): void
            {
            }

            public function shouldReport(\Throwable $e): bool
            {
                return true;
            }

            public function render($request, \Throwable $e): \Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response();
            }

            public function renderForConsole($output, \Throwable $e): void
            {
            }
        };

        $provider->attach($handler);
        $provider->attach($handler);

        $this->assertSame(1, count(array_filter($logged->messages, static fn (string $m): bool => str_starts_with($m, '例外のハンドラーに reportable が無い'))));
    }

    #[Test]
    public function 例外の知らせを作る所が投げても_元の例外はLaravelが記録し_送れなかったと書く(): void
    {
        $this->app?->bind(Transport::class, static fn (): Transport => throw new LogicException('transport is broken'));
        $this->app?->forgetInstance(Reporter::class);
        $logged = $this->listenLog();

        report(new RuntimeException('probe-original'));

        $this->assertContains('probe-original', $logged->messages, 'Laravel の元の記録が止まらない');
        $index = array_search('例外を Chatwork に送れなかった', $logged->messages, true);
        $this->assertIsInt($index);
        $this->assertInstanceOf(\Throwable::class, $logged->contexts[$index]['exception'] ?? null);
        $this->assertSame(RuntimeException::class, $logged->contexts[$index]['original'] ?? null);
    }

    #[Test]
    public function 設定を読めなくても_送れなかったと書く(): void
    {
        $logged = $this->listenLog();

        $this->reporterWithBrokenConfig('rich-chatwork.access_token')->report(new RuntimeException('probe'));
        $this->reporterWithBrokenConfig('rich-chatwork.access_token')->notice('probe', ['probe'], 'a');

        $this->assertContains('例外を Chatwork に送れなかった', $logged->messages);
        $this->assertContains('知らせを Chatwork に送れなかった', $logged->messages);
    }

    #[Test]
    public function 最小の知らせにも_間引いた回数を付ける(): void
    {
        $reporter = $this->reporterWithBrokenConfig('rich-chatwork.wrapper_exceptions');
        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:00:00'));
        $e = new RuntimeException('probe');
        $reporter->report($e);
        $reporter->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 10:01:01'));
        $reporter->report($e);

        $this->assertSame('[info][title]Probe（production）RuntimeException: 知らせを組み立てられなかった[/title]Request ID: req-1（中身はログをこの番号で引く）' . "\n" . '同じ例外: 前の知らせ（10:00）の後の 60 秒に、送らずに 1 回[/info]', $this->transport->sent[1]['body']);
    }

    #[Test]
    public function 例外ではない知らせの枠が長ければ_枠の末尾の行を落とす(): void
    {
        $code = implode("\n", array_fill(0, 5000, str_repeat('x', 28)));

        $this->reporter()->notice('多い', ['1 行'], 'long-code', $code);

        $body = $this->transport->sent[0]['body'];
        $this->assertLessThanOrEqual(30000, mb_strlen($body));
        $this->assertMatchesRegularExpression('/\n（以下 \d+ 行は省いた）\[\/code\]\[\/info\]\z/u', $body);
        $this->assertStringContainsString('[/title]1 行', $body);
    }

    #[Test]
    public function 文言が長すぎて落としても超えるなら_文言を詰める(): void
    {
        config(['rich-chatwork.send_message' => true, 'rich-chatwork.message_max_length' => 100000]);

        $body = $this->reporter()->body($this->failWithMessage(str_repeat('x', 60000)));

        $this->assertLessThanOrEqual(30000, mb_strlen($body));
        $this->assertMatchesRegularExpression('/\[code\]x+…\[\/code\]/', $body);
        $this->assertStringContainsString('前後のコード: 長いので省いた（ログを見る）', $body);
    }

    #[Test]
    public function 送る直前に3万文字を超えていたら_最小の知らせに替える(): void
    {
        $reporter = $this->reporter();
        // 組み立ての全部の項目に上限があるので、本文を直接渡して最後の守りだけを見る
        new \ReflectionMethod(Reporter::class, 'deliver')->invoke($reporter, 'probe-token', '12345', 'guard', 60, static fn (array $extra): string => str_repeat('a', 30001), static fn (array $extra): string => 'minimal', '同じ例外', 'Probe');

        $this->assertSame('minimal', $this->transport->sent[0]['body']);
    }

    #[Test]
    public function トークンと本文は_呼び出しの並びの引数に残さない(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $this->transport->fail = true;
        $logged = $this->listenLog();
        try {
            $this->reporter()->report(new RuntimeException('probe'));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        $index = array_search('例外を Chatwork に送れなかった', $logged->messages, true);
        $this->assertIsInt($index);
        $failure = $logged->contexts[$index]['exception'] ?? null;
        $this->assertInstanceOf(\Throwable::class, $failure);
        $args = array_merge(...array_map(static fn (array $frame): array => $frame['args'] ?? [], $failure->getTrace()));
        $this->assertNotContains('probe-token', array_filter($args, is_string(...)));
        $this->assertNotSame([], array_filter($args, static fn (mixed $arg): bool => $arg instanceof \SensitiveParameterValue), '引数は残る設定になっている');

        foreach ([[Transport::class, 'send'], [PhpChatworkTransport::class, 'send']] as [$class, $method]) {
            foreach (['token', 'body'] as $parameter) {
                $reflection = array_find(new \ReflectionMethod($class, $method)->getParameters(), static fn (\ReflectionParameter $p): bool => $p->getName() === $parameter);
                $this->assertNotNull($reflection);
                $this->assertNotSame([], $reflection->getAttributes(\SensitiveParameter::class), "{$class}::{$method}() の \${$parameter}");
            }
        }
    }

    /**
     * 同じキャッシュとアプリを使う別のプロセス（Reporter をもう 1 つ作る）
     */
    private function otherProcess(): Reporter
    {
        $app = $this->app ?? $this->fail('アプリが無い');

        return new Reporter($app, config(), $app->make('cache'), $this->transport, self::source(), $app->make(JobEntrance::class));
    }

    /**
     * 設定の 1 つのキーを読むと投げる Reporter（部品の組み立ての失敗を起こす）
     */
    private function reporterWithBrokenConfig(string $brokenKey): Reporter
    {
        $items = config()->all();
        $config = new class ($items, $brokenKey) extends ConfigRepository {
            /**
             * @param array<string, mixed> $items
             */
            public function __construct(array $items, private readonly string $broken)
            {
                parent::__construct($items);
            }

            /**
             * @param array<mixed>|string $key
             */
            public function get($key, $default = null): mixed
            {
                if ($key === $this->broken) {
                    throw new LogicException('config is broken');
                }

                return parent::get($key, $default);
            }
        };
        $app = $this->app ?? $this->fail('アプリが無い');

        return new Reporter($app, $config, $app->make('cache'), $this->transport, self::source(), $app->make(JobEntrance::class));
    }

    /*
     * 投げた場所の違う例外（間引きのキーが別になる）
     */

    private function distinct(int $n): RuntimeException
    {
        return self::fabricate(new RuntimeException('probe'), __FILE__, $n, []);
    }

    private function distinctLogic(int $n): LogicException
    {
        return self::fabricate(new LogicException('probe'), __FILE__, $n, []);
    }

    private function distinctType(int $n): \TypeError
    {
        return self::fabricate(new \TypeError('probe'), __FILE__, $n, []);
    }

    private function distinctDomain(int $n): \DomainException
    {
        return self::fabricate(new \DomainException('probe'), __FILE__, $n, []);
    }

    private function reporter(): Reporter
    {
        return $this->app?->make(Reporter::class) ?? $this->fail('アプリが無い');
    }

    private function relative(string $path): string
    {
        return self::source()->relative($path);
    }

    private function listenLog(): LogSpy
    {
        $spy = new LogSpy();
        Log::listen(static function (MessageLogged $message) use ($spy): void {
            $spy->messages[] = $message->message;
            $spy->contexts[] = $message->context;
        });

        return $spy;
    }

    /**
     * 読み書きのたびに投げるキャッシュ（DB やディスクが落ちたときの形）
     */
    private function breakCache(): void
    {
        Cache::extend('broken', fn (Application $app): Repository => new Repository(new class () extends ArrayStore {
            public function get($key): mixed
            {
                throw new RuntimeException('cache is down');
            }

            public function add(mixed $key, mixed $value, mixed $seconds): bool
            {
                throw new RuntimeException('cache is down');
            }
        }));
        config(['cache.stores.broken' => ['driver' => 'broken'], 'rich-chatwork.cache_store' => 'broken']);
        $this->app?->forgetInstance(Reporter::class);
        // 予備の一時フォルダは前のテストの印を持ち越さない
        $this->clearFallback();
    }

    private function clearFallback(): void
    {
        $directory = Throttle::fallbackDirectory(dirname(__DIR__));
        if (is_dir($directory)) {
            new \Illuminate\Filesystem\Filesystem()->deleteDirectory($directory);
        }
    }

    private function failInVendor(): InvalidFormatException
    {
        try {
            CarbonImmutable::parse('日付ではない');
        } catch (InvalidFormatException $e) {
            return $e;
        }

        $this->fail('Carbon が日付ではない文字を受け付けた');
    }

    private function failDeep(int $depth): RuntimeException
    {
        return $depth === 0 ? new RuntimeException('probe') : $this->failDeep($depth - 1);
    }

    private function failWithMessage(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }

    /**
     * 引数に値を持って投げる。前後の行に値の文字が入らないよう、呼び出し側から離して置く
     */
    private function failWith(string $value): RuntimeException
    {
        return new RuntimeException("送ってはいけない {$value}");
    }
}
