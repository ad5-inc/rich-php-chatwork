<?php

declare(strict_types=1);

// 設定は送り先の 2 つと、知らせの部屋・件名の名前の上書き（どちらも任意）だけ。両方あるときだけ送る（テストと手元では、値があっても送らずにログに書く）。
// 件名の頭（app.name と APP_ENV）・間引き・待つ秒数などは部品が決める（README「決めていること」）
return [
    'access_token' => env('REPORT_CHATWORK_ACCESS_TOKEN'),
    'room_id' => env('REPORT_CHATWORK_ROOM_ID'),

    // 例外ではない知らせ（notice）を送る部屋。空なら room_id に送る。管理者の追加など、エラーではない物を別の部屋で受けるときに入れる
    'notice_room_id' => env('REPORT_CHATWORK_NOTICE_ROOM_ID'),

    // 件名の名前。空なら APP_NAME。APP_NAME を変えられない（ログインのクッキーの名前などに使っている）ときに入れる
    'app_name' => env('REPORT_CHATWORK_APP_NAME'),
];
