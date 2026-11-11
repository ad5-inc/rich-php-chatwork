<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 同じ例外を間引き、送らなかった回数を数える。部品の全体の上限（1 分あたりの件数）もここで数える
 *
 * キー（間を置く印）・{キー}:count（送らなかった回数）・{キー}:last（前に送った時刻）を置く。
 * 回数は「おおよそ」: 読む間の取りこぼしと、送信の失敗で戻すときの取りこぼしは受け入れる。
 * file・array のストアの increment は期限の無いキーを作りうるが、次に送るときの pull で消える
 *
 * 置き場は 設定のキャッシュ → 一時フォルダのファイル → プロセスの中 の順に落ちる。キャッシュが DB で DB が落ちても、
 * 一時フォルダの印（add は flock で排他）で PHP-FPM のプロセスをまたいで間引きと上限が効く
 */
final class Throttle
{
    private const DAY = 86400;

    private const OVERFLOW = 'rich-chatwork:overflow';

    /** 今の知らせで使っている置き場（0: 設定のキャッシュ、1: 一時フォルダ、2: プロセスの中）。投げたら次へ落ちる */
    private int $level = 0;

    private ?Cache $file = null;

    /** @var array<string, true> 置き場が使えないとログに書いた物（プロセスごとに 1 度） */
    private array $logged = [];

