<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * 同じ例外を間引き、送らなかった回数を数える
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
        $this->broken = false;

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
