<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\Snippet;
use Ad5\RichPhpChatwork\Trace;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * 送る条件・送る中身（値を入れない）・間引き・送れないときの振る舞い
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
    public function 本文にクラス・場所・番号を書き_例外の文言と引数の値は書かない(): void
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

        $this->assertStringStartsWith('[info][title]Probe（production）にて例外発生[/title]', $body);
        $this->assertStringContainsString('例外: ' . RuntimeException::class, $body);
        $this->assertStringContainsString('場所: ' . $this->relative(__FILE__) . ':', $body);
        $this->assertStringContainsString("Request ID: req-1\n", $body);
        $this->assertStringContainsString("リクエスト: GET /api/reports/1\n", $body);
        $this->assertStringContainsString("利用者: （未ログイン）\n", $body);
        $this->assertStringContainsString(self::class . '->failWith()', $body);
        $this->assertStringNotContainsString('taro', $body);
        $this->assertStringNotContainsString(dirname(__DIR__), $body, '手元のパスを送らない');
    }

    #[Test]
    public function 設定で選べば例外の文言をそのまま添え_長い文言は切る(): void
    {
        config(['rich-chatwork.send_message' => true, 'rich-chatwork.message_max_length' => 20]);

        $short = $this->reporter()->body($this->failWithMessage('鍵のファイルが無い'));
        $long = $this->reporter()->body($this->failWithMessage(str_repeat('あ', 30)));

        $this->assertStringContainsString("文言: 鍵のファイルが無い\n", $short);
        $this->assertMatchesRegularExpression('/^文言: あ+…$/mu', $long);
        $this->assertStringNotContainsString(str_repeat('あ', 30), $long);
    }

    #[Test]
    public function バッチではリクエストの代わりにコマンドを書く(): void
    {
        Context::add('command', 'report:daily');

        $body = $this->reporter()->body(new RuntimeException('probe'));

        $this->assertStringContainsString("コマンド: report:daily\n", $body);
        // 前後の行にこのテストのソースが入るので、行頭で見る
        $this->assertDoesNotMatchRegularExpression('/^リクエスト: /m', $body);
    }

    #[Test]
    public function 件名と利用者はアプリが決められる(): void
    {
        config(['rich-chatwork.title' => '{app}にて例外発生']);
        Reporter::resolveUserUsing(static fn (): string => '社員#42');

        $body = $this->reporter()->body(new RuntimeException('probe'));

        $this->assertStringStartsWith('[info][title]Probeにて例外発生[/title]', $body);
        $this->assertStringContainsString("利用者: 社員#42\n", $body);
    }

    #[Test]
    public function 送らない環境では送らず_選べばログに書く(): void
    {
        $this->useEnvironment('local');
        config(['rich-chatwork.log_when_silent' => true]);
        $logged = $this->listenLog();

        $this->reporter()->report(new RuntimeException('probe'));

        $this->assertSame([], $this->transport->sent);
        $this->assertContains('例外の知らせ（この環境では Chatwork に送らない）', $logged->messages);
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
    public function キャッシュが使えなくても送り_このプロセスの中で間引く(): void
    {
        $this->breakCache();
        $e = new RuntimeException('probe');

        $this->reporter()->report($e);
        $this->reporter()->report($e);

        $this->assertCount(1, $this->transport->sent);
    }

    #[Test]
    public function 例外ではない知らせは_同じキーなら間を置くまで1回だけ送る(): void
    {
        $this->reporter()->notice('ログインの失敗が多い', ['同じ ID で 20 回', '最後の IP: 203.0.113.10'], 'signin:probe');
        $this->reporter()->notice('ログインの失敗が多い', ['同じ ID で 21 回'], 'signin:probe');
        $this->reporter()->notice('ログインの失敗が多い', ['別の ID'], 'signin:other');

        $this->assertCount(2, $this->transport->sent);
        $this->assertSame("[info][title]Probe（production）ログインの失敗が多い[/title]同じ ID で 20 回\n最後の IP: 203.0.113.10[/info]", $this->transport->sent[0]['body']);

        $this->travel(3601)->seconds();
        $this->reporter()->notice('ログインの失敗が多い', ['同じ ID で 22 回'], 'signin:probe');

        $this->assertCount(3, $this->transport->sent);
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
    public function 例外の行と前後3行を行番号付きで添える(): void
    {
        $e = new RuntimeException('probe');

        $rows = explode("\n", (string) new Snippet(self::source())->of($e));
        $around = Snippet::CONTEXT_LINES;

        $this->assertSame($this->relative(__FILE__) . ':' . $e->getLine(), $rows[0]);
        $this->assertCount(1 + $around * 2 + 1, $rows);
        $this->assertMatchesRegularExpression('/^> +' . $e->getLine() . ' \| +\$e = new RuntimeException\(\'probe\'\);$/', $rows[1 + $around]);
        $this->assertStringContainsString("[code]{$rows[0]}\n", $this->reporter()->body($e));
    }

    #[Test]
    public function vendorの中で投げられた例外は_呼び出したアプリの行を添える(): void
    {
        $e = $this->failInVendor();
        $this->assertStringContainsString('/vendor/nesbot/carbon/', $e->getFile());

        $snippet = (string) new Snippet(self::source())->of($e);

        $this->assertStringStartsWith($this->relative(__FILE__) . ':', $snippet);
        $this->assertMatchesRegularExpression("/^> +\\d+ \\| +CarbonImmutable::parse\\('日付ではない'\\);$/m", $snippet);
    }

    #[Test]
    public function vendorの段はまとめ_アプリの段は上限まで並べる(): void
    {
        $trace = new Trace(self::source())->of($this->failDeep(Trace::FRAMES + 5));

        $this->assertSame(Trace::FRAMES, preg_match_all('/^#\d+ /m', $trace));
        $this->assertStringNotContainsString('vendor/', $trace);
        $this->assertMatchesRegularExpression('/^（以下 \d+ 段はログ）$/m', $trace);
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
     * 引数に値を持って投げる。前後 3 行に値の文字が入らないよう、呼び出し側から離して置く
     */
    private function failWith(string $value): RuntimeException
    {
        return new RuntimeException("送ってはいけない {$value}");
    }
}
