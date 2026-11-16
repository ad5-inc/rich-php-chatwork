<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Error;
use ErrorException;
use Exception;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use PDOException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;
use TypeError;

/**
 * 例外の種類ごとに、値を含まない決まった部分だけを取り出す。件名の要約・「種類」の行・「説明」の行（Glossary の表）はここから作る
 *
 * 例外の項目（errorInfo・getSeverity() など）から取れるものはそこから取り、文言しかないものは次の形でだけ読む。
 *
 * - 種類ごとに 1 つの正規表現を、文言の先頭から末尾まで（\A〜\z）に当てる
 * - 取り出す部分は名前の文字だけ。形が合わない・文字が条件を満たさないときは、その項目を出さない
 * - 文言や文言の一部をそのまま出す逃げ道は作らない
 *
 * PHP や Laravel の版で文言の形が変わると、その種類の項目が出なくなるだけで、値は出ない
 */
final class Details
{
    /** SQLSTATE の 5 文字 */
    private const SQLSTATE = '/\A[0-9A-Z]{5}\z/';

    /** 接続の名前 */
    public const CONNECTION = '/\A[A-Za-z0-9_.\-]{1,64}\z/';

    /** errorInfo が無いとき（接続の失敗など）の、文言の頭の SQLSTATE と番号。後ろはドライバーの文言で、読まない */
    private const PDO_HEAD = '/\ASQLSTATE\[([0-9A-Z]{5})\](?: \[(\d{1,5})\])?/';

    /** MySQL の「値が長すぎる」（errorInfo[2]） */
    private const DATA_TOO_LONG = "/\\AData too long for column '([A-Za-z0-9_]{1,64})' at row \\d{1,10}\\z/";

    /** PHP が作る引数の型の誤り。called in の後ろ（パス）は読まない */
    private const TYPE_ERROR = '/\A([A-Za-z0-9_\\\\:]{1,200})\(\): Argument #(\d{1,2}) \(\$([A-Za-z0-9_]{1,64})\) must be of type ([A-Za-z0-9_\\\\?|]{1,200}), ([A-Za-z0-9_\\\\?|]{1,200}) given(?:, called in .+ on line \d{1,10})?\z/s';

    /** PHP が作るメモリ切れ */
    private const MEMORY = '/\AAllowed memory size of (\d{1,20}) bytes exhausted(?: \(tried to allocate \d{1,20} bytes\))?\z/';

    /** PHP が作る実行時間の上限 */
    private const EXECUTION_TIME = '/\AMaximum execution time of (\d{1,10}) seconds? exceeded\z/';

    /** PHP が作る null のメソッド呼び出し。メソッドの名前だけを取る */
    private const CALL_ON_NULL = '/\ACall to a member function ([A-Za-z0-9_]{1,64})\(\) on null\z/';

    /** 番号の表（Glossary）と SQL の形（SqlShape）を使うドライバー */
    private const MYSQL_DRIVERS = ['mysql', 'mariadb'];

    /** Laravel のスケジュール（schedule:run）が作るコマンドの失敗 */
    private const SCHEDULE = '/\AScheduled command \[(.{1,1000})\] failed with exit code \[(\d{1,3})\]\.\z/s';

    /** 接続の失敗として、ドライバーの文言を使わず固定の言葉にする番号（HY000 のとき） */
    public const CONNECTION_FAILURE_DRIVERS = [2002, 2006, 1045];

    /**
     * 間引きでアプリの行を外す（どこで起きても同じ障害とみなす）番号。2006（接続が切れた）は長い問い合わせなど
     * 特定のコードが原因のことがあるので、場所ごとに分けて数える
     */
    public const OUTAGE_DRIVERS = [2002, 1045];

    /** artisan のコマンドの名前 */
    public const COMMAND = '/\A[A-Za-z0-9_:\-]{1,100}\z/';

    /** Google の reason（notFound・forbidden など決まった語） */
    private const GOOGLE_REASON = '/\A[A-Za-z_]{1,50}\z/';

    /** @var array<int, array{string, string}> 重さ => [日本語, 定数の名前] */
    private const SEVERITIES = [
        E_ERROR => ['エラー', 'E_ERROR'],
        E_WARNING => ['警告', 'E_WARNING'],
        E_NOTICE => ['通知', 'E_NOTICE'],
        E_DEPRECATED => ['非推奨', 'E_DEPRECATED'],
        E_USER_ERROR => ['エラー', 'E_USER_ERROR'],
        E_USER_WARNING => ['警告', 'E_USER_WARNING'],
        E_USER_NOTICE => ['通知', 'E_USER_NOTICE'],
        E_USER_DEPRECATED => ['非推奨', 'E_USER_DEPRECATED'],
    ];

