<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * キューのジョブの入口（ジョブの印と Request ID）
 *
 * JobProcessing で、前の Request ID を積み重ねに退避してジョブ用の新しい ID を入れ、ジョブの終わりで 1 段だけ戻す。
 * Laravel は失敗のイベント（JobExceptionOccurred・JobFailed）を出した後で report() を呼ぶ（Worker も SyncQueue も）。
 * report() の時点では ID がもう戻っているので、失敗のイベントで入口を例外に結び付けて取っておく。
 *
 * Laravel の ContextServiceProvider は JobProcessing で Context を丸ごと入れ替える（hydrate）。
 * ここの JobProcessing はそれより後に動く必要がある（ServiceProvider の boot の順。テストで固定している）
 */
final class JobEntrance
{
    /** @var list<array{uuid: string, label: string, id: string, origin: ?string, previous: mixed, had: bool}> */
    private array $stack = [];

    /** @var WeakMap<Throwable, array{label: string, id: string, origin: ?string}> */
    private WeakMap $captured;

    public function __construct(private readonly Config $config)
    {
        $this->captured = new WeakMap();
    }

    public function processing(Job $job): void
    {
        $key = $this->key();
        $had = Context::has($key);
        $previous = Context::get($key);
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
        ];
        Context::add($key, $id);
    }

    /**
     * 失敗のイベント。入口を例外に結び付けてから戻す。入れ子で外側のジョブにも同じ例外が来るので、一番内側を残す
     */
    public function failed(Job $job, Throwable $e): void
    {
        $frame = $this->find($this->uuid($job));
        if ($frame !== null) {
            $entry = ['label' => $frame['label'], 'id' => $frame['id'], 'origin' => $frame['origin']];
            $main = Chain::of($e, $this->wrappers())->main;
            foreach ([$e, $main] as $exception) {
                if (! $this->captured->offsetExists($exception)) {
                    $this->captured[$exception] = $entry;
                }
            }
        }

        $this->finished($job);
    }

    /**
     * ジョブごとに 1 段だけ戻す。JobProcessed・JobFailed・JobExceptionOccurred のうち最初に来た物で戻り、後の物は何もしない
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

        $frame['had'] ? Context::add($this->key(), $frame['previous']) : Context::forget($this->key());
    }

    /**
     * 例外の入口。失敗のイベントで取っておいた物を先に見て、無ければ動いている最中のジョブ
     *
     * @return array{label: string, id: string, origin: ?string}|null
     */
    public function of(Throwable $e, Throwable $main): ?array
    {
        $entry = $this->captured[$e] ?? $this->captured[$main] ?? null;
        if ($entry !== null) {
            return $entry;
        }

        $top = end($this->stack);

        return $top === false ? null : ['label' => $top['label'], 'id' => $top['id'], 'origin' => $top['origin']];
    }

    /**
     * @return array{uuid: string, label: string, id: string, origin: ?string, previous: mixed, had: bool}|null
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
