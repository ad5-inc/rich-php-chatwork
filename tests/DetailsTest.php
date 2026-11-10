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

        $this->assertSame(['SQLSTATE: 23000（ドライバーの番号 1062）', '接続: mysql'], Details::lines($e));
        $this->assertNoValues($e);
    }

    #[Test]
    public function 値が長すぎるときは列の名前も出す(): void
    {
        $e = $this->queryFailure('22001', 1406, "Data too long for column 'name' at row 1");

        $this->assertSame(['SQLSTATE: 22001（ドライバーの番号 1406）', '接続: mysql', '列: name'], Details::lines($e));
    }

    #[Test]
    public function 列の名前に名前の文字以外が混ざれば列を出さない(): void
    {
        $e = $this->queryFailure('22001', 1406, "Data too long for column 'name' 山田' at row 1");

        $this->assertSame(['SQLSTATE: 22001（ドライバーの番号 1406）', '接続: mysql'], Details::lines($e));
    }

    #[Test]
    public function DBにつながらないときは_PDOの文言の頭から番号だけを出す(): void
    {
        $e = $this->connectFailure("SQLSTATE[HY000] [1045] Access denied for user 'taro@example.com'@'10.0.0.1' (using password: YES)");

        $this->assertSame(['SQLSTATE: HY000（ドライバーの番号 1045）', '接続: mysql'], Details::lines($e));
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
        $this->assertNoValues($e);
    }

    #[Test]
    public function 引数の型の誤りは_関数と引数と型を出し_呼び出し元のパスは出さない(): void
    {
        $e = $this->typeError();

        $this->assertSame([
            '関数: ' . self::class . '::takesInt()',
            '引数: #1 $id',
            '求める型: int',
            '渡された型: string',
        ], Details::lines($e));
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

        $this->assertSame(['メモリ切れ（上限 128 MB）'], Details::lines($e));
        $this->assertSame([], Details::lines(new FatalError('Maximum execution time of 30 seconds exceeded', 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => 1])));
    }

    #[Test]
    public function スケジュールのコマンドの失敗は_名前と終了コードを出し_パスと引数は出さない(): void
    {
        $e = $this->plain("Scheduled command ['/home/deploy/php' 'artisan' report:daily --mail=taro@example.com] failed with exit code [1].");

        $this->assertSame(['スケジュールのコマンド: report:daily', '終了コード: 1'], Details::lines($e));
        $this->assertNoValues($e);
    }

    #[Test]
    public function コマンドの名前が名前の形でなければ_失敗と終了コードだけを出す(): void
    {
        $e = $this->plain("Scheduled command ['php' 'artisan' 'taro@example.com'] failed with exit code [2].");

        $this->assertSame(['スケジュールのコマンドの失敗', '終了コード: 2'], Details::lines($e));
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
        $this->assertNoValues($e);
    }

    #[Test]
    public function Httpクライアントの失敗は_HTTPの状態を出す(): void
    {
        $this->assertSame(['HTTP の状態: 503'], Details::lines(new RequestException(new Response(new Psr7Response(503)))));
    }

    #[Test]
    public function ほかの例外は何も出さない(): void
    {
        $this->assertSame([], Details::lines(new RuntimeException('taro@example.com')));
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
