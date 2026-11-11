<?php

declare(strict_types=1);

// 設定は送り先の 2 つだけ。両方あるときだけ送る（テストと手元では、値があっても送らずにログに書く）。
// 件名の頭（app.name と APP_ENV）・間引き・待つ秒数などは部品が決める（README「決めていること」）
return [
    'access_token' => env('REPORT_CHATWORK_ACCESS_TOKEN'),
    'room_id' => env('REPORT_CHATWORK_ROOM_ID'),
];
