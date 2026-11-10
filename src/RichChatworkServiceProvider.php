<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class RichChatworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rich-chatwork.php', 'rich-chatwork');

        $this->app->bindIf(Transport::class, PhpChatworkTransport::class, true);
        $this->app->bindIf(Source::class, static fn (Application $app): Source => new Source($app->basePath()), true);
        $this->app->singleton(Reporter::class, static fn (Application $app): Reporter => new Reporter(
            $app,
            $app->make('config'),
            $app->make('cache'),
            $app->make(Transport::class),
            $app->make(Source::class),
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
    }
}
