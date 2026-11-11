# rich-php-chatwork

Laravel の例外を Chatwork の部屋に知らせる部品。どんな例外かが知らせだけで分かるようにしつつ、秘密や個人情報になりうる値は送らない。

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
| `title` | `{app}（{env}）` | 件名の頭。`{app}` は app.name、`{env}` は APP_ENV。続けて「短いクラス名: 要約」が付く |
| `environments` | `null` | 送る環境の一覧。null ならどこでも |
| `silent_environments` | `['testing', 'local']` | 送り先があっても送らない環境 |
| `log_when_silent` | `false` | 送らない環境で、送る中身（伏せた後の本文）をログ（info）に書く |
| `timeout_seconds` | `3` | Chatwork に送るときに待つ秒数 |
| `send_message` | `false` | 形の分からない例外の文言を、伏せて送る（下の「送るもの・送らないもの」） |
| `message_max_length` | `500` | 文言の長さの上限（表示幅。全角は 2） |
| `message_redact_patterns` | `[]` | 文言に足す伏せる規則（正規表現の一覧）。既定の規則は外せない |
| `wrapper_exceptions` | `['Illuminate\View\ViewException']` | 包む例外。剥いで中の例外を主に見る |
| `snippet_paths` | `['app/', 'routes/']` | 前後のコードを読むフォルダ。ここの外のファイルは読まない |
| `throttle_seconds` | `60` | 同じ例外（アプリの行・クラス・SQLSTATE が同じ）を送り直すまでの秒数 |
| `cache_store` | `null`（既定のキャッシュ） | 間引きの印と回数を置くキャッシュ |
| `context` | `request_id`・`request`・`command` | Request ID・リクエスト・コマンドを読む Context のキー |
| `notice_title` | `{app}（{env}）` | 例外ではない知らせの件名の頭。続けて「知らせ: 」と `notice()` の件名が付く |
| `notice_throttle_seconds` | `3600` | 同じ知らせ（`notice()` のキーが同じ）を送り直すまでの秒数 |

利用者の出し方は、AppServiceProvider などで関数を渡して変える。既定は、既定のガードで照合が済んだ利用者の ID。内部の ID だけを返す（氏名・メールアドレスを返さない）。

```php
use Ad5\RichPhpChatwork\Reporter;

Reporter::resolveUserUsing(fn (): ?string => auth('staff')->hasUser() ? 'スタッフ#' . auth('staff')->id() : null);
```

Request ID は、入口のミドルウェアで `Context::add('request_id', …)` のように文字で入れておく。バッチはコマンドの始まりで `Context::add('command', …)`（名前だけを出す）。HTTP のリクエストは部品がルートのテンプレート（`PUT /api/employees/{employee}`）を読むので、入れなくてよい。`request` は、ルートを持たない入口（コンソールで動く独自の入口）で明示するときだけ使う。

キューのジョブは部品が自分で見分ける。ジョブの間は Context の Request ID をジョブ用の新しい ID に入れ替え（ログもその ID で引ける）、ディスパッチした側の ID を「発端の Request ID」として出す。ジョブが終われば元の ID に戻す。

## 例外ではない知らせ

不自然に多いログインの失敗など、例外ではないことも同じ部屋に送れる。送る条件は例外の知らせと同じで、同じキーは `notice_throttle_seconds` に 1 回だけ送る。行と枠（`code`）は文言と同じ規則で伏せて逃がすが、氏名のような形の無い個人情報は伏せられないので入れない。IP やログイン ID も入れず、「laravel.log を見る」と案内する。

```php
app(\Ad5\RichPhpChatwork\Reporter::class)->notice('ログインの失敗が多い', ['直近 10 分の失敗: 52 回', 'IP やログイン ID は laravel.log を見る'], 'signin:all');

// 枠を使いたいとき。中身は部品が逃がす
app(\Ad5\RichPhpChatwork\Reporter::class)->notice('取り込みの失敗', ['3 件が取り込めなかった'], 'import', code: $summary);
```

