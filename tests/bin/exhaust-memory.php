<?php

declare(strict_types=1);

// 別のプロセスでメモリ切れを起こす（MemoryExhaustionTest から動かす）。引数はログのファイル
use Ad5\RichPhpChatwork\RichChatworkServiceProvider;
use Ad5\RichPhpChatwork\Source;
use Ad5\RichPhpChatwork\Tests\FakeTransport;
use Ad5\RichPhpChatwork\Transport;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$log = $argv[1] ?? '';
$app = Application::create(basePath: null);
$app->detectEnvironment(static fn (): string => 'production');
$app->make('config')->set([
    'app.name' => 'Probe',
    'logging.default' => 'single',
    'logging.channels.single' => ['driver' => 'single', 'path' => $log, 'level' => 'debug'],
]);
$app->instance(Transport::class, new FakeTransport());
$app->instance(Source::class, new Source(dirname(__DIR__, 2)));
$app->register(RichChatworkServiceProvider::class);
$app->make('config')->set(['rich-chatwork.access_token' => 'probe-token', 'rich-chatwork.room_id' => '12345', 'rich-chatwork.snippet_paths' => ['tests/']]);

ini_set('memory_limit', '64M');
// 小さく積み（つないだ配列。配列を伸ばすと大きく取り直すので使わない）、メモリ切れの時点で空きがほとんど残らないようにする
$node = null;
while (true) {
    $node = [$node, str_repeat('x', 200)];
}
