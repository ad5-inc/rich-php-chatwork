<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * キューのジョブの入口（ジョブの印と Request ID）
 *
 * JobProcessing で、前の Request ID を積み重ねに退避してジョブ用の新しい ID を入れ、ジョブの終わりで 1 段だけ戻す。
 *
 * 戻す時機は、Laravel が例外を記録した後にする（ログの Request ID と知らせの Request ID を揃えるため）。
 * - 成功: JobProcessed
 * - ワーカーで失敗: Worker::runJob() は process() の後で report() を呼び、JobAttempted は process() の finally で
 *   report() より前に出る。そこで次の周の Looping（daemon の周の頭）か WorkerStopping で戻す
 * - sync で失敗: SyncQueue は例外を呼び出し側へ投げ、report() は呼び出し側（HTTP など）で呼ばれる。
 *   呼び出し側へ戻る所の JobAttempted（executeJob の finally）で戻す。知らせは失敗のイベントで結び付けた入口で出す
 *
 * 出した側に ID が無い sync の失敗では戻さずに残し（呼び出し側の例外の記録もジョブの ID になる）、入口の終わり（ending）で戻す。
 *
 * 失敗のイベント（JobExceptionOccurred・JobFailed）で入口を例外に結び付けて取っておく（sync では report() の時点で戻っているため）。
 *
 * Laravel の ContextServiceProvider は JobProcessing で Context を丸ごと入れ替える（hydrate）。
 * ここの JobProcessing はそれより後に動く必要がある（ServiceProvider の boot の順。テストで固定している）
 */
final class JobEntrance
{
    /** @var list<array{uuid: string, label: string, id: string, origin: ?string, previous: mixed, had: bool, failed: bool, sync: bool, lingering: bool}> */
    private array $stack = [];

    /** @var WeakMap<Throwable, array{label: string, id: string, origin: ?string, sync: bool}> */
    private WeakMap $captured;

    /** 動いているコマンドの深さ。要求の中の Artisan::call の終わりで、残した ID を戻さないため */
    private int $commands = 0;

    /** 前の JobProcessing の後に Laravel が Context を入れ替えた（ContextHydrated） */
    private bool $hydrated = false;

    /**
     * 部品が Context に残したジョブの ID。後のジョブの payload に写っても、発端（出した側の ID）として使わない
     *
     * @var array<string, true>
     */
    private array $left = [];

    /** 覚えておく残した ID の数。長く動くワーカーで増え続けないようにする */
    private const LEFT_LIMIT = 100;

    public function __construct(private readonly Config $config)
    {
        $this->captured = new WeakMap();
    }

    /**
     * ContextHydrated。Laravel が JobProcessing で Context を payload の物に入れ替えた
     */
    public function hydrated(): void
    {
        $this->hydrated = true;
    }

    public function processing(Job $job): void
    {
        // 戻さないまま次のジョブが来た（ワーカーの周の頭のイベントが来なかった）。
        // Context が入れ替わった後なら、戻すと新しい Context を古い値で上書きするので印だけ捨てる。入れ替わっていなければ戻してから捨てる
        while (($top = end($this->stack)) !== false && $top['failed']) {
            array_pop($this->stack);
            if (! $this->hydrated) {
                $this->restore($top);
            }
        }
        $this->hydrated = false;

        $key = $this->key();
        $previous = Context::get($key);
        // 部品が残した ID は、出した側の ID ではない（前の sync のジョブの物）
        $left = is_string($previous) && isset($this->left[$previous]);
        $had = ! $left && Context::has($key);
        $previous = $left ? null : $previous;
        $id = (string) Str::uuid();

        $name = $job->resolveName();
        $short = substr($name, (int) strrpos('\\' . $name, '\\'));
        $label = (preg_match('/\A[A-Za-z0-9_]{1,100}\z/', $short) === 1 ? $short : 'ジョブ') . "（試行 {$job->attempts()} 回目）";

        $this->stack[] = [
            'uuid' => $this->uuid($job),
            'label' => $label,
            'id' => $id,
            'origin' => is_string($previous) && $previous !== '' ? $previous : null,
            'previous' => $previous,
            'had' => $had,
            'failed' => false,
            'sync' => $job instanceof SyncJob,
            'lingering' => false,
        ];
        Context::add($key, $id);
    }

    /**
     * 失敗のイベント。入口を例外に結び付け、失敗の印を付ける（戻すのは report() の後）。
     * 入れ子で外側のジョブにも同じ例外が来るので、結び付けは一番内側を残す
     */
    public function failed(Job $job, Throwable $e): void
    {
        $uuid = $this->uuid($job);
        $frame = $this->find($uuid);
        if ($frame !== null) {
            $entry = ['label' => $frame['label'], 'id' => $frame['id'], 'origin' => $frame['origin'], 'sync' => $frame['sync']];
            $main = Chain::of($e, $this->wrappers())->main;
            foreach ([$e, $main] as $exception) {
                if (! $this->captured->offsetExists($exception)) {
                    $this->captured[$exception] = $entry;
                }
            }
            $this->stack = array_map(static fn (array $f): array => $f['uuid'] === $uuid ? [...$f, 'failed' => true] : $f, $this->stack);
        }
    }

