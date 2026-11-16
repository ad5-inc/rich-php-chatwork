<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Glossary;
use ArgumentCountError;
use DivisionByZeroError;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PlainTestCase;
use RuntimeException;
use UnhandledMatchError;
use ValueError;

/**
 * 番号・状態・reason・終了コード・例外のクラスに添える決まった日本語。表に無い値には何も返さない
 */
final class GlossaryTest extends PlainTestCase
{
    #[Test]
    public function MySQLの番号はSQLSTATEより先に見て_23000を番号で言い分ける(): void
    {
        $this->assertSame(['short' => '重複', 'explanation' => '一意のキー（主キー・unique）に、すでにある値を入れようとした'], Glossary::database('23000', 1062, true));
        $this->assertSame('参照先が無い', Glossary::database('23000', 1452, true)['short'] ?? null);
        $this->assertSame('参照されている行', Glossary::database('23000', 1451, true)['short'] ?? null);
        $this->assertSame('空にできない列', Glossary::database('23000', 1048, true)['short'] ?? null);
    }

    #[Test]
    public function 番号が表に無ければ_SQLSTATEの表を完全一致で引く(): void
    {
        $this->assertSame(['short' => '制約に反した', 'explanation' => '一意・外部キー・NOT NULL などの制約に反した（どれかは番号を見る）'], Glossary::database('23000', 9999, true));
        $this->assertSame('列が無い', Glossary::database('42S22', null, true)['short'] ?? null);
    }

    #[Test]
    public function 番号はSQLSTATEとの組では引かない(): void
    {
        // 1366 は資料の SQLSTATE が HY000 でも、実際には 22007 で返ることがある
        $this->assertSame('値が列に合わない', Glossary::database('22007', 1366, true)['short'] ?? null);
        $this->assertSame('値が列に合わない', Glossary::database('HY000', 1366, true)['short'] ?? null);
    }

    #[Test]
    public function MySQLでない接続では_番号の表を引かない(): void
    {
        // SQL Server の 1205 はデッドロックで、MySQL の 1205（ロック待ちの時間切れ）とは違う
        $this->assertNull(Glossary::database('HY000', 1205, false));
        $this->assertSame('やり直しが要る', Glossary::database('40001', 1205, false)['short'] ?? null);
        $this->assertSame('重複', Glossary::database('23505', 7, false)['short'] ?? null);
    }

    #[Test]
    public function 表に無いSQLSTATEとHY000には何も付けず_頭2文字で推測しない(): void
    {
        $this->assertNull(Glossary::database('HY000', null, true));
        $this->assertNull(Glossary::database('42999', null, true));
        $this->assertNull(Glossary::database('23999', 9999, true));
        $this->assertNull(Glossary::database(null, null, true));
    }

    #[Test]
    public function 接続の失敗の番号は言い分ける(): void
    {
        $this->assertSame(['short' => '接続が切れた', 'explanation' => 'DB との接続が切れていた'], Glossary::database('HY000', 2006, true));
        $this->assertSame('接続できない', Glossary::database('HY000', 2002, true)['short'] ?? null);
        $this->assertSame('認証に失敗', Glossary::database('HY000', 1045, true)['short'] ?? null);
        $this->assertSame('問い合わせ中に切断', Glossary::database('HY000', 2013, true)['short'] ?? null);
    }

    #[Test]
    public function HTTPの状態は相手の応答として言う(): void
    {
        $this->assertSame(['short' => '相手が一時停止', 'explanation' => '相手が一時的に使えない（混雑か保守）'], Glossary::http(503));
        $this->assertSame('回数の上限', Glossary::http(429)['short'] ?? null);
        $this->assertNull(Glossary::http(418));
        $this->assertNull(Glossary::http(599));
        $this->assertNull(Glossary::http(200));
    }

