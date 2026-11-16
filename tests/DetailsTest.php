<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Details;
use Ad5\RichPhpChatwork\Reporter;
use ErrorException;
use Exception;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use TypeError;

/**
 * 例外の種類ごとの「中間」。決まった部分は出し、値（人・アドレス・SQL の値・パス・ID）は出さない。形が変わったら何も出さない
 */
final class DetailsTest extends TestCase
{
    /** 例外の文言に混ぜる値。本文のどこにも出てはいけない */
    private const VALUES = ['taro@example.com', '山田', 'p@ssw0rd', '/home/deploy', '987654321'];

    #[Test]
    public function DBの問い合わせの失敗は_SQLSTATEと番号と接続を出し_SQLの値は出さない(): void
    {
        $e = $this->queryFailure('23000', 1062, "Duplicate entry 'taro@example.com' for key 'users.email'");

        $this->assertSame(['SQLSTATE: 23000', 'ドライバーの番号: 1062', '接続: mysql'], Details::lines($e));
        $this->assertSame('重複・SQLSTATE 23000', Details::of($e)->summary);
        $this->assertNoValues($e);
    }

    #[Test]
    public function 値が長すぎるときは列の名前も出す(): void
    {
        $e = $this->queryFailure('22001', 1406, "Data too long for column 'name' at row 1");

        $this->assertSame(['SQLSTATE: 22001', 'ドライバーの番号: 1406', '接続: mysql', '列: name'], Details::lines($e));
        $this->assertSame('文字が長すぎる・列 name・SQLSTATE 22001', Details::of($e)->summary);
    }

    #[Test]
    public function 列の名前に名前の文字以外が混ざれば列を出さない(): void
    {
        $e = $this->queryFailure('22001', 1406, "Data too long for column 'name' 山田' at row 1");

        $this->assertSame(['SQLSTATE: 22001', 'ドライバーの番号: 1406', '接続: mysql'], Details::lines($e));
    }

    #[Test]
    public function DBにつながらないときは_PDOの文言の頭から番号だけを出す(): void
    {
        $e = $this->connectFailure("SQLSTATE[HY000] [1045] Access denied for user 'taro@example.com'@'10.0.0.1' (using password: YES)");

        $this->assertSame(['SQLSTATE: HY000', 'ドライバーの番号: 1045', '接続: mysql'], Details::lines($e));
        $this->assertSame('認証に失敗・SQLSTATE HY000', Details::of($e)->summary);
        $this->assertNoValues($e);
    }

    #[Test]
    public function DBの文言の形が違えば_SQLSTATEを出さない(): void
    {
        $e = $this->connectFailure('Access denied for taro@example.com');

        $this->assertSame(['接続: mysql'], Details::lines($e));
    }

    #[Test]
    public function PHPの警告は重さだけを出し_文言のキーは出さない(): void
    {
        $e = $this->warning('Undefined array key "taro@example.com"');

        $this->assertSame(['重さ: 警告'], Details::lines($e));
        $this->assertSame('PHP の警告（E_WARNING）', Details::of($e)->summary);
        $this->assertNoValues($e);
    }

    #[Test]
    public function 引数の型の誤りは_関数と引数と型を出し_呼び出し元のパスは出さない(): void
    {
        $e = $this->typeError();

        $this->assertSame([
            '関数: ' . self::class . '::takesInt()',
            '引数の位置: #1',
            '引数の名前: $id',
            '求める型: int',
            '渡された型: string',
        ], Details::lines($e));
        $this->assertSame('DetailsTest::takesInt() 引数 #1 int←string', Details::of($e)->summary);
        $this->assertStringContainsString('called in', $e->getMessage());
        $this->assertStringNotContainsString(dirname(__DIR__), implode("\n", Details::lines($e)));
    }

    #[Test]
    public function 型の誤りの文言の形が違えば何も出さない(): void
    {
        $this->assertSame([], Details::lines(new TypeError('Cannot assign string to property taro@example.com')));
        $this->assertSame([], Details::lines(new TypeError('f(): Argument #1 ($id) must be of type int, class@anonymous/home/deploy given')));
    }