    public static function of(Throwable $e): Kind
    {
        $process = ProcessFailure::of($e);
        if ($process !== null) {
            return new Kind($process->summary, $process->items, $process->explanation);
        }

        // クラスだけで決まる物を先に見る（ArgumentCountError は TypeError の子なので、下の TypeError より先）
        $short = Glossary::exception($e);
        if ($short !== null) {
            return new Kind($short, []);
        }

        return match (true) {
            $e instanceof QueryException => self::query($e),
            $e instanceof ErrorException => self::severity($e),
            $e instanceof TypeError => self::typeError($e),
            $e instanceof FatalError => self::fatal($e),
            $e instanceof ConnectionException => new Kind(Glossary::connection($e->getPrevious()), []),
            $e::class === Error::class => self::error($e),
            $e::class === Exception::class => self::schedule($e),
            default => self::status($e),
        };
    }

    /**
     * 「ラベル: 値」の行（件名に入れた項目も含む）
     *
     * @return list<string>
     */
    public static function lines(Throwable $e): array
    {
        return array_map(static fn (array $item): string => "{$item['label']}: {$item['value']}", self::of($e)->items);
    }

    /**
     * 問い合わせの失敗は errorInfo から、無ければ（接続の失敗など）PDO の文言の頭から取る。接続の失敗では getCode() が番号になるので使わない
     *
     * @return array{?string, ?int}
     */
    public static function sqlState(QueryException $e): array
    {
        $previous = $e->getPrevious();
        $info = $previous instanceof PDOException ? $previous->errorInfo : $e->errorInfo;
        $state = $info[0] ?? null;
        $driver = $info[1] ?? null;

        if (is_string($state) && preg_match(self::SQLSTATE, $state) === 1) {
            return [$state, is_int($driver) ? $driver : null];
        }

        if ($previous !== null && preg_match(self::PDO_HEAD, $previous->getMessage(), $match) === 1) {
            return [$match[1], isset($match[2]) ? (int) $match[2] : null];
        }

        return [null, null];
    }

    /**
     * DB につながらない（SQLSTATE 08xxx、または HY000 でドライバー 2002・2006・1045）
     */
    public static function isConnectionFailure(?string $state, ?int $driver): bool
    {
        return $state !== null && (str_starts_with($state, '08') || ($state === 'HY000' && in_array($driver, self::CONNECTION_FAILURE_DRIVERS, true)));
    }

    /**
     * DB の接続断（間引きのキーからアプリの行を外す）
     */
    public static function isOutage(?string $state, ?int $driver): bool
    {
        return $state !== null && (str_starts_with($state, '08') || ($state === 'HY000' && in_array($driver, self::OUTAGE_DRIVERS, true)));
    }

    /**
     * 接続のドライバー（mysql・mariadb・pgsql など）。Laravel が例外に添えた接続の詳細から取り、無ければ設定（database.connections.{接続名}.driver）を見る。
     * DB へは問い合わせない
     */
    public static function driver(QueryException $e): ?string
    {
        return self::connectionSetting($e, 'driver');
    }

    /**
     * 接続の設定のデータベース名（SQL の形で {db} に替える）。取り方は driver() と同じ
     */
    public static function database(QueryException $e): ?string
    {
        return self::connectionSetting($e, 'database');
    }

    /**
     * MySQL・MariaDB の接続か
     */
    public static function isMysql(QueryException $e): bool
    {
        return in_array(self::driver($e), self::MYSQL_DRIVERS, true);
    }

    public static function status(Throwable $e): Kind
    {
        $status = match (true) {
            $e instanceof GoogleServiceException => $e->getCode(),
            $e instanceof RequestException => $e->response->status(),
            // Laravel の Http を通さない素の Guzzle の失敗（4xx・5xx の応答）。Guzzle が無い環境では当たらない
            class_exists(BadResponseException::class) && $e instanceof BadResponseException => $e->getResponse()->getStatusCode(),
            default => null,
        };
        if (in_array($status, [null, 0], true)) {
            return new Kind(null, []);
        }

        $items = [self::item('HTTP の状態', (string) $status, true)];
        $reasons = [];

        if ($e instanceof GoogleServiceException) {
            /** @var list<string> $reasons */
            $reasons = collect($e->getErrors() ?? [])
                ->map(static fn (array $error): mixed => $error['reason'] ?? null)
                ->filter(static fn (mixed $reason): bool => is_string($reason) && preg_match(self::GOOGLE_REASON, $reason) === 1)
                ->unique()
                ->values()
                ->all();
            if ($reasons !== []) {
                $items[] = self::item('Google の reason', implode(', ', $reasons), true);
            }
        }

        // reason が複数でも、言葉は最初の 1 つから引く
        $term = Glossary::http($status, $reasons[0] ?? null);
        $summary = self::join($term['short'] ?? null, $reasons === [] ? null : 'reason ' . implode(', ', $reasons), "HTTP {$status}");

        return new Kind($summary, $items, $term['explanation'] ?? null);
    }