## 本文の形

```text
[info][title]example-app（production）QueryException: SQLSTATE 22001・列 name[/title][code]SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 (Connection: mysql, SQL: （ログ）)[/code]アプリの行: app/UseCase/Actions/Employee/UpdateAction.php:42（vendor の Connection.php:825 で投げた）
[code]  40 | $param = EmployeeParam::from($request);
  41 |
> 42 | $employee->update($param->toArray());
  43 |
  44 | return $employee;[/code]リクエスト: PUT /api/employees/{employee} ／ 利用者: 42
Request ID: 6f1c…（中身はログをこの番号で引く）
同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 12 回
[hr][code]（vendor の 3 段）
#3 app/UseCase/Actions/Employee/UpdateAction.php:42 Illuminate\Database\Eloquent\Model->update()
#4 app/Http/Controllers/EmployeeController.php:31 App\UseCase\Actions\Employee\UpdateAction->__invoke()
（vendor の 38 段）[/code][/info]
```

- 件名は「アプリ（環境）短いクラス名: 要約」。要約は種類ごとの値を含まない部分から作り、文言は入れない（スマホのロック画面に出るため）
- 包む例外（ViewException など）は剥いで、中の例外を主に見る。剥いだ物は「包んだもの」、内側の previous は「原因」（クラスと場所だけ、3 行まで）に出す
- 入口はジョブ → コマンド → HTTP の順に見て、最初に当たった 1 つを出す
- 本文は 30,000 文字まで。超えたら呼び出しの並び、前後のコードの順に落とす

## 送るもの・送らないもの

知らせは「どんな種類の例外かを知り、ログを引く鍵（Request ID）を渡す」ためのもの。伏せる前の文言・SQL・呼び出しの並びは、Laravel がいつもどおり laravel.log に書く。

送るもの:

- 件名（アプリ・環境・短いクラス名・種類ごとの要約）
- 形の分かる例外の、部品が組み立て直した文言（下の表。`send_message` に関係なく送る）
- `send_message` が true のときだけ、形の分からない例外の文言を伏せた物
- 種類ごとの決まった部分のうち、件名に入らなかった物
- アプリの行と前後 2 行（`snippet_paths` の中のファイルだけ。形のはっきりした秘密と、文字列の値のパスワードなどは伏せる）
- 入口（ジョブの短いクラス名と試行回数／コマンドの名前／HTTP のメソッドとルートのテンプレートと利用者の内部 ID）、Request ID
- 呼び出しの並び（段ごとのクラス::メソッドとファイル:行。アプリの段を 10 段まで、vendor の段はまとめる）

送らないもの:

- DB の例外の SQL・値・接続先（Host・Port・Database）、HTTP クライアントの例外の応答の本文・URL の問い合わせ・利用者情報
- 呼び出しの並びの引数の値（`getTraceAsString()` は php.ini しだいで引数の値を含むので使わない）
- サーバーの絶対パス（相対パスにする。匿名クラス・クロージャの名前の中も）

### 組み立て直す例外

| 例外 | 送る文言 |
| --- | --- |
| `QueryException` | PDO の文言が許可リストの形（値が長すぎる・重複・値の形の誤り・列が無い・構文・外部キー・null）に当たったときだけ、値を伏せて出す。当たらなければ SQLSTATE とドライバーの番号だけ。接続の失敗は「認証に失敗」「名前が引けない」「接続できない」の固定の言葉 |
| `RequestException` | `HTTP {状態コード}` と、取れたときだけメソッド・スキームとホスト・パス。パスは英小文字の段と `v1` のような段だけ残し、ほかの段（ID・Webhook の秘密）は `{…}` にする |
| `ConnectionException` | `接続できない: {スキームとホスト}` |

