<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\RichChatworkServiceProvider;
use Ad5\RichPhpChatwork\Source;
use Ad5\RichPhpChatwork\Transport;
use Illuminate\Foundation\Application as LaravelApplication;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;
use Throwable;

abstract class TestCase extends Orchestra
{
    protected FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeTransport();
        // testbench の base_path() は vendor の中の骨組み。このリポジトリをアプリとみなす（tests がアプリ、vendor が vendor）
        $this->useSource(self::source());
        $this->app?->instance(Transport::class, $this->transport);
        // このリポジトリでは tests がアプリのコード
        config(['rich-chatwork.snippet_paths' => ['tests/']]);
        // 間引きの印はテストごとに消える array に置く（既定のファイルだと、前のテストの印が残って送られない）
        config(['rich-chatwork.cache_store' => 'array']);
    }

    protected function useEnvironment(string $environment): void
    {
        $this->app?->detectEnvironment(static fn (): string => $environment);
    }

    protected function useSource(Source $source): void
    {
        $this->app?->instance(Source::class, $source);
        $this->app?->forgetInstance(Reporter::class);
    }

    /**
     * testbench はコンソールで動くので、HTTP の入口を見るテストだけ切り替える
     */
    protected function runningOverHttp(): void
    {
        $app = $this->app;
        if ($app instanceof LaravelApplication) {
            new ReflectionProperty(LaravelApplication::class, 'isRunningInConsole')->setValue($app, false);
        }
    }

    protected static function source(): Source
    {
        return new Source(dirname(__DIR__));
    }

    /**
     * 例外の投げた場所と呼び出しの並びを、作り物のアプリの形に置き換える
     *
     * @template T of Throwable
     *
     * @param T $e
     * @param list<array<string, mixed>> $trace
     *
     * @return T
     */
    protected static function fabricate(Throwable $e, string $file, int $line, array $trace): Throwable
    {
        $class = $e instanceof \Exception ? \Exception::class : \Error::class;
        new ReflectionProperty($class, 'file')->setValue($e, $file);
        new ReflectionProperty($class, 'line')->setValue($e, $line);
        new ReflectionProperty($class, 'trace')->setValue($e, $trace);

        return $e;
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RichChatworkServiceProvider::class];
    }
}