    #[Test]
    public function Googleのreasonは状態より先に見る(): void
    {
        // rateLimitExceeded は 403 でも来る。403 の「相手が権限なしで拒否」にしない
        $this->assertSame(['short' => '回数の上限', 'explanation' => '短い時間に送りすぎた。間を空けて送り直す'], Glossary::http(403, 'rateLimitExceeded'));
        $this->assertSame('相手が権限なしで拒否', Glossary::http(403, 'probeUnknownReason')['short'] ?? null, '知らない reason なら状態の言葉');
    }

    /**
     * @return iterable<string, array{int, ?string}>
     */
    public static function exitCodes(): iterable
    {
        yield '1' => [1, '失敗を返した'];
        yield '2' => [2, '使い方の誤り'];
        yield '127' => [127, 'コマンドが無い'];
        yield '137' => [137, '強制終了（SIGKILL）'];
        yield '143' => [143, '終了の依頼（SIGTERM）'];
        yield '表に無いシグナル' => [129, 'シグナル 1'];
        yield '表に無いシグナルの上端' => [159, 'シグナル 31'];
        yield '128' => [128, null];
        yield '160' => [160, null];
        yield '0' => [0, null];
        yield '3' => [3, null];
        yield '255' => [255, null];
    }

    #[Test]
    #[DataProvider('exitCodes')]
    public function 終了コードは表とシグナルの番号だけ(int $code, ?string $short): void
    {
        $this->assertSame($short, Glossary::exitCode($code)['short'] ?? null);
    }

    #[Test]
    public function 強制終了は原因を断定しない(): void
    {
        $this->assertSame('外から強制終了された（メモリ不足で OS が止めた場合を含む）', Glossary::exitCode(137)['explanation'] ?? null);
        $this->assertSame('シグナル 1 で止まった', Glossary::exitCode(129)['explanation'] ?? null);
    }

    #[Test]
    public function クラスだけで決まる例外は_子のクラスを先に見る(): void
    {
        $this->assertSame('ジョブの時間切れ', Glossary::exception(new TimeoutExceededException('probe')));
        $this->assertSame('ジョブの試行回数の上限', Glossary::exception(new MaxAttemptsExceededException('probe')));
        $this->assertSame('0 で割った', Glossary::exception(new DivisionByZeroError('probe')));
        $this->assertSame('引数の数の誤り', Glossary::exception(new ArgumentCountError('probe')));
        $this->assertSame('match に当たる場合が無い', Glossary::exception(new UnhandledMatchError('Unhandled match case probe-secret')));
        $this->assertSame('受け付けない値', Glossary::exception(new ValueError('probe')));
        $this->assertSame('JSON の読み書きの失敗', Glossary::exception(new JsonException('probe')));
        $this->assertNull(Glossary::exception(new RuntimeException('probe')));
    }

    #[Test]
    public function HTTPの接続の失敗は_Guzzleの例外のクラスで言い分ける(): void
    {
        $request = new Psr7Request('GET', 'https://api.example.test/');

        $this->assertSame('接続の時間切れ', Glossary::connection(new ConnectTimeoutException('probe', $request)));
        $this->assertSame('応答の時間切れ', Glossary::connection(new ResponseTimeoutException('probe', $request, new Psr7Response(200))));
        $this->assertSame('接続できない', Glossary::connection(new ConnectException('probe', $request)));
        $this->assertSame('接続できない', Glossary::connection(null));
    }

    #[Test]
    public function 説明にASCIIの括弧とコロンを使わない(): void
    {
        $terms = [];
        foreach (['MYSQL', 'SQLSTATES', 'HTTP', 'GOOGLE_REASONS', 'EXIT_CODES'] as $table) {
            /** @var array<array-key, array{string, string}> $rows */
            $rows = new \ReflectionClassConstant(Glossary::class, $table)->getValue();
            foreach ($rows as $row) {
                $terms[] = $row[1];
            }
        }

        $this->assertNotEmpty($terms);
        foreach ($terms as $explanation) {
            $this->assertDoesNotMatchRegularExpression('/[():\[\]]/', $explanation, "絵文字の記号や記法に当たらない: {$explanation}");
        }
    }
}
