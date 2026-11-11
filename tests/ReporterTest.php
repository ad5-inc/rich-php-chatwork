<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\JobEntrance;
use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\Snippet;
use Ad5\RichPhpChatwork\Trace;
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

        $this->assertStringContainsString('[/title][code]鍵 password=（伏せた） が無い[/code]アプリの行: ', $short);
        $this->assertStringContainsString('[code]' . str_repeat('あ', 9) . '…[/code]', $long, '全角は幅 2');
    }

    #[Test]
    public function 文字を直してから伏せ_逃がしてから切る(): void
    {
        config(['rich-chatwork.send_message' => true]);

        $body = $this->reporter()->body($this->failWithMessage("壊れた\xC3\x28文字\x07と\ttab\nと改行"));

        $this->assertStringContainsString("[code]壊れた?(文字と    tab\nと改行[/code]", $body);
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
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
        $this->assertStringContainsString("同じ例外: 前の知らせ（11/10 14:02）の後の 60 秒に、送らずに 2 回\n", $this->transport->sent[1]['body']);
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
    public function キャッシュが使えなくても送り_このプロセスの中で間引き_回数の行は出さない(): void
    {
        $this->breakCache();
        $e = new RuntimeException('probe');

        $this->reporter()->report($e);
        $this->reporter()->report($e);
        $this->travel(61)->seconds();
        $this->reporter()->report($e);

        $this->assertCount(2, $this->transport->sent);
        $this->assertStringNotContainsString('同じ例外', $this->transport->sent[1]['body']);
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
        $this->assertStringContainsString('同じ例外: 前の知らせ（10:00）の後の 60 秒に、送らずに 2 回', $this->transport->sent[1]['body']);
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
        $this->assertStringContainsString("ほかの例外: 8 件（DomainException 3・LogicException 2・RuntimeException 1、上限で送らなかった。ログを見る）\n", $this->transport->sent[2]['body']);
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