    private readonly Cache $memory;

    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly string $fallbackDirectory,
    ) {
        $this->memory = new CacheRepository(new ArrayStore());
    }

    /**
     * 予備の一時フォルダ。アプリごとに分ける
     */
    public static function fallbackDirectory(string $basePath): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rich-chatwork-' . substr(sha1($basePath), 0, 12);
    }

    /**
     * 1 つの知らせの始まり。前の知らせで置き場が投げていても、もう一度設定のキャッシュから試す
     */
    public function begin(): void
    {
        $this->level = 0;
    }

    /**
     * 送ってよければ、前の知らせの後に送らなかった回数と前に送った時刻を返す。送らないなら null
     *
     * first は最初に数えた時刻、since は送れなかった分を含めて数え始めた時刻（送れなかったことがあるときだけ）
     *
     * @return array{count: int, last: ?int, first: ?int, since: ?int}|null
     */
    public function attempt(string $key, int $seconds): ?array
    {
        return $this->run(static function (Cache $store) use ($key, $seconds): ?array {
            if (! $store->add($key, true, $seconds)) {
                // 値を持てない置き場（NullStore・つながらない memcached）は投げずに false を返す。間を置く印が無いのに置けないなら、次の置き場へ
                if (! $store->has($key)) {
                    throw new \RuntimeException('間引きの印を置けない');
                }
                $store->add("{$key}:count", 0, self::DAY);
                $store->increment("{$key}:count");
                $store->add("{$key}:first", now()->getTimestamp(), self::DAY);

                return null;
            }

            $count = $store->pull("{$key}:count");
            $last = $store->get("{$key}:last");
            $first = $store->pull("{$key}:first");
            $since = $store->pull("{$key}:since");

            return [
                'count' => is_numeric($count) ? (int) $count : 0,
                'last' => is_int($last) ? $last : null,
                'first' => is_int($first) ? $first : null,
                'since' => is_int($since) ? $since : null,
            ];
        });
    }

    /**
     * 送れたら時刻を置く
     */
    public function sent(string $key): void
    {
        $this->run(static fn (Cache $store): bool => $store->put("{$key}:last", now()->getTimestamp(), self::DAY));
    }

    /**
     * 送れなかった・上限で送らなかったら、間引きの印を消し、読んだ回数を戻す。次に同じ例外が起きたらすぐ送り直す
     *
     * 送れなかったとき（$failed）は、その 1 回も数え、数え始めた時刻（since）を置く。次の知らせは
     * 「前の知らせの後の 60 秒」ではなく「since から」の言い方になる（送れなかった間は前の知らせの窓と合わない）
     *
     * @param array{count: int, last: ?int, first: ?int, since: ?int} $state attempt() の返した物
     */
    public function release(string $key, array $state, bool $failed): void
    {
        $count = $state['count'] + ($failed ? 1 : 0);
        $since = $failed ? ($state['since'] ?? $state['first'] ?? now()->getTimestamp()) : $state['since'];

        $this->run(static function (Cache $store) use ($key, $count, $state, $since): void {
            $store->forget($key);
            if ($count > 0) {
                $store->add("{$key}:count", 0, self::DAY);
                $store->increment("{$key}:count", $count);
            }
            if ($state['first'] !== null) {
                $store->add("{$key}:first", $state['first'], self::DAY);
            }
            if ($since !== null) {
                $store->put("{$key}:since", $since, self::DAY);
            }
        });
    }

    /**
     * 部品の全体の上限の枠を 1 つ取る。1 分ごと（時計の分）に数える
     */
    public function slot(int $max): bool
    {
        $minute = intdiv(now()->getTimestamp(), 60);

        return (bool) $this->run(static function (Cache $store) use ($minute, $max): bool {
            $store->add("rich-chatwork:minute:{$minute}", 0, 120);
            $count = $store->increment("rich-chatwork:minute:{$minute}");
            if (! is_int($count)) {
                throw new \RuntimeException('全体の上限を数えられない');
            }

            return $count <= $max;
        });
    }

    /**
     * 上限で送らなかった知らせを、間引きのキーごとに数える。最初に上限を超えた時刻も置く。
     * 読んで書くので、同時に起きた分は取りこぼしうる（おおよそ）
     */
    public function overflow(string $key, string $name, bool $notice): void
    {
        $this->changeOverflow(static function (array $overflow) use ($key, $name, $notice): array {
            $entry = $overflow['entries'][$key] ?? ['name' => $name, 'count' => 0, 'notice' => $notice, 'since' => now()->getTimestamp()];
            $entry['count']++;
            $overflow['entries'][$key] = $entry;
            $overflow['since'] ??= now()->getTimestamp();

            return $overflow;
        });
    }

    /**
     * 上限で送らなかった件数を読んで消す
     *
     * @return array{since?: int, entries?: array<string, array{name: string, count: int, notice: bool, since: int}>}
     */
    public function takeOverflow(): array
    {
        return $this->changeOverflow(static fn (array $overflow): array => []);
    }

    /**
     * 送れなかったら、読んだ件数を戻す
     *
     * @param array{since?: int, entries?: array<string, array{name: string, count: int, notice: bool, since: int}>} $taken
     */
    public function restoreOverflow(array $taken): void
    {
        if (($taken['entries'] ?? []) === []) {
            return;
        }

        $this->changeOverflow(static function (array $overflow) use ($taken): array {
            foreach ($taken['entries'] as $key => $entry) {
                $current = $overflow['entries'][$key] ?? ['name' => $entry['name'], 'count' => 0, 'notice' => $entry['notice'], 'since' => $entry['since']];
                $current['count'] += $entry['count'];
                $current['since'] = min($current['since'], $entry['since']);
                $overflow['entries'][$key] = $current;
            }
            $overflow['since'] = min($overflow['since'] ?? PHP_INT_MAX, $taken['since'] ?? PHP_INT_MAX);

            return $overflow;
        });
    }

    /**
     * 変える前の値を返す
     *
     * @param callable(array{since?: int, entries?: array<string, array{name: string, count: int, notice: bool, since: int}>}): array{since?: int, entries?: array<string, array{name: string, count: int, notice: bool, since: int}>} $change
     *
     * @return array{since?: int, entries?: array<string, array{name: string, count: int, notice: bool, since: int}>}
     */
    private function changeOverflow(callable $change): array
    {
        return $this->run(static function (Cache $store) use ($change): array {
            $stored = $store->get(self::OVERFLOW);
            /** @var array{since?: int, entries?: array<string, array{name: string, count: int, notice: bool, since: int}>} $overflow */
            $overflow = is_array($stored) ? $stored : [];
            $store->put(self::OVERFLOW, $change($overflow), self::DAY);

            return $overflow;
        });
    }

    /**
     * 今の置き場で $work を動かす。投げたら次の置き場へ落ちて、同じ知らせの中はそこを使い続ける
     *
     * @template T
     *
     * @param callable(Cache): T $work
     *
     * @return T
     */
    private function run(callable $work): mixed
    {
        for (; $this->level < 2; $this->level++) {
            try {
                return $work($this->store($this->level));
            } catch (Throwable $failure) {
                $this->logOnce($this->level === 0 ? '間引きのキャッシュが使えないので、予備の置き場で数える' : '予備の一時フォルダが使えないので、プロセスの中で数える', $failure);
            }
        }

        return $work($this->memory);
    }

    private function logOnce(string $message, Throwable $failure): void
    {
        if (isset($this->logged[$message])) {
            return;
        }
        $this->logged[$message] = true;

        try {
            Log::warning($message, ['exception' => $failure]);
        } catch (Throwable) {
            // ログにも書けないなら諦める
        }
    }

    private function store(int $level): Cache
    {
        if ($level === 0) {
            $store = $this->config->get('rich-chatwork.cache_store');

            return $this->cache->store(is_string($store) ? $store : null);
        }

        return $this->file ??= $this->fileStore();
    }

    /**
     * 一時フォルダは誰でも書ける場所なので、フォルダが自分の物で書き込めるときだけ使う。「自分」はプロセスの実効の利用者
     * （getmyuid() はスクリプトのファイルの持ち主なので、デプロイの利用者と PHP-FPM の利用者が違うと外れる）。
     * ファイルは持ち主だけが読み書きでき（0600）、中身からクラスを復元しない（置き場の中身は整数と配列だけ）
     */
    private function fileStore(): Cache
    {
        $directory = $this->fallbackDirectory;
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        clearstatcache(true, $directory);
        if (! is_dir($directory) || ! is_writable($directory) || fileowner($directory) !== (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid())) {
            throw new \RuntimeException('予備の一時フォルダが自分の物でないか、書き込めない');
        }

        // FileStore は「ファイルの権限」をフォルダにも当てる。0600 のままだとフォルダの x が落ち、root でない利用者は
        // 中に入れず、予備が使えないままプロセスの中に落ちる。フォルダは 0700、ファイルは 0600 にする
        return new CacheRepository(new class (new Filesystem(), $directory, 0600, false) extends FileStore {
            protected function ensurePermissionsAreCorrect($path): void
            {
                @chmod($path, is_dir($path) ? 0700 : 0600);
            }
        });
    }
}