    #[Test]
    public function メモリ切れは上限だけを出す(): void
    {
        $e = new FatalError('Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => 1]);

        $this->assertSame(['メモリの上限: 128 MB'], Details::lines($e));
        $this->assertSame('メモリ切れ（上限 128 MB）', Details::of($e)->summary);
    }

    #[Test]
    public function スケジュールのコマンドの失敗は_件名に名前と終了コードを出し_文言の引数のメールは伏せる(): void
    {
        $e = $this->plain("Scheduled command ['/home/deploy/php' 'artisan' report:daily --mail=taro@example.com] failed with exit code [1].");

        $this->assertSame(['スケジュールのコマンド: report:daily', '終了コード: 1'], Details::lines($e));
        $this->assertSame('スケジュール report:daily・失敗を返した・終了コード 1', Details::of($e)->summary);
        $body = $this->app?->make(Reporter::class)->body($e) ?? '';
        $this->assertStringContainsString("[code]Scheduled command ['/home/deploy/php' 'artisan' report:daily --mail=（伏せた）] failed with exit code [1].[/code]", $body);
        $this->assertStringNotContainsString('taro@example.com', $body);
    }

    #[Test]
    public function コマンドの名前が名前の形でなければ_失敗と終了コードだけを出す(): void
    {
        $e = $this->plain("Scheduled command ['php' 'artisan' 'taro@example.com'] failed with exit code [2].");

        $this->assertSame(['終了コード: 2'], Details::lines($e));
        $this->assertSame('スケジュールのコマンドの失敗・使い方の誤り・終了コード 2', Details::of($e)->summary);
    }

    #[Test]
    public function 素のExceptionでスケジュールの形でなければ何も出さない(): void
    {
        $this->assertSame([], Details::lines(new Exception('taro@example.com に送れなかった')));
    }

    #[Test]
    public function Googleの失敗は_HTTPの状態と英字のreasonだけを出す(): void
    {
        $e = $this->google(403, [
            ['reason' => 'forbidden', 'message' => 'taro@example.com'],
            ['reason' => 'forbidden'],
            ['reason' => 'taro@example.com'],
            ['domain' => 'global'],
        ]);

        $this->assertSame(['HTTP の状態: 403', 'Google の reason: forbidden'], Details::lines($e));
        $this->assertSame('拒否・reason forbidden・HTTP 403', Details::of($e)->summary);
        $this->assertNoValues($e);
    }

    #[Test]
    public function Httpクライアントの失敗は_HTTPの状態を出す(): void
    {
        $this->assertSame(['HTTP の状態: 503'], Details::lines(new RequestException(new Response(new Psr7Response(503)))));
        $this->assertSame('相手が一時停止・HTTP 503', Details::of(new RequestException(new Response(new Psr7Response(503))))->summary);
        $this->assertSame('接続できない', Details::of(new ConnectionException('probe'))->summary);
    }

    #[Test]
    public function DBの例外は_番号の表の言葉を件名の頭に置き_説明を添える(): void
    {
        $e = $this->queryFailure('22003', 1264, "Out of range value for column 'score' at row 1");

        $this->assertSame('数値が範囲外・SQLSTATE 22003', Details::of($e)->summary);
        $this->assertSame('列の型に収まらない数値を入れようとした。列の型か入れた値を見る', Details::of($e)->explanation);
    }

    #[Test]
    public function MySQLでない接続では_番号の表を引かずSQLSTATEの表だけを見る(): void
    {
        $pdo = new PDOException('SQLSTATE[23000]: probe');
        $pdo->errorInfo = ['23000', 1062, 'probe'];
        $e = new QueryException('probe', 'insert into t values (?)', [], $pdo, ['driver' => 'sqlsrv']);

        $this->assertSame('制約に反した・SQLSTATE 23000', Details::of($e)->summary);
    }

    #[Test]
    public function 表に無いSQLSTATEには何も足さない(): void
    {
        $e = $this->queryFailure('HY000', 1105, 'probe');

        $this->assertSame('SQLSTATE HY000', Details::of($e)->summary);
        $this->assertNull(Details::of($e)->explanation);
    }

    #[Test]
    public function 接続の失敗は言い分け_2006は接続が切れたと言う(): void
    {
        $this->assertSame('接続が切れた・SQLSTATE HY000', Details::of($this->connectFailure('SQLSTATE[HY000] [2006] MySQL server has gone away'))->summary);
        $this->assertSame('DB との接続が切れていた', Details::of($this->connectFailure('SQLSTATE[HY000] [2006] MySQL server has gone away'))->explanation);
        $this->assertSame('名前が引けない・SQLSTATE HY000', Details::of($this->connectFailure('SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db.internal failed'))->summary);
        $this->assertSame('接続できない・SQLSTATE HY000', Details::of($this->connectFailure('SQLSTATE[HY000] [2002] Connection refused'))->summary);
    }

    #[Test]
    public function 実行時間の上限は秒数だけを出す(): void
    {
        $e = new FatalError('Maximum execution time of 30 seconds exceeded', 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => 1]);

        $this->assertSame('実行時間の上限（30 秒）', Details::of($e)->summary);
        $this->assertSame([], Details::lines(new FatalError('Maximum execution time of 30 seconds exceeded in /home/deploy', 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => 1])));
    }

    #[Test]
    public function nullのメソッド呼び出しはメソッドの名前だけを出す(): void
    {
        $this->assertSame('null の save() を呼んだ', Details::of(new \Error('Call to a member function save() on null'))->summary);
        $this->assertNull(Details::of(new \Error('Call to a member function save() on string'))->summary);
        $this->assertNull(Details::of(new \Error('Call to a member function taro@example.com() on null'))->summary);
    }

    #[Test]
    public function クラスで決まる例外は文言を読まずに短い言葉を出す(): void
    {
        $this->assertSame('引数の数の誤り', Details::of(new \ArgumentCountError('Too few arguments to function probe(), 0 passed in /home/deploy/x.php'))->summary);
        $this->assertSame('match に当たる場合が無い', Details::of(new \UnhandledMatchError('Unhandled match case \'taro@example.com\''))->summary);
        $this->assertSame('ジョブの時間切れ', Details::of(new \Illuminate\Queue\TimeoutExceededException('App\Jobs\X has timed out.'))->summary);
    }

    #[Test]
    public function HTTPの接続の失敗は時間切れを言い分ける(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.test/');

        $this->assertSame('接続の時間切れ', Details::of(new ConnectionException('probe', 0, new \GuzzleHttp\Exception\ConnectTimeoutException('probe', $request)))->summary);
    }

    #[Test]
    public function Googleの失敗は_最初のreasonの言葉を状態より先に取る(): void
    {
        $e = $this->google(403, [['reason' => 'rateLimitExceeded'], ['reason' => 'forbidden']]);

        $this->assertSame('回数の上限・reason rateLimitExceeded, forbidden・HTTP 403', Details::of($e)->summary);
        $this->assertSame('短い時間に送りすぎた。間を空けて送り直す', Details::of($e)->explanation);
    }

    #[Test]
    public function 表に無いHTTPの状態には何も足さない(): void
    {
        $kind = Details::of(new RequestException(new Response(new Psr7Response(418))));

        $this->assertSame('HTTP 418', $kind->summary);
        $this->assertNull($kind->explanation);
    }

    #[Test]
    public function スケジュールの終了コードがシグナルなら_シグナルの番号だけを出す(): void
    {
        $kind = Details::of($this->plain("Scheduled command ['php' 'artisan' report:daily] failed with exit code [137]."));

        $this->assertSame('スケジュール report:daily・強制終了（SIGKILL）・終了コード 137', $kind->summary);
        $this->assertSame('外から強制終了された（メモリ不足で OS が止めた場合を含む）', $kind->explanation);
        $this->assertSame('スケジュール report:daily・シグナル 3・終了コード 131', Details::of($this->plain("Scheduled command ['php' 'artisan' report:daily] failed with exit code [131]."))->summary);
    }

    #[Test]
    public function ほかの例外は何も出さない(): void
    {
        $this->assertSame([], Details::lines(new RuntimeException('taro@example.com')));
        $this->assertNull(Details::of(new RuntimeException('taro@example.com'))->summary);
    }

    /**
     * 本文全体（件名・中間・前後の行・道筋）に値が出ないこと
     */
    private function assertNoValues(\Throwable $e): void
    {
        $body = $this->app?->make(Reporter::class)->body($e) ?? '';

        foreach (self::VALUES as $value) {
            $this->assertStringNotContainsString($value, $body);
        }
    }

    /*
     * ここから下は例外を作る補助。本文に添える前後 3 行に値の文字が入らないよう、値は引数で渡して離れた行で作る
     */

    private function queryFailure(string $state, int $code, string $driverMessage): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$state}]: {$driverMessage}");
        $pdo->errorInfo = [$state, $code, $driverMessage];

        return $this->queryWith($pdo);
    }

    private function connectFailure(string $message): QueryException
    {
        return $this->queryWith(new PDOException($message));
    }

    private function queryWith(PDOException $pdo): QueryException
    {
        $binding = self::VALUES[0];

        return new QueryException('mysql', 'insert into users (email) values (?)', [$binding], $pdo);
    }

    private function plain(string $message): Exception
    {
        return new Exception($message);
    }

    /**
     * @param array<array<string, string>> $errors
     */
    private function google(int $code, array $errors): GoogleServiceException
    {
        return new GoogleServiceException('{"error":{"message":"' . self::VALUES[0] . '"}}', $code, null, $errors);
    }

    private function warning(string $message): ErrorException
    {
        return new ErrorException($message, 0, E_WARNING, __FILE__, __LINE__);
    }

    private function typeError(): TypeError
    {
        try {
            /** @phpstan-ignore argument.type (型の誤りを起こすのが目的) */
            $this->assertSame(0, self::takesInt(self::VALUES[4]));
        } catch (TypeError $e) {
            return $e;
        }

        $this->fail('型の誤りが起きなかった');
    }

    private static function takesInt(int $id): int
    {
        return $id;
    }
}
