<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\RichChatworkServiceProvider;
use Ad5\RichPhpChatwork\Source;
use Ad5\RichPhpChatwork\Transport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeTransport();
        // testbench の base_path() は vendor の中の骨組み。このリポジトリをアプリとみなす（tests がアプリ、vendor が vendor）
        $this->app?->instance(Source::class, self::source());
        $this->app?->instance(Transport::class, $this->transport);
        $this->app?->forgetInstance(Reporter::class);
    }

    protected function useEnvironment(string $environment): void
    {
        $this->app?->detectEnvironment(static fn (): string => $environment);
    }

    protected static function source(): Source
    {
        return new Source(dirname(__DIR__));
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RichChatworkServiceProvider::class];
    }
}
