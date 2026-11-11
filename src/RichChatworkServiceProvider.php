<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Context\Events\ContextHydrated;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;
use WeakMap;

final class RichChatworkServiceProvider extends ServiceProvider
{
    /** メモリ切れのときに足すメモリ。部品の組み立て（数 MB）と Laravel の元の記録に足りる量 */
    private const MEMORY_ROOM = 32 * 1024 * 1024;

    /** @var WeakMap<object, true> 送る callback を登録したハンドラー */
    private WeakMap $attached;

    private bool $warnedUnattachable = false;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->attached = new WeakMap();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rich-chatwork.php', 'rich-chatwork');

        $this->app->bindIf(Transport::class, static function (Application $app): Transport {
            // 届くことを優先して長く待つ。画面の操作の中では、サーバーの時間の上限で先に切れることがある
            $seconds = $app->make('config')->get('rich-chatwork.timeout_seconds');

            return new PhpChatworkTransport(is_int($seconds) || is_float($seconds) ? (float) $seconds : 180.0);
        }, true);
        $this->app->bindIf(Source::class, static fn (Application $app): Source => new Source($app->basePath()), true);
        $this->app->singleton(JobEntrance::class, static fn (Application $app): JobEntrance => new JobEntrance($app->make('config')));
        $this->app->singleton(Reporter::class, static fn (Application $app): Reporter => new Reporter(
            $app,
            $app->make('config'),
            $app->make('cache'),
            $app->make(Transport::class),
            $app->make(Source::class),
            $app->make(JobEntrance::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/rich-chatwork.php' => config_path('rich-chatwork.php')], 'rich-chatwork-config');

        // 記録する例外（dontReport に当たらないもの）を送る。ログへの記録はそのまま続く
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $this->attach($handler);
        });

        $this->listenToJobs();
    }

    /**
     * 例外のハンドラーに、送る callback を登録する。Laravel の Handler を継承しない物（コンソールの Collision の
     * ExceptionHandler など）も、reportable() を持てば中の Handler に回すので、継承ではなく reportable() の有無で見る。
     * resolve のたびに呼ばれうるので、同じハンドラーには 1 度だけ登録する
     */
    public function attach(object $handler): void
    {
        if (isset($this->attached[$handler])) {
            return;
        }
        $this->attached[$handler] = true;

        if (! method_exists($handler, 'reportable')) {
            if (! $this->warnedUnattachable) {
                $this->warnedUnattachable = true;
                try {
                    Log::warning('例外のハンドラーに reportable が無いので、例外を Chatwork に知らせない', ['handler' => $handler::class]);
                } catch (Throwable) {
                    // ログにも書けないなら諦める
                }
            }

            return;
        }

        // Handler::reportThrowable() は callback を try で包まない。ここから投げると Laravel が元の例外をログに書かずに終わる
        $handler->reportable(function (Throwable $e): void {
            try {
                if (! self::makeRoomForMemoryExhaustion($e)) {
                    return;
                }
                $this->app->make(Reporter::class)->report($e);
            } catch (Throwable $failure) {
                try {
                    Log::warning('例外を Chatwork に送れなかった', ['exception' => $failure, 'original' => $e::class]);
                } catch (Throwable) {
                    // ログにも書けないなら諦める。元の例外の記録は Laravel 側で続く
                }
            }
        });
    }

    /**
     * メモリ切れのときは、部品を動かす前に上限を少し上げる。Laravel は 32KB しか空けずに report() を呼び、部品の callback が
     * 元の記録より先に動く。部品がメモリを取って 2 度目のメモリ切れを起こすと、元の記録まで止まる。
     * 上げられなければ false（部品を動かさず、元の記録は Laravel に任せる）。上限は下げない
     */
    private static function makeRoomForMemoryExhaustion(Throwable $e): bool
    {
        if (! $e instanceof FatalError || ! str_starts_with($e->getMessage(), 'Allowed memory size of')) {
            return true;
        }

        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current === null) {
            return true;
        }

        $wanted = memory_get_usage(true) + self::MEMORY_ROOM;
        if ($current >= $wanted) {
            return true;
        }

        return @ini_set('memory_limit', (string) $wanted) !== false && (self::bytes((string) ini_get('memory_limit')) ?? PHP_INT_MAX) >= $wanted;
    }

    /**
     * memory_limit の文字（512M・1G・数字）をバイトに。-1（上限なし）は null
     */
    private static function bytes(string $limit): ?int
    {
        $limit = trim($limit);
        if ($limit === '-1' || $limit === '') {
            return null;
        }

        $number = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * ジョブの入口の印。Laravel の ContextServiceProvider（JobProcessing で Context を入れ替える）より後に登録される必要がある。
     * パッケージの ServiceProvider は Laravel の基本の ServiceProvider より後に boot されるので、ここで登録すればよい
     */
    private function listenToJobs(): void
    {
        $events = $this->app->make('events');
        $entrance = fn (): JobEntrance => $this->app->make(JobEntrance::class);
        // 入口の印が失敗しても、ジョブは止めない
        $safely = static function (callable $handle): void {
            try {
                $handle();
            } catch (Throwable) {
                // 知らせに入口が出なくなるだけ
            }
        };

        $events->listen(ContextHydrated::class, static function () use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->hydrated());
        });
        $events->listen(JobProcessing::class, static function (JobProcessing $event) use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->processing($event->job));
        });
        $events->listen(JobProcessed::class, static function (JobProcessed $event) use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->finished($event->job));
        });
        $events->listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event) use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->failed($event->job, $event->exception));
        });
        $events->listen(JobFailed::class, static function (JobFailed $event) use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->failed($event->job, $event->exception));
        });
        $events->listen(JobAttempted::class, static function (JobAttempted $event) use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->attempted($event->job));
        });
        // Looping は until() で出るので、何も返さない（false を返すとワーカーの周が止まる）
        $events->listen(Looping::class, static function () use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->settle());
        });
        $events->listen(WorkerStopping::class, static function () use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->settle());
        });
        // 入口の終わり。出した側に ID が無い sync の失敗で残したジョブの ID を戻す
        // 要求の中の Artisan::call でも CommandFinished が出るので、深さを数えて一番外でだけ戻す
        $events->listen(CommandStarting::class, static function () use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->commandStarting());
        });
        $events->listen(CommandFinished::class, function () use ($entrance, $safely): void {
            $safely(fn () => $entrance()->commandFinished($this->app->runningInConsole()));
        });
        $events->listen(RequestHandled::class, static function () use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->ending());
        });
        $this->app->terminating(static function () use ($entrance, $safely): void {
            $safely(static fn () => $entrance()->ending());
        });
    }
}
