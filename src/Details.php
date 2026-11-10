<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use ErrorException;
use Exception;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use PDOException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;
use TypeError;

/**
 * 例外の種類ごとに、値を含まない決まった部分だけを取り出した行
 *
 * 例外の文言は送らない。DB の例外は値を埋めた SQL を、メールの失敗は宛先を含むなど、個人情報が入りうるため。
 * 例外の項目（errorInfo・getSeverity() など）から取れるものはそこから取り、文言しかないものは次の形でだけ読む。
 *
 * - 種類ごとに 1 つの正規表現を、文言の先頭から末尾まで（\A〜\z）に当てる
 * - 取り出す部分は名前の文字だけ。形が合わない・文字が条件を満たさないときは、その行を出さない
 * - 文言や文言の一部をそのまま出す逃げ道は作らない
 *
 * PHP や Laravel の版で文言の形が変わると、その種類の行が出なくなるだけで、値は出ない
 */
final class Details
{
    /** SQLSTATE の 5 文字 */
    private const SQLSTATE = '/\A[0-9A-Z]{5}\z/';

    /** 接続の名前 */
    private const CONNECTION = '/\A[A-Za-z0-9_.\-]{1,64}\z/';

    /** PDO が接続のときに作る文言（errorInfo が無い）。番号の後ろはドライバーの文言で、読まない */
    private const PDO_CONNECT = '/\ASQLSTATE\[([0-9A-Z]{5})\] \[(\d{1,5})\] .*\z/s';

    /** MySQL の「値が長すぎる」（errorInfo[2]） */
    private const DATA_TOO_LONG = "/\\AData too long for column '([A-Za-z0-9_]{1,64})' at row \\d{1,10}\\z/";

    /** PHP が作る引数の型の誤り。called in の後ろ（パス）は読まない */
    private const TYPE_ERROR = '/\A([A-Za-z0-9_\\\\:]{1,200})\(\): Argument #(\d{1,2}) \(\$([A-Za-z0-9_]{1,64})\) must be of type ([A-Za-z0-9_\\\\?|]{1,200}), ([A-Za-z0-9_\\\\?|]{1,200}) given(?:, called in .+ on line \d{1,10})?\z/s';

    /** PHP が作るメモリ切れ */
    private const MEMORY = '/\AAllowed memory size of (\d{1,20}) bytes exhausted(?: \(tried to allocate \d{1,20} bytes\))?\z/';

    /** Laravel のスケジュール（schedule:run）が作るコマンドの失敗 */
    private const SCHEDULE = '/\AScheduled command \[(.{1,1000})\] failed with exit code \[(\d{1,3})\]\.\z/s';

    /** artisan のコマンドの名前 */
    private const COMMAND = '/\A[A-Za-z0-9_:\-]{1,100}\z/';

    /** Google の reason（notFound・forbidden など決まった語） */
    private const GOOGLE_REASON = '/\A[A-Za-z_]{1,50}\z/';

    private const SEVERITIES = [
        E_ERROR => 'エラー',
        E_WARNING => '警告',
        E_NOTICE => '通知',
        E_DEPRECATED => '非推奨',
        E_USER_ERROR => 'エラー（アプリ）',
        E_USER_WARNING => '警告（アプリ）',
        E_USER_NOTICE => '通知（アプリ）',
        E_USER_DEPRECATED => '非推奨（アプリ）',
    ];

    /**
     * @return list<string>
     */
    public static function lines(Throwable $e): array
    {
        return match (true) {
            $e instanceof QueryException => self::query($e),
            $e instanceof ErrorException => self::severity($e),
            $e instanceof TypeError => self::typeError($e),
            $e instanceof FatalError => self::fatal($e),
            $e::class === Exception::class => self::schedule($e),
            default => self::status($e),
        };
    }

