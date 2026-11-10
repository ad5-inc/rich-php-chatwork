# rich-php-chatwork

Laravel の例外を Chatwork の部屋に知らせる部品。どんな例外かが知らせだけで分かるようにしつつ、個人情報になりうる値は送らない。

- 対応: PHP 8.5 / Laravel 13
- 送るところは [ad5jp/php-chatwork](https://github.com/ad5jp/php-chatwork) を使う

## 入れ方

Packagist には登録していない。composer.json に GitHub のリポジトリを書いて入れる（公開なので鍵は要らない）。

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ad5-inc/rich-php-chatwork" }
    ],
    "require": {
        "ad5-inc/rich-php-chatwork": "^0.1"
    }
}
```

ServiceProvider は自動で読まれ、記録する例外（`dontReport` に当たらないもの）を送る。ログへの記録はそのまま続く。

`.env` に送り先を書く。両方あるときだけ送る。

```dotenv
REPORT_CHATWORK_ACCESS_TOKEN=
REPORT_CHATWORK_ROOM_ID=
```

テストで送らないよう、`phpunit.xml` では両方を空にしておく（既定でも `testing` と `local` では送らない）。

## 設定

`php artisan vendor:publish --tag=rich-chatwork-config` で `config/rich-chatwork.php` を出して変える。

| キー | 既定 | 中身 |
| --- | --- | --- |
| `title` | `{app}（{env}）にて例外発生` | 件名。`{app}` は app.name、`{env}` は APP_ENV |
| `environments` | `null` | 送る環境の一覧。null ならどこでも |
| `silent_environments` | `['testing', 'local']` | 送り先があっても送らない環境 |
| `log_when_silent` | `false` | 送らない環境で、送る中身をログ（info）に書く |
| `throttle_seconds` | `60` | 同じ例外（クラスとファイル:行が同じ）を送り直すまでの秒数 |
| `cache_store` | `null`（既定のキャッシュ） | 間引きの印を置くキャッシュ |
| `context` | `request_id`・`request`・`command` | Request ID・リクエスト・コマンドを読む Context のキー |

利用者の出し方は、AppServiceProvider などで関数を渡して変える。既定は、既定のガードで照合が済んだ利用者の ID。

```php
use Ad5\RichPhpChatwork\Reporter;

Reporter::resolveUserUsing(fn (): ?string => auth('staff')->hasUser() ? 'スタッフ#' . auth('staff')->id() : null);
```

Request ID とリクエストは、入口のミドルウェアで `Context::add('request_id', …)`・`Context::add('request', 'GET /path')` のように文字で入れておく。バッチはコマンドの始まりで `Context::add('command', …)`。

## 送るもの

- 例外のクラス、起きた場所（ファイル:行）、Request ID、リクエスト（バッチはコマンド）、利用者
- 例外の種類ごとの決まった部分（下の表）
- アプリのコードで例外に最も近い行と前後 3 行（vendor の中で投げられたら、呼び出したアプリの行）
- 呼ばれた道筋（段ごとのクラス::メソッドとファイル:行。vendor の段はまとめる）

## 送らないもの

- 例外の文言。DB の例外は値を埋めた SQL を、メールの失敗は宛先を含むなど、個人情報が入りうる
- 道筋の引数の値（`getTraceAsString()` は php.ini しだいで引数の値を含むので使わない）

中身はアプリのログを Request ID で引いて見る。

## 例外の種類ごとに送る部分

| 例外 | 送るもの |
| --- | --- |
| `QueryException` | SQLSTATE、ドライバーの番号、接続の名前。値が長すぎる（22001）ときは列の名前 |
| `ErrorException` | 重さ（警告・通知など） |
| `TypeError` | 関数、引数の位置と名前、求める型、渡された型 |
| メモリ切れ（`FatalError`） | 上限 |
| スケジュールのコマンドの失敗 | コマンドの名前、終了コード |
| Google の API（`google/apiclient`）・Http クライアント | HTTP の状態、Google の reason（`notFound` など決まった語） |

文言から取るものは、種類ごとに決めた形を文言の先頭から末尾まで当て、名前の文字だけを取り出す。形が合わないときは何も出さない（PHP や Laravel の版で文言が変わっても、値が出ることはない）。

## 気をつけること

- **Chatwork が応答しないと止まる**: ad5jp/php-chatwork はタイムアウトを指定できない。送る処理を差し替えるときは `Ad5\RichPhpChatwork\Transport` を実装してコンテナに登録する
- **PHP 8.5 で非推奨の警告**: ad5jp/php-chatwork のコンストラクタ（暗黙の nullable）が、読み込むたびに deprecation を出す
- **DB の障害を知らせたいとき**: 既定のキャッシュが database だと、DB が落ちたときに間引きの印を置けない。そのときはプロセスの中だけで間引くので、`cache_store` を `file` か `redis` にしておくとよい

## 開発

```bash
composer install
composer test
composer stan
```

## ライセンス

MIT