    private static function query(QueryException $e): Kind
    {
        [$state, $driver] = self::sqlState($e);
        $items = [];

        if ($state !== null) {
            $items[] = self::item('SQLSTATE', $state, true);
        }
        if ($driver !== null) {
            $items[] = self::item('ドライバーの番号', (string) $driver, false);
        }

        $connection = $e->getConnectionName();
        if (preg_match(self::CONNECTION, $connection) === 1) {
            $items[] = self::item('接続', $connection, false);
        }

        $previous = $e->getPrevious();
        $driverMessage = $previous instanceof PDOException ? ($previous->errorInfo[2] ?? null) : null;
        $column = null;
        if ($state === '22001' && is_string($driverMessage) && preg_match(self::DATA_TOO_LONG, $driverMessage, $match) === 1) {
            $column = $match[1];
            $items[] = self::item('列', $column, true);
        }

        if ($state === null) {
            return new Kind(null, $items);
        }

        $term = Glossary::database($state, $driver, self::isMysql($e));
        $short = $term['short'] ?? null;
        if (self::isConnectionFailure($state, $driver)) {
            $words = KnownMessage::connectionWords($driver, $previous?->getMessage() ?? '');
            // 「名前が引けない」は表の「接続できない」（2002）より細かいので、そちらを取る
            $short = $words === KnownMessage::NAME_LOOKUP_WORDS ? $words : ($short ?? $words);
        }

        return new Kind(self::join($short, $column === null ? null : "列 {$column}", "SQLSTATE {$state}"), $items, $term['explanation'] ?? null);
    }

    private static function severity(ErrorException $e): Kind
    {
        $severity = $e->getSeverity();
        [$name, $constant] = self::SEVERITIES[$severity] ?? ['その他', (string) $severity];

        return new Kind("PHP の{$name}（{$constant}）", [self::item('重さ', $name, true)]);
    }

    private static function typeError(TypeError $e): Kind
    {
        if (preg_match(self::TYPE_ERROR, $e->getMessage(), $match) !== 1) {
            return new Kind(null, []);
        }

        $function = $match[1];
        $short = substr($function, (int) strrpos('\\' . $function, '\\'));

        return new Kind("{$short}() 引数 #{$match[2]} {$match[4]}←{$match[5]}", [
            self::item('関数', "{$function}()", true),
            self::item('引数の位置', "#{$match[2]}", true),
            self::item('引数の名前', "\${$match[3]}", false),
            self::item('求める型', $match[4], true),
            self::item('渡された型', $match[5], true),
        ]);
    }

    private static function fatal(FatalError $e): Kind
    {
        if (preg_match(self::EXECUTION_TIME, $e->getMessage(), $match) === 1) {
            return new Kind("実行時間の上限（{$match[1]} 秒）", [self::item('実行時間の上限', "{$match[1]} 秒", true)]);
        }
        if (preg_match(self::MEMORY, $e->getMessage(), $match) !== 1) {
            return new Kind(null, []);
        }

        $megabytes = intdiv((int) $match[1], 1024 * 1024);

        return new Kind("メモリ切れ（上限 {$megabytes} MB）", [self::item('メモリの上限', "{$megabytes} MB", true)]);
    }

    private static function error(Error $e): Kind
    {
        if (preg_match(self::CALL_ON_NULL, $e->getMessage(), $match) !== 1) {
            return new Kind(null, []);
        }

        return new Kind("null の {$match[1]}() を呼んだ", [self::item('呼んだメソッド', "{$match[1]}()", true)]);
    }

    /**
     * コマンドの文字は「'/path/to/php' 'artisan' report:daily --option」の形。artisan の次の語だけを取る
     */
    private static function schedule(Exception $e): Kind
    {
        if (preg_match(self::SCHEDULE, $e->getMessage(), $match) !== 1) {
            return new Kind(null, []);
        }

        $words = array_map(static fn (string $word): string => trim($word, "'\""), preg_split('/\s+/', $match[1]) ?: []);
        $artisan = array_find_key($words, static fn (string $word): bool => $word === 'artisan' || str_ends_with($word, '/artisan'));
        $name = is_int($artisan) ? ($words[$artisan + 1] ?? null) : null;
        $exit = self::item('終了コード', $match[2], true);
        $term = Glossary::exitCode((int) $match[2]);

        if (! is_string($name) || preg_match(self::COMMAND, $name) !== 1) {
            return new Kind(self::join('スケジュールのコマンドの失敗', $term['short'] ?? null, "終了コード {$match[2]}"), [$exit], $term['explanation'] ?? null);
        }

        return new Kind(self::join("スケジュール {$name}", $term['short'] ?? null, "終了コード {$match[2]}"), [self::item('スケジュールのコマンド', $name, true), $exit], $term['explanation'] ?? null);
    }

    /**
     * 件名の要約を「短い言葉・決まった項目・番号」の順に「・」でつなぐ。件名は後ろから切るので、切れるときは番号から落ちる（番号は本文の枠に残る）
     */
    public static function join(?string ...$parts): string
    {
        return implode('・', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }

    private static function connectionSetting(QueryException $e, string $key): ?string
    {
        $value = $e->getConnectionDetails()[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        // 部品は Laravel の中で動くが、テストや Laravel の外で作った例外では設定が無いことがある
        try {
            $container = Container::getInstance();
            $config = $container->bound('config') ? $container->make('config') : null;
            $value = $config instanceof ConfigRepository ? $config->get("database.connections.{$e->getConnectionName()}.{$key}") : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{label: string, value: string, inSummary: bool}
     */
    private static function item(string $label, string $value, bool $inSummary): array
    {
        return ['label' => $label, 'value' => $value, 'inSummary' => $inSummary];
    }
}