    /**
     * 外の API の失敗で、HTTP の状態と Google の返した reason の行
     *
     * @return list<string>
     */
    public static function status(Throwable $e): array
    {
        $status = match (true) {
            $e instanceof GoogleServiceException => $e->getCode(),
            $e instanceof RequestException => $e->response->status(),
            default => null,
        };
        $lines = in_array($status, [null, 0], true) ? [] : ["HTTP の状態: {$status}"];

        if ($e instanceof GoogleServiceException) {
            $reasons = collect($e->getErrors() ?? [])
                ->map(static fn (array $error): mixed => $error['reason'] ?? null)
                ->filter(static fn (mixed $reason): bool => is_string($reason) && preg_match(self::GOOGLE_REASON, $reason) === 1)
                ->unique()
                ->implode(', ');
            if ($reasons !== '') {
                $lines[] = "Google の reason: {$reasons}";
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function query(QueryException $e): array
    {
        [$state, $driver] = self::sqlState($e);
        $lines = [];

        if ($state !== null) {
            $lines[] = "SQLSTATE: {$state}" . ($driver === null ? '' : "（ドライバーの番号 {$driver}）");
        }

        $connection = $e->getConnectionName();
        if (preg_match(self::CONNECTION, $connection) === 1) {
            $lines[] = "接続: {$connection}";
        }

        $driverMessage = $e->errorInfo[2] ?? null;
        if ($state === '22001' && is_string($driverMessage) && preg_match(self::DATA_TOO_LONG, $driverMessage, $match) === 1) {
            $lines[] = "列: {$match[1]}";
        }

        return $lines;
    }

    /**
     * 問い合わせの失敗は errorInfo から、接続の失敗（errorInfo が無い）は PDO の文言の頭から取る
     *
     * @return array{?string, ?int}
     */
    private static function sqlState(QueryException $e): array
    {
        $state = $e->errorInfo[0] ?? null;
        $driver = $e->errorInfo[1] ?? null;

        if (is_string($state) && preg_match(self::SQLSTATE, $state) === 1) {
            return [$state, is_int($driver) ? $driver : null];
        }

        $previous = $e->getPrevious();
        if ($previous instanceof PDOException && preg_match(self::PDO_CONNECT, $previous->getMessage(), $match) === 1) {
            return [$match[1], (int) $match[2]];
        }

        return [null, null];
    }

    /**
     * @return list<string>
     */
    private static function severity(ErrorException $e): array
    {
        $severity = $e->getSeverity();

        return ['重さ: ' . (self::SEVERITIES[$severity] ?? "その他（{$severity}）")];
    }

    /**
     * @return list<string>
     */
    private static function typeError(TypeError $e): array
    {
        if (preg_match(self::TYPE_ERROR, $e->getMessage(), $match) !== 1) {
            return [];
        }

        return ["関数: {$match[1]}()", "引数: #{$match[2]} \${$match[3]}", "求める型: {$match[4]}", "渡された型: {$match[5]}"];
    }

    /**
     * @return list<string>
     */
    private static function fatal(FatalError $e): array
    {
        if (preg_match(self::MEMORY, $e->getMessage(), $match) !== 1) {
            return [];
        }

        return ['メモリ切れ（上限 ' . intdiv((int) $match[1], 1024 * 1024) . ' MB）'];
    }

    /**
     * コマンドの文字は「'/path/to/php' 'artisan' report:daily --option」の形。artisan の次の語だけを取る
     *
     * @return list<string>
     */
    private static function schedule(Exception $e): array
    {
        if (preg_match(self::SCHEDULE, $e->getMessage(), $match) !== 1) {
            return [];
        }

        $words = array_map(static fn (string $word): string => trim($word, "'\""), preg_split('/\s+/', $match[1]) ?: []);
        $artisan = array_find_key($words, static fn (string $word): bool => $word === 'artisan' || str_ends_with($word, '/artisan'));
        $name = is_int($artisan) ? ($words[$artisan + 1] ?? null) : null;

        return [
            is_string($name) && preg_match(self::COMMAND, $name) === 1 ? "スケジュールのコマンド: {$name}" : 'スケジュールのコマンドの失敗',
            "終了コード: {$match[2]}",
        ];
    }
}
