<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\KnownMessage;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PlainTestCase;
use RuntimeException;

/**
 * 形の分かる例外の文言を、安全な部品だけで組み立て直す。元の文言は使わない
 */
final class KnownMessageTest extends PlainTestCase
{
    /** 例外に入れる値。組み立てた文言のどこにも出てはいけない */
    private const VALUES = ['probe-user', '10.0.0.1', 'db.internal', 'probe_db', 'probe-secret', 'probe-value'];

    #[Test]
    public function 値が長すぎるときは_PDOの文言をそのまま使いSQLを伏せる(): void
    {
        $e = $this->query('22001', 1406, 'String data, right truncated', "Data too long for column 'name' at row 1");

        $this->assertSame("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 (Connection: mysql, SQL: （ログ）)", KnownMessage::of($e));
    }

    /**
     * @return iterable<string, array{string, int, string, string, string}>
     */
    public static function allowedShapes(): iterable
    {
        yield '重複' => ['23000', 1062, 'Integrity constraint violation', "Duplicate entry 'probe-value' for key 'employees.code'", "Duplicate entry '（伏せた）' for key 'employees.code'"];
        yield '引用符を含む値' => ['23000', 1062, 'Integrity constraint violation', "Duplicate entry 'O'Probe-2026' for key 'employees.code'", "Duplicate entry '（伏せた）' for key 'employees.code'"];
        yield '値の形の誤り' => ['22007', 1292, 'Invalid datetime format', "Incorrect datetime value: 'probe-value' for column 'birthday' at row 1", "Incorrect datetime value: '（伏せた）' for column 'birthday' at row 1"];
        yield '切り詰め' => ['22007', 1292, 'Invalid datetime format', "Truncated incorrect DOUBLE value: 'probe-value'", "Truncated incorrect DOUBLE value: '（伏せた）'"];
        yield '列が無い' => ['42S22', 1054, 'Column not found', "Unknown column 'nme' in 'where clause'", "Unknown column 'nme' in 'where clause'"];
        yield '構文' => ['42000', 1064, 'Syntax error or access violation', "You have an error in your SQL syntax; check the manual for the right syntax to use near '= 'probe-secret'' at line 1", "You have an error in your SQL syntax; check the manual for the right syntax to use near '（伏せた）' at line 1"];
        yield '外部キー' => ['23000', 1452, 'Integrity constraint violation', 'Cannot add or update a child row: a foreign key constraint fails (`probe_db`.`scores`, CONSTRAINT `fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`))', 'Cannot add or update a child row: a foreign key constraint fails (（伏せた）)'];
        yield 'null' => ['23000', 1048, 'Integrity constraint violation', "Column 'name' cannot be null", "Column 'name' cannot be null"];
    }

    #[Test]
    #[DataProvider('allowedShapes')]
    public function 許可リストの形だけ_値を伏せて出す(string $state, int $driver, string $stateText, string $driverMessage, string $expected): void
    {
        $e = $this->query($state, $driver, $stateText, $driverMessage);

        $this->assertSame("SQLSTATE[{$state}]: {$stateText}: {$driver} {$expected} (Connection: mysql, SQL: （ログ）)", KnownMessage::of($e));
    }