    /**
     * JobAttempted。sync のジョブだけ、ここ（呼び出し側へ戻る所）で戻す。ワーカーでは report() より前に来るので戻さない。
     * 出した側に ID が無い sync で失敗したときは戻さず、ジョブの ID を残す（呼び出し側の例外の記録もその番号で引けるように）
     */
    public function attempted(Job $job): void
    {
        if (! $job instanceof SyncJob) {
            return;
        }

        $uuid = $this->uuid($job);
        $frame = $this->find($uuid);
        if ($frame !== null && $frame['failed'] && $frame['origin'] === null) {
            // 段は捨てずに残し、入口の終わり（ending）で戻す
            $this->stack = array_map(static fn (array $f): array => $f['uuid'] === $uuid ? [...$f, 'lingering' => true] : $f, $this->stack);
            $this->left[$frame['id']] = true;
            if (count($this->left) > self::LEFT_LIMIT) {
                $this->left = array_slice($this->left, -self::LEFT_LIMIT, null, true);
            }

            return;
        }

        $this->finished($job);
    }

    public function commandStarting(): void
    {
        $this->commands++;
    }

    /**
     * 一番外のコマンドの終わりでだけ、残した ID を戻す
     */
    public function commandFinished(): void
    {
        $this->commands = max(0, $this->commands - 1);
        if ($this->commands === 0) {
            $this->ending();
        }
    }

    /**
     * 入口の終わり（一番外の CommandFinished・RequestHandled・アプリの terminating）。残したジョブの ID を戻す。
     * Context がもう別の値なら（後のジョブが入れ替えた）触らない
     */
    public function ending(): void
    {
        $key = $this->key();
        while (($top = end($this->stack)) !== false && $top['lingering']) {
            array_pop($this->stack);
            if (Context::get($key) === $top['id']) {
                $this->restore($top);
            }
        }
    }

    /**
     * ワーカーの周の頭（Looping）と止まるとき（WorkerStopping）。失敗したまま戻していないジョブを戻す
     */
    public function settle(): void
    {
        $this->ending();
        while (($top = end($this->stack)) !== false && $top['failed']) {
            array_pop($this->stack);
            $this->restore($top);
        }
    }

    /**
     * ジョブごとに 1 段だけ戻す。uuid で見分けるので、同じジョブで 2 回戻らない
     */
    public function finished(Job $job): void
    {
        $uuid = $this->uuid($job);
        $index = array_find_key($this->stack, static fn (array $frame): bool => $frame['uuid'] === $uuid);
        if (! is_int($index)) {
            return;
        }

        $frame = $this->stack[$index];
        $this->stack = array_slice($this->stack, 0, $index);
        $this->restore($frame);
    }

    /**
     * @param array{uuid: string, label: string, id: string, origin: ?string, previous: mixed, had: bool, failed: bool, sync: bool, lingering: bool} $frame
     */
    private function restore(array $frame): void
    {
        $frame['had'] ? Context::add($this->key(), $frame['previous']) : Context::forget($this->key());
    }

    /**
     * 例外の入口。失敗のイベントで取っておいた物を先に見て、無ければ動いている最中のジョブ
     *
     * @return array{label: string, id: string, origin: ?string, sync: bool}|null
     */
    public function of(Throwable $e, Throwable $main): ?array
    {
        // 呼び出し側が sync の失敗を別の例外で包み直したときも、previous を辿って結び付けた入口を使う
        $seen = 0;
        for ($current = $e; $current !== null && $seen <= Chain::DEPTH; $current = $current->getPrevious(), $seen++) {
            $entry = $this->captured[$current] ?? null;
            if ($entry !== null) {
                return $entry;
            }
        }
        $entry = $this->captured[$main] ?? null;
        if ($entry !== null) {
            return $entry;
        }

        // 動いている最中の段だけ。失敗して残した段（戻すのを待っている物）は、もう入口ではない（受け止めた後の別の例外はコマンド・HTTP で出す）
        $running = array_filter($this->stack, static fn (array $frame): bool => ! $frame['failed']);
        $top = end($running);

        // 動いている最中のジョブの中で report() したなら、呼び出し側で記録されたのではない
        return $top === false ? null : ['label' => $top['label'], 'id' => $top['id'], 'origin' => $top['origin'], 'sync' => false];
    }

    /**
     * @return array{uuid: string, label: string, id: string, origin: ?string, previous: mixed, had: bool, failed: bool, sync: bool, lingering: bool}|null
     */
    private function find(string $uuid): ?array
    {
        return array_find($this->stack, static fn (array $frame): bool => $frame['uuid'] === $uuid);
    }

    private function uuid(Job $job): string
    {
        $uuid = $job->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : 'job:' . spl_object_id($job);
    }

    private function key(): string
    {
        $key = $this->config->get('rich-chatwork.context.request_id');

        return is_string($key) && $key !== '' ? $key : 'request_id';
    }

    /**
     * @return list<string>
     */
    private function wrappers(): array
    {
        return Settings::strings($this->config, 'wrapper_exceptions', ['Illuminate\View\ViewException']);
    }
}
