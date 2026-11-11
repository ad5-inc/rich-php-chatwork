<?php

declare(strict_types=1);

return [
    // 送り先。両方あるときだけ送る。テスト（phpunit.xml）では空にしておく
    'access_token' => env('REPORT_CHATWORK_ACCESS_TOKEN'),
    'room_id' => env('REPORT_CHATWORK_ROOM_ID'),

    // 件名の頭。{app} は app.name、{env} は APP_ENV に置き換わる。続けて「短いクラス名: 要約」が付く
    'title' => '{app}（{env}）',

    // 送る環境。null ならどこでも（silent_environments を除く）
    'environments' => null,

    // 送り先があっても送らない環境
    'silent_environments' => ['testing', 'local'],

    // 送らない環境で、送る中身（伏せた後の本文）をログ（info）に書くか
    'log_when_silent' => false,

    // Chatwork に送るときに待つ秒数。応答しないとき、例外のハンドラーが止まり続けないよう切る
    'timeout_seconds' => 3,

    // 形の分からない例外の文言を、伏せて送るか。伏せる規則は秘密の形（パスワード・トークンなど）に当たるが、
    // 氏名のような形の無い個人情報は伏せられない。送るのは、送り先の部屋に入る人が見てよいと決めたときだけ。
    // DB・HTTP クライアントの例外は、この設定に関係なく部品が組み立て直した文言を送る
    'send_message' => false,

    // 文言の長さの上限（表示幅。全角は 2）
    'message_max_length' => 500,

    // 文言に足す伏せる規則（正規表現の一覧）。当たった所を「（伏せた）」にする。既定の規則は外せない
    'message_redact_patterns' => [],

    // 包む例外。外側から剥いで、中の例外を主に見る（件名・メッセージ・アプリの行・間引きのキー）
    'wrapper_exceptions' => [
        'Illuminate\View\ViewException',
    ],

    // 前後のコードを読むフォルダ（base_path() から見た相対パス）。ここの外のファイルは読まない
    'snippet_paths' => ['app/', 'routes/'],

    // 同じ例外（アプリの行・クラス・SQLSTATE が同じ）を送り直すまでの秒数
    'throttle_seconds' => 60,

    // 例外ではない知らせ（Reporter::notice()）の件名の頭。{app}・{env} は件名と同じ。続けて「知らせ: 」と notice() の $title が付く
    'notice_title' => '{app}（{env}）',

    // 同じ知らせ（notice() の $throttleKey が同じ）を送り直すまでの秒数
    'notice_throttle_seconds' => 3600,

    // 間引きの印と回数を置くキャッシュ。null なら既定。DB の障害を知らせたいなら file か redis にする
    'cache_store' => null,

    // Context から読むキー。入口のミドルウェア・コマンドが文字で入れておく。
    // request は、ルートを持たない入口（コンソールで動く独自の入口）で使う側が明示するときだけ読む
    'context' => [
        'request_id' => 'request_id',
        'request' => 'request',
        'command' => 'command',
    ],
];
