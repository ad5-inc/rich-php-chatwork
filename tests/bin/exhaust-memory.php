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

// メモリ切れの後に Laravel が動ける程度の空き（引数の KB）を、Laravel の後始末より先に返す。
// 部品が組み立てに使う量（数 MB）より小さくし、部品がメモリを取ると 2 度目のメモリ切れになる形を作る
$spare = str_repeat('x', 1024 * (int) ($argv[2] ?? 256));
register_shutdown_function(static function () use (&$spare): void {
    $spare = null;
});
// PHPUnit から動かすと APP_ENV=testing を引き継ぎ、Collision が入らない。本番のコンソールと同じ形にする
putenv('APP_ENV=production');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
$app = Application::create(basePath: null);
$app->detectEnvironment(static fn (): string => 'production');
// コンソールなので Collision が Laravel のハンドラーを包む。その形でも部品が登録されることも確かめる
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
// 小さく積み（大きさの決まった入れ物に 64KB ずつ）、メモリ切れの時点で空きがほとんど残らないようにする
$blocks = new SplFixedArray(4096);
for ($index = 0; ; $index++) {
    $blocks[$index] = str_repeat('x', 64 * 1024);
}