組み立てに失敗したら「（組み立てられなかったので省いた。ログを見る）」にする。元の文言には戻さない。

### 伏せる規則

文言・notice の行には次を当て、当たった所を「（伏せた）」にする（長さや頭の文字は残さない）。伏せる処理が失敗したら、その項目を「（伏せられなかったので省いた。ログを見る）」にする。

- 別の例外が写した DB の文言の SQL と値
- JWT、パスワードのハッシュ（`$2y$`・`$argon2id$`）、`Bearer`・`Basic` の後ろ
- URL の利用者情報（`//user:pass@`）と `?` 以降、メールアドレス
- `password`・`token`・`secret`・`api_key`・`cookie`・`session` などを含むキーの値（`password_confirmation`・`remember_token` にも当たる。`session_lifetime: 120` のような伏せなくてよい値も伏せる）
- 32 文字以上の 16 進と、数字を 3 個以上含む 32 文字以上の base64（UUID・snake_case の識別子・クラス名・ファイル名の一部は外す）
- `message_redact_patterns` で足した規則

**伏せる規則は「形のある秘密」にしか当たらない。** 氏名・住所のような形の無い個人情報は伏せられない。`send_message` を true にするのは、送り先の部屋に入る人が文言を見てよいと決めたときだけにする。

### 入れてよい値（使う側が渡す物）

| 入れる所 | 入れてよい値 |
| --- | --- |
| `Context` の `request_id` | ログを引くための番号 |
| `Context` の `command` | artisan のコマンドの名前（引数は出さない） |
| `resolveUserUsing()` の返す値 | 利用者の内部 ID |
| `notice()` の行・件名・枠 | 件数・種類などの決まった言葉。IP・ログイン ID・氏名・メールアドレスは入れない |

### 記法の逃がし

`[code]` の外の `[` `]` は全角の `［` `］` にする。`[code]` の中は記法の始まりの形（`[toall]`・`[To:1]`・`[/code]` など、`[` の後ろが英字か `/`）だけを全角にし、`['key']`・`[$i]` は残す。URL に `[toall]` を入れたリクエストで部屋の全員に知らせが飛ぶ、といったことは起きない。

## 気をつけること

- **本番の前に試験用の部屋で確かめる**: 試験用の部屋に 1 回送り、全角にした `［To:…］` が宛先として効かないこと・全角の `［` の見え方、`[hr]` が `[info]` の中で横線になること、スマホのアプリで `[code]` の長い 1 行が折り返すか、件名がスマホの通知のプレビューでどこまで見えるかを見る
- **間引きの最後の回数は届かない**: 「同じ例外: 前の知らせの後の 60 秒に、送らずに N 回」は、次に送るときに添える。障害が止まると、最後のまとまりの回数は届かない。回数はおおよそで、file・array のキャッシュでは期限の無いキーができうる（次に送るときに消える）
- **送るときのタイムアウト**: ad5jp/php-chatwork はタイムアウトを指定できないので、送るところだけを部品の中で書き直し、`timeout_seconds`（既定 3 秒）で切っている。送る処理を差し替えるときは `Ad5\RichPhpChatwork\Transport` を実装してコンテナに登録する
- **PHP 8.5 で非推奨の警告**: ad5jp/php-chatwork のコンストラクタ（暗黙の nullable）が、読み込むたびに deprecation を出す
- **DB の障害を知らせたいとき**: 既定のキャッシュが database だと、DB が落ちたときに間引きの印を置けない。そのときはプロセスの中だけで間引き（間引いた回数は出さない）、`cache_store` を `file` か `redis` にしておくとよい
- **0.3.0 から上げるとき**: `title` は件名の頭だけになった（既定 `{app}（{env}）`）。「にて例外発生」などを足していたら外す。`message_max_length` は文字数ではなく表示幅になり、既定は 500

## 開発

```bash
composer install
composer test
composer stan
```

## ライセンス

MIT
