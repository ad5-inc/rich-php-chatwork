<?php

declare(strict_types=1);

// 予備の一時フォルダを、別のプロセスから使えるかを見る（ReporterTest から動かす）。
// 間引けたら throttled、送ってよいなら sent を出す。引数は一時フォルダの元にするパスと autoload.php（スクリプトを別の場所へ写して動かすため）
use Ad5\RichPhpChatwork\Throttle;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Orchestra\Testbench\Foundation\Application;

require $argv[2] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = Application::create(basePath: null);
$broken = new class () implements Factory {
    public function store($name = null): Repository
    {
        throw new RuntimeException('cache is down');
    }
};
unset($app);
$throttle = new Throttle(new Illuminate\Config\Repository([]), $broken, Throttle::fallbackDirectory($argv[1] ?? ''));
$throttle->begin();
echo $throttle->attempt('rich-chatwork:probe', 60) === null ? 'throttled' : 'sent';
