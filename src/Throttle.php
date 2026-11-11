<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * 同じ例外を間引き、送らなかった回数を数える。部品の全体の上限（1 分あたりの件数）もここで数える
 *
 * キー（間を置く印）・{キー}:count（送らなかった回数）・{キー}:last（前に送った時刻）の 3 つを置く。
 * 回数は「おおよそ」: 読む間の取りこぼしと、送信の失敗で戻すときの取りこぼしは受け入れる。
 * file・array のストアの increment は期限の無いキーを作りうるが、次に送るときの pull で消える
 */
final class Throttle
{
    private const DAY = 86400;

    /**
     * キャッシュが使えないとき（DB の障害など）の、このプロセスだけの印。キーと期限（UNIX 時刻）
     *
     * @var array<string, int>
     */
    private array $fallbackMarks = [];

    /** キャッシュが 1 度投げたら、同じ知らせの中ではキャッシュに行かない */
    private bool $broken = false;

    /**
     * キャッシュが使えないときの、このプロセスだけの 1 分ごとの件数
     *
     * @var array<int, int>
     */
    private array $fallbackMinutes = [];

    /**
     * キャッシュが使えないときの、このプロセスだけの上限で送らなかった件数
     *
     * @var array<string, int>
     */
    private array $fallbackOverflow = [];

    public function __construct(private readonly Config $config, private readonly CacheFactory $cache)
    {
    }

    /**
     * 送ってよければ、前の知らせの後に送らなかった回数と前に送った時刻を返す。送らないなら null
     *
     * 予備の動き（プロセスの中だけの印）のときは回数を null にする（数えていないので出さない）
     *
     * @return array{count: ?int, last: ?int}|null
     */
    public function attempt(string $key, int $seconds): ?array
    {
        if ($this->broken) {
            return $this->fallbackAttempt($key, $seconds);
        }

        try {
            $store = $this->store();
            if (! $store->add($key, true, $seconds)) {
                $store->add("{$key}:count", 0, self::DAY);
                $store->increment("{$key}:count");

                return null;
            }

            $count = $store->pull("{$key}:count");
            $last = $store->get("{$key}:last");

            return ['count' => is_numeric($count) ? (int) $count : 0, 'last' => is_int($last) ? $last : null];
        } catch (Throwable) {
            $this->broken = true;
        }

        return $this->fallbackAttempt($key, $seconds);
    }

    /**
     * 1 つの知らせの始まり。前の知らせでキャッシュが投げていても、もう一度キャッシュを試す
     */
    public function begin(): void
    {
        $this->broken = false;
    }

    /**
     * 部品の全体の上限の枠を 1 つ取る。1 分ごと（時計の分）に数える
     */
    public function slot(int $max): bool
    {
        $minute = intdiv(now()->getTimestamp(), 60);

        if (! $this->broken) {
            try {
                $store = $this->store();
                $store->add("rich-chatwork:minute:{$minute}", 0, 120);
                $count = $store->increment("rich-chatwork:minute:{$minute}");

                return is_int($count) && $count <= $max;
            } catch (Throwable) {
                $this->broken = true;
            }
        }

        $this->fallbackMinutes = [$minute => ($this->fallbackMinutes[$minute] ?? 0) + 1];

        return $this->fallbackMinutes[$minute] <= $max;
    }

    /**
     * 上限で送らなかった知らせを、名前ごとに数える。読んで書くので、同時に起きた分は取りこぼしうる（おおよそ）
     */
    public function overflow(string $name): void
    {
        $this->changeOverflow(static fn (array $counts): array => [...$counts, $name => ($counts[$name] ?? 0) + 1]);
    }

    /**
     * 上限で送らなかった件数を読んで消す
     *
     * @return array<string, int>
     */
    public function takeOverflow(): array
    {
        $taken = [];
        $this->changeOverflow(static function (array $counts) use (&$taken): array {
            $taken = $counts;

            return [];
        });

        return $taken;
    }

    /**
     * 送れなかったら、読んだ件数を戻す
     *
     * @param array<string, int> $taken
     */
    public function restoreOverflow(array $taken): void
    {
        if ($taken !== []) {
            $this->changeOverflow(static function (array $counts) use ($taken): array {
                foreach ($taken as $name => $count) {
                    $counts[$name] = ($counts[$name] ?? 0) + $count;
                }

                return $counts;
            });
        }
    }

    /**
     * 送れなかったら、間引きの印を消し、読んだ回数を戻す。次に同じ例外が起きたらすぐ送り直す
     */
    public function release(string $key, int $count): void
    {
        unset($this->fallbackMarks[$key]);
        $this->bestEffort(static fn (Cache $store): bool => $store->forget($key));
        $this->restore($key, $count);
    }

    /**
     * @param callable(array<string, int>): array<string, int> $change
     */
    private function changeOverflow(callable $change): void
    {
        if (! $this->broken) {
            try {
                $store = $this->store();
                $stored = $store->get('rich-chatwork:overflow');
                $counts = is_array($stored) ? array_filter($stored, is_int(...)) : [];
                /** @var array<string, int> $counts */
                $store->put('rich-chatwork:overflow', $change($counts), self::DAY);

                return;
            } catch (Throwable) {
                $this->broken = true;
            }
        }

        $this->fallbackOverflow = $change($this->fallbackOverflow);
    }

    /**
     * @return array{count: ?int, last: ?int}|null
     */
    private function fallbackAttempt(string $key, int $seconds): ?array
    {
        $now = now()->getTimestamp();
        if (($this->fallbackMarks[$key] ?? 0) > $now) {
            return null;
        }
        $this->fallbackMarks[$key] = $now + $seconds;

        return ['count' => null, 'last' => null];
    }

    /**
     * 送れたら時刻を置く
     */
    public function sent(string $key): void
    {
        $this->bestEffort(fn (Cache $store): bool => $store->put("{$key}:last", now()->getTimestamp(), self::DAY));
    }

    /**
     * 送れなかったら、読んだ回数を戻す
     */
    public function restore(string $key, int $count): void
    {
        if ($count > 0) {
            $this->bestEffort(static function (Cache $store) use ($key, $count): void {
                $store->add("{$key}:count", 0, self::DAY);
                $store->increment("{$key}:count", $count);
            });
        }
    }

    /**
     * @param callable(Cache): mixed $write
     */
    private function bestEffort(callable $write): void
    {
        if ($this->broken) {
            return;
        }

        try {
            $write($this->store());
        } catch (Throwable) {
            $this->broken = true;
        }
    }

    private function store(): Cache
    {
        $store = $this->config->get('rich-chatwork.cache_store');

        return $this->cache->store(is_string($store) ? $store : null);
    }
}
