<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use PDOException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * 形の分かる例外の文言。部品が安全な部品だけで組み立て直し、元の文言は使わない
 *
 * QueryException の文言は値を埋めた SQL と接続先（Host・Port・Database）を、RequestException の文言は応答の本文を、
 * プロセスの例外の文言はコマンドライン（パスワード・トークンの引数）を含む。
 * 組み立てに失敗しても元の文言には戻さず、FAILED を返す
 */
final class KnownMessage
{
    public const FAILED = '（組み立てられなかったので省いた。ログを見る）';

    /** 接続の失敗のうち、名前が引けないときの言葉（件名の要約は、表の言葉よりこちらを取る） */
    public const NAME_LOOKUP_WORDS = '名前が引けない';

    /** ドライバー 2002 のうち、名前が引けないときの文言の手がかり（文言は出さず、言葉を選ぶためだけに読む） */
    private const NAME_LOOKUP = '/getaddrinfo|php_network_getaddresses|Name or service not known|nodename nor servname|Temporary failure in name resolution/i';

    /**
     * 形の分かる例外なら組み立てた文言、それ以外は null
     *
     * @param bool $sqlBelow DB の例外で、SQL の形を本文の別の枠に出すか（出すなら「SQL: 下の枠」、出さないなら今までどおり）
     */
    public static function of(Throwable $e, bool $sqlBelow = false): ?string
    {
        try {
            return match (true) {
                $e instanceof QueryException => self::query($e, $sqlBelow),
                $e instanceof RequestException => self::request($e),
                $e instanceof ConnectionException => self::connection($e),
                default => ProcessFailure::of($e)?->message,
            };
        } catch (Throwable) {
            return self::FAILED;
        }
    }

    private static function query(QueryException $e, bool $sqlBelow): string
    {
        [$state, $driver] = Details::sqlState($e);
        $name = preg_match(Details::CONNECTION, $e->getConnectionName()) === 1 ? $e->getConnectionName() : Redactor::MARK;
        $connection = $sqlBelow ? "(Connection: {$name}, SQL: 下の枠)" : "(Connection: {$name})";
        $head = $state === null ? '' : "SQLSTATE[{$state}]";
        $previous = $e->getPrevious();

        if (! $previous instanceof PDOException) {
            return ltrim("{$head} （文言はログ） {$connection}");
        }

        if (Details::isConnectionFailure($state, $driver)) {
            return $head . ($driver === null ? '' : " [{$driver}]") . ' ' . self::connectionWords($driver, $previous->getMessage()) . "（詳細はログ） {$connection}";
        }

        $driverMessage = $previous->errorInfo[2] ?? null;
        $allowed = is_string($driverMessage) ? DriverMessage::redact($driverMessage) : null;
        if ($allowed !== null && $state !== null) {
            // PDO が付ける SQLSTATE の説明（String data, right truncated など）は決まった英語
            $stateText = preg_match('/\ASQLSTATE\[[0-9A-Z]{5}\]: ([A-Za-z ,\/\-]{1,80}): /', $previous->getMessage(), $match) === 1 ? "{$match[1]}: " : '';

            return "{$head}: {$stateText}" . ($driver === null ? '' : "{$driver} ") . "{$allowed} (Connection: {$name}, SQL: " . ($sqlBelow ? '下の枠' : '（ログ）') . ')';
        }

        return ltrim($head . ($driver === null ? '' : " ドライバー {$driver}") . "（文言はログ） {$connection}");
    }

    /**
     * 接続の失敗の固定の言葉。ドライバーの文言は出さず、言葉を選ぶためだけに読む（写された文言を伏せるときも同じ言葉にする）
     */
    public static function connectionWords(?int $driver, string $driverMessage): string
    {
        return match (true) {
            $driver === 1045 => '認証に失敗',
            $driver === 2002 && preg_match(self::NAME_LOOKUP, $driverMessage) === 1 => self::NAME_LOOKUP_WORDS,
            // 2006（MySQL server has gone away）はつながった後に切れた物
            $driver === 2006 => '接続が切れた',
            default => '接続できない',
        };
    }

    private static function request(RequestException $e): string
    {
        $status = $e->response->status();
        $uri = $e->response->effectiveUri();
        $method = $e->response->transferStats?->getRequest()->getMethod();
        $origin = $uri === null ? null : self::origin($uri);

        if ($uri === null || $origin === null || ! is_string($method) || preg_match('/\A[A-Z]{1,10}\z/', $method) !== 1) {
            return "HTTP {$status}";
        }

        return "HTTP {$status} {$method} {$origin}" . UrlPath::redact($uri->getPath());
    }

    private static function connection(ConnectionException $e): string
    {
        $previous = $e->getPrevious();
        $origin = $previous instanceof NetworkExceptionInterface || $previous instanceof RequestExceptionInterface
            ? self::origin($previous->getRequest()->getUri())
            : null;

        $words = Glossary::connection($previous);

        return $origin === null ? $words : "{$words}: {$origin}";
    }

    /**
     * スキームとホストだけ（利用者情報・ポート・問い合わせは出さない）
     */
    private static function origin(UriInterface $uri): ?string
    {
        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        return preg_match('/\A[a-z][a-z0-9+.\-]{0,15}\z/', $scheme) === 1 && preg_match('/\A[A-Za-z0-9.\-]{1,253}\z/', $host) === 1
            ? "{$scheme}://{$host}"
            : null;
    }
}
