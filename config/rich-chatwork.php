<?php

declare(strict_types=1);

return [
    // 送り先。両方あるときだけ送る。テスト（phpunit.xml）では空にしておく
    'access_token' => env('REPORT_CHATWORK_ACCESS_TOKEN'),
    'room_id' => env('REPORT_CHATWORK_ROOM_ID'),

    // 件名。{app} は app.name、{env} は APP_ENV に置き換わる
    'title' => '{app}（{env}）にて例外発生',

    // 送る環境。null ならどこでも（silent_environments を除く）
    'environments' => null,

    // 送り先があっても送らない環境
    'silent_environments' => ['testing', 'local'],

    // 送らない環境で、送る中身をログ（info）に書くか
    'log_when_silent' => false,

    // 同じ例外（クラスとファイル:行が同じ）を送り直すまでの秒数
    'throttle_seconds' => 60,

    // 間引きの印を置くキャッシュ。null なら既定。DB の障害を知らせたいなら file か redis にする
    'cache_store' => null,

    // Context から読むキー。入口のミドルウェア・コマンドが文字で入れておく
    'context' => [
        'request_id' => 'request_id',
        'request' => 'request',
        'command' => 'command',
    ],
];
