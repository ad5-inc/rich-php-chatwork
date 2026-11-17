<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\Version;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * 本文の最後の行に出す部品の版。リリースの版の形だけを出し、取れなくても知らせは止めない
 */
final class VersionTest extends TestCase
{
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
    #[DataProvider('releases')]
    public function リリースの版は名前と並べて出す(string $pretty): void
    {
        $this->assertSame("rich-php-chatwork {$pretty}", new Version($pretty)->line());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function releases(): iterable
    {
        yield '版' => ['0.6.1'];
        yield '頭に v' => ['v1.2.3'];
        yield 'プレリリース' => ['1.0.0-beta.2'];
    }

    #[Test]
    #[DataProvider('unknowns')]
    public function 版の形でなければ決まった文にする(?string $pretty): void
    {
        $this->assertSame(Version::UNKNOWN, new Version($pretty)->line());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unknowns(): iterable
    {
        yield '記録が無い' => [null];
        yield '空' => [''];
        yield 'dev のブランチ' => ['dev-main'];
        yield 'スラッシュを含むブランチ' => ['dev-feat/version-in-body'];
        yield 'ブランチの別名' => ['0.6.x-dev'];
        yield 'ルートの仮の版' => ['1.0.0+no-version-set'];
        yield '記法' => ['0.6.1[toall]'];
        yield '記法だけ' => ['[To:1]'];
        yield '絵文字の記号' => ['0.6.1-:D'];
        yield '改行' => ["0.6.1\n[toall]"];
        yield '空白' => ['0.6.1 beta'];
    }

    #[Test]
    public function 今入っている版を取っても投げず_決まった形のどちらかになる(): void
    {
        $line = Version::installed()->line();

        $this->assertMatchesRegularExpression('/\A(?:rich-php-chatwork v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?|' . preg_quote(Version::UNKNOWN, '/') . ')\z/u', $line);
    }

    #[Test]
    public function 例外の知らせは呼び出しの並びの後ろの最後の行に版を出す(): void
    {
        $this->reporter()->report(new RuntimeException('失敗した'));

        $this->assertCount(1, $this->transport->sent);
        $this->assertStringEndsWith("[/code]rich-php-chatwork 0.6.1[/info]", $this->transport->sent[0]['body']);
        $this->assertStringNotContainsString('0.6.1', explode('[/title]', $this->transport->sent[0]['body'])[0], '件名には入れない');
    }

    #[Test]
    public function 版が取れなくても例外の知らせとnoticeを送る(): void
    {
        $this->app?->instance(Version::class, new Version(null));
        $this->app?->forgetInstance(Reporter::class);

        $this->reporter()->report(new RuntimeException('失敗した'));
        $this->reporter()->notice('多い', ['1 行'], 'unknown-version');

        $this->assertCount(2, $this->transport->sent);
        $this->assertStringEndsWith('[/code]' . Version::UNKNOWN . '[/info]', $this->transport->sent[0]['body']);
        $this->assertSame("[info][title]Probe（production）知らせ: 多い[/title]1 行\n" . Version::UNKNOWN . '[/info]', $this->transport->sent[1]['body']);
    }

    #[Test]
    public function 記法の入った版は本文に出さない(): void
    {
        $this->app?->instance(Version::class, new Version('0.6.1[/info][toall]'));
        $this->app?->forgetInstance(Reporter::class);

        $this->reporter()->notice('多い', ['1 行'], 'markup-version');

        $body = $this->transport->sent[0]['body'];
        $this->assertStringNotContainsString('[toall]', $body);
        $this->assertSame(1, substr_count($body, '[/info]'));
        $this->assertStringEndsWith(Version::UNKNOWN . '[/info]', $body);
    }

    private function reporter(): Reporter
    {
        return $this->app?->make(Reporter::class) ?? $this->fail('アプリが無い');
    }
}
