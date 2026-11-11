<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class RichChatworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rich-chatwork.php', 'rich-chatwork');

        $this->app->bindIf(Transport::class, static function (Application $app): Transport {
            $seconds = $app->make('config')->get('rich-chatwork.timeout_seconds');

            return new PhpChatworkTransport(is_int($seconds) || is_float($seconds) ? (float) $seconds : 3.0);
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
            if ($handler instanceof Handler) {
                $handler->reportable(function (Throwable $e): void {
                    $this->app->make(Reporter::class)->report($e);
                });
            }
        });

        $this->listenToJobs();
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
    }
}
