<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Database\QueryException;
use PDOException;
use SplObjectStorage;
use Throwable;

/**
 * previous のつながり。外側から「包む例外」を剥いで、最初に現れる包まない例外を主に見る
 *
 * 件名・種類の行・メッセージ・アプリの行・間引きのキーは、すべて主に見る例外から作る
 */
final readonly class Chain
{
    /** previous を辿る段数の上限 */
    public const DEPTH = 10;

    public const CAUSES = 3;

    /**
     * @param list<Throwable> $wrappers 剥いだ包む例外（外側から）
     * @param list<Throwable> $causes 主に見る例外より内側の previous（最大 3）
     */
    public function __construct(
        public Throwable $main,
        public array $wrappers,
        public array $causes,
    ) {
    }

    /**
     * @param list<string> $wrapperClasses
     */
    public static function of(Throwable $e, array $wrapperClasses): self
    {
        $seen = new SplObjectStorage();
        $all = [];

        // 同じ例外に 2 度着いたら止めるので、副作用のあるループで書く
        for ($current = $e; $current !== null && count($all) <= self::DEPTH && ! $seen->offsetExists($current); $current = $current->getPrevious()) {
            $seen->offsetSet($current);
            $all[] = $current;
        }

        $isWrapper = static fn (Throwable $t): bool => array_any($wrapperClasses, static fn (string $class): bool => $t instanceof $class);
        $index = array_find_key($all, static fn (Throwable $t): bool => ! $isWrapper($t));
        if (! is_int($index)) {
            return new self($e, [], []);
        }

        $main = $all[$index];
        $causes = [];
        foreach (array_slice($all, $index + 1) as $position => $cause) {
            // QueryException の中の PDOException は、組み立てた文言に入っている
            $outer = $all[$index + $position];
            if ($cause instanceof PDOException && $outer instanceof QueryException) {
                continue;
            }
            $causes[] = $cause;
        }

        return new self($main, array_slice($all, 0, $index), array_slice($causes, 0, self::CAUSES));
    }

    /**
     * 一番内側の ViewException が描いていたビュー。CompilerEngine が文言の末尾に「 (View: 絶対パス)」を付ける
     */
    public function view(Source $source): ?string
    {
        $views = array_filter($this->wrappers, static fn (Throwable $t): bool => $t instanceof \Illuminate\View\ViewException);
        $innermost = end($views);
        if ($innermost === false || preg_match('/\(View: ([^()\0]+)\)\s*\z/u', $innermost->getMessage(), $match) !== 1) {
            return null;
        }

        $path = $source->relative($match[1]);

        return str_starts_with($path, '/') ? basename($path) : $path;
    }
}