    #[Test]
    public function 許可リストに無い形は_番号だけを出す(): void
    {
        $e = $this->query('42000', 1142, 'Syntax error or access violation', "SELECT command denied to user 'probe-user'@'10.0.0.1' for table 'secrets'");

        $message = KnownMessage::of($e);

        $this->assertSame('SQLSTATE[42000] ドライバー 1142（文言はログ） (Connection: mysql)', $message);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function connectionFailures(): iterable
    {
        yield '認証' => ['mysql', "SQLSTATE[HY000] [1045] Access denied for user 'probe-user'@'10.0.0.1' (using password: YES)", 'SQLSTATE[HY000] [1045] 認証に失敗（詳細はログ） (Connection: mysql)'];
        yield '名前が引けない' => ['mysql', 'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db.internal failed: Name or service not known', 'SQLSTATE[HY000] [2002] 名前が引けない（詳細はログ） (Connection: mysql)'];
        yield 'つながらない' => ['mysql', 'SQLSTATE[HY000] [2002] Connection refused', 'SQLSTATE[HY000] [2002] 接続できない（詳細はログ） (Connection: mysql)'];
        yield 'pgsql' => ['pgsql', 'SQLSTATE[08006] [7] connection to server at "db.internal" (10.0.0.1), port 5432 failed: FATAL:  password authentication failed for user "probe-user"', 'SQLSTATE[08006] [7] 接続できない（詳細はログ） (Connection: pgsql)'];
    }

    #[Test]
    #[DataProvider('connectionFailures')]
    public function 接続の失敗は_PDOの文言を使わず固定の言葉にする(string $connection, string $pdoMessage, string $expected): void
    {
        // 接続の失敗では errorInfo が無く、getCode() が番号になる
        $pdo = new PDOException($pdoMessage, 2002);
        $e = new QueryException($connection, 'select 1', [], $pdo, ['driver' => $connection, 'host' => self::VALUES[2], 'port' => 3306, 'database' => self::VALUES[3]]);

        $message = (string) KnownMessage::of($e);

        $this->assertSame($expected, $message);
        $this->assertNoValues($message);
    }

    #[Test]
    public function previousがPDOExceptionでなければ_文言を使わない(): void
    {
        $e = new QueryException('mysql', 'select 1', [], new RuntimeException('SQLSTATE[HY000] lost connection to probe-user@10.0.0.1'));

        $this->assertSame('SQLSTATE[HY000] （文言はログ） (Connection: mysql)', KnownMessage::of($e));
    }

    #[Test]
    public function HTTPクライアントの失敗は_状態とメソッドとホストと許可した段だけを出す(): void
    {
        $this->assertSame('HTTP 404 POST https://hooks.slack.example/services/{…}/{…}/{…}', KnownMessage::of($this->http(404, 'POST', 'https://hooks.slack.example/services/T0PROBE01/B0PROBE02/ProbeSecretProbeSecret12?x=1')));
        $this->assertSame('HTTP 404 POST https://discord.com/api/webhooks/{…}/{…}', KnownMessage::of($this->http(404, 'POST', 'https://discord.com/api/webhooks/123456789012345678/Probe-Secret_Probe-Secret_Probe-Secret_Probe-Secret_Probe-Secret12')));
        $this->assertSame('HTTP 401 POST https://api.telegram.org/{…}/{…}', KnownMessage::of($this->http(401, 'POST', 'https://api.telegram.org/bot123456:PROBE-probe/sendMessage')));
        $this->assertSame('HTTP 401 GET https://api.example.test/v1/token', KnownMessage::of($this->http(401, 'GET', 'https://api.example.test/v1/token')));
        $this->assertSame('HTTP 500 GET https://api.example.test/v1/users/{…}/messages', KnownMessage::of($this->http(500, 'GET', 'https://user:pass@api.example.test/v1/users/42/messages')));
    }

    #[Test]
    public function URLが取れなければ_状態だけを出す(): void
    {
        $this->assertSame('HTTP 503', KnownMessage::of(new RequestException(new Response(new Psr7Response(503, [], '{"error":"probe-secret"}')))));
    }

    #[Test]
    public function HTTPの接続失敗は_ホストだけを出す(): void
    {
        $guzzle = new ConnectException('cURL error 6: Could not resolve host: probe-user.example.test', new Psr7Request('GET', 'https://api.example.test/v1/users?token=probe-secret'));

        $this->assertSame('接続できない: https://api.example.test', KnownMessage::of(new ConnectionException($guzzle->getMessage(), 0, $guzzle)));
        $this->assertSame('接続できない', KnownMessage::of(new ConnectionException('cURL error 6: probe-user')));
    }

    #[Test]
    public function ほかの例外は組み立てない(): void
    {
        $this->assertNull(KnownMessage::of(new RuntimeException('probe')));
    }

    private function assertNoValues(string $message): void
    {
        foreach (self::VALUES as $value) {
            $this->assertStringNotContainsString($value, $message);
        }
    }

    private function query(string $state, int $driver, string $stateText, string $driverMessage): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$state}]: {$stateText}: {$driver} {$driverMessage}");
        $pdo->errorInfo = [$state, $driver, $driverMessage];

        return new QueryException('mysql', 'update employees set name = ? where id = ?', [self::VALUES[5], 1], $pdo, ['driver' => 'mysql', 'host' => self::VALUES[2], 'port' => 3306, 'database' => self::VALUES[3]]);
    }

    private function http(int $status, string $method, string $uri): RequestException
    {
        $response = new Response(new Psr7Response($status, [], '{"error":"' . self::VALUES[4] . '"}'));
        $response->transferStats = new TransferStats(new Psr7Request($method, $uri), $response->toPsrResponse());

        return new RequestException($response);
    }
}
