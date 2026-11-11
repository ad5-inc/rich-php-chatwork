# rich-php-chatwork

Laravel の例外を Chatwork の部屋に知らせる部品。どんな例外かが知らせだけで分かるようにしつつ、秘密や個人情報になりうる値は送らない。

- 対応: PHP 8.5 / Laravel 13
- 送るところは [ad5jp/php-chatwork](https://github.com/ad5jp/php-chatwork) を使う

## ゴール（重要順）

この部品が守ること。ぶつかったら上を優先する。直すときもこの順で判断する。

| 順 | ゴール | 満たす形 |
| --- | --- | --- |
| 1 | **エラー通知を黙殺しない** | 例外が起きたら、Chatwork への知らせか、ログの「送れなかった」記録のどちらかが必ず残る。Laravel の元の例外の記録を止めない |
| 2 | **知らせだけで何のエラーか分かる** | 件名で種類が分かる。**例外に入れた文字列は必ず出す**（省かない）。Request ID でログを引ける |
| 3 | **秘密を出さない** | パスワード・ハッシュ・トークン・鍵・接続先（ホスト・DB の利用者）を知らせに出さない。文言の中にあれば、文言は残してその値だけを伏せる |
| 4 | **重要な個人情報を出さない** | 形の分かる個人情報（メール・電話番号・マイナンバー・口座番号）を伏せる。氏名のように形の無い物は伏せきれないので、送り先の部屋に入れる人で守る |
| 5 | **読み手が誤って判断しない** | 場所・入口・回数・時刻が正しい |
| 6 | **部屋を壊さない・うるさくしない** | Chatwork の記法を逃がす。長さに上限を置く。同じ例外は間引く |
| 7 | **使う側に手間をかけない・壊さない** | 設定は送り先だけ。変わる所は「上げるとき」の節に書く |

ぶつかるときの決め方の例:

- 文言に秘密がある（2 と 3）→ 文言は出し、秘密の値だけを「（伏せた）」にする
- 伏せる処理に失敗した（1 と 3）→ その項目だけ省き、知らせは送る
- 本文の組み立てに失敗した（1 と 3・4）→ 値を含まない最小の知らせを送る
- 同じ例外が続く（1 と 6）→ 間引き、送らなかった回数を次の知らせに書く。元の例外はどれもログにある

## 入れ方

Packagist には登録していない。composer.json に GitHub のリポジトリを書いて入れる（公開なので鍵は要らない）。

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ad5-inc/rich-php-chatwork" }
    ],
    "require": {
        "ad5-inc/rich-php-chatwork": "^0.5"
    }
}
```

ServiceProvider は自動で読まれ、記録する例外（`dontReport` に当たらないもの）を送る。ログへの記録はそのまま続く。

`.env` に送り先を書く。両方あるときだけ送る。

```dotenv
REPORT_CHATWORK_ACCESS_TOKEN=
REPORT_CHATWORK_ROOM_ID=
```

`testing` と `local` では送らないので、テストと手元では値があっても送られない。

## 設定

**設定は送り先の 2 つ（`.env` の上の 2 行）だけ。** `config/rich-chatwork.php` はアプリに置かなくてよい。

ほかは部品が決めている。

| 項目 | 決めていること |
| --- | --- |
| 件名の頭 | `{アプリ名}（{環境}）`。アプリ名は `app.name`（`.env` の `APP_NAME`）、環境は `APP_ENV`。続けて「短いクラス名: 要約」が付く |
| 送らない環境 | `testing` と `local`。送り先があっても送らず、送る中身（伏せた後の本文）をログ（info）に書く |
| 送るときに待つ秒数 | 180 秒。届くことを優先する（画面の操作の中では、サーバーの時間の上限で先に切れることがある） |
| 文言の長さ | 表示幅 500 まで（全角は 2） |
| 同じ例外を送り直すまで | 60 秒（アプリの行・クラス・SQLSTATE が同じなら同じ例外） |
| 全体の上限 | 1 分に 20 件（例外と notice を合わせて） |
| 同じ知らせ（`notice()`）を送り直すまで | 1 時間 |
| 間引きの印の置き場 | ファイルのキャッシュ（`file`）。DB の障害のときも間引きが効く。testing の環境ではテストごとに消える `array` |
| 前後のコードを読むフォルダ | `app/`・`routes/` |
| 包む例外（剥いで中を見る） | `Illuminate\View\ViewException` |
| Context のキー | `request_id`・`request`・`command` |

利用者の出し方は、AppServiceProvider などで関数を渡して変える。既定は、既定のガードで照合が済んだ利用者の ID。内部の ID だけを返す（氏名・メールアドレスを返さない）。

```php
use Ad5\RichPhpChatwork\Reporter;

Reporter::resolveUserUsing(fn (): ?string => auth('staff')->hasUser() ? 'スタッフ#' . auth('staff')->id() : null);
```

Request ID は、入口のミドルウェアで `Context::add('request_id', …)` のように文字で入れておく。バッチはコマンドの始まりで `Context::add('command', …)`（名前だけを出す）。HTTP のリクエストは部品がルートのテンプレート（`PUT /api/employees/{employee}`）を読むので、入れなくてよい。`request` は、ルートを持たない入口（コンソールで動く独自の入口）で明示するときだけ使う。

キューのジョブは部品が自分で見分ける。ジョブの間は Context の Request ID をジョブ用の新しい ID に入れ替え（ログもその ID で引ける）、ディスパッチした側の ID を「発端の Request ID」として出す。ジョブが終われば、Laravel が例外を記録した後に元の ID に戻す。

sync のキューで動くジョブ（ShouldQueue を `dispatchSync` したものも含む）で失敗して呼び出し側（HTTP など）で記録されたときは、例外の記録は呼び出し側の ID で書かれる。そのとき知らせは「Request ID: {ジョブの ID}（ジョブの中のログ）」と「発端の Request ID: {呼び出し側の ID}（例外の記録はこの番号）」に分けて書く。呼び出し側に ID が無ければ、入口の終わり（コマンド・リクエストの終わり）までジョブの ID をそのまま残し、例外の記録もその番号で引ける。

ServiceProvider は例外のハンドラーの `reportable()` に登録する。コンソールで Collision がハンドラーを包んでいても登録される。`reportable()` を持たない独自のハンドラーのときは、ログに警告を 1 度だけ書いて登録しない。

## 例外ではない知らせ

不自然に多いログインの失敗など、例外ではないことも同じ部屋に送れる。送る条件は例外の知らせと同じで、同じキーは `notice_throttle_seconds` に 1 回だけ送る。行と枠（`code`）は文言と同じ規則で伏せて逃がすが、氏名のような形の無い個人情報は伏せられないので入れない。IP やログイン ID も入れず、「laravel.log を見る」と案内する。地の文の行が値の書き出しの形（`=>`・JSON・serialize など）を含むなら、その行は「（値の書き出しを含むので省いた。ログを見る）」になる。

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

- 件名は「アプリ（環境）短いクラス名: 要約」。要約は値を含まず、文言は入れない（スマホのロック画面に出るため）
- 入口はジョブ → コマンド → HTTP の順に見て、最初に当たった 1 つを出す

並び・件名の要約・アプリの行・入口・間引きの言い方・ほかの例は [docs/message-format.md](docs/message-format.md)。

## 送るもの・送らないもの

知らせは「どんな種類の例外かを知り、ログを引く鍵（Request ID）を渡す」ためのもの。伏せる前の文言・SQL・呼び出しの並びは、Laravel がいつもどおり laravel.log に書く。

送るもの:

- 件名（アプリ・環境・短いクラス名・種類ごとの要約）
- **例外の文言（例外に入れた文字列）。どんな文言でも省かずに出す。** 秘密と形の分かる個人情報は、その値だけを「（伏せた）」にする。値の書き出し（print_r・JSON など）や複数行の文言も、伏せる規則を当てて全部出す。写したプロセスの文言（`The command "…" failed.` など）は、引用符の中のコマンドラインだけを伏せる。長さは表示幅 500 まで
- DB・HTTP クライアント・外のコマンドの例外は、元の文言の代わりに部品が組み立て直した文言（SQL の値・応答の本文・コマンドラインを含まない。[組み立て直す例外](docs/redaction.md#組み立て直す例外)）
- 種類ごとの決まった部分のうち、件名に入らなかった物
- アプリの行と前後 2 行（`app/`・`routes/` の中のファイルだけ。形のはっきりした秘密と、文字列の値のパスワードなどは伏せる）
- 入口（ジョブの短いクラス名と試行回数／コマンドの名前／HTTP のメソッドとルートのテンプレートと利用者の内部 ID）、Request ID
- 呼び出しの並び（段ごとのクラス::メソッドとファイル:行。アプリの段を 10 段まで、vendor の段はまとめる）

送らないもの:

- DB の例外の SQL・値・接続先（Host・Port・Database）、HTTP クライアントの例外の応答の本文・URL の問い合わせ・利用者情報、プロセスの例外のコマンドライン
- 呼び出しの並びの引数の値（`getTraceAsString()` は php.ini しだいで引数の値を含むので使わない）
- サーバーの絶対パス（相対パスにする。匿名クラス・クロージャの名前の中も）

DB・HTTP クライアント・プロセスの例外の文言の組み立て方と、伏せる規則の全部は [docs/redaction.md](docs/redaction.md)。

**伏せる規則は「形のある秘密と個人情報」にしか当たらない。** 氏名・住所のような形の無い個人情報や、普通の語のキー（`name` など）の値は伏せられない。送り先の部屋には、文言を見てよい人（社内の開発の担当）だけを入れる。

アプリの例外を tinker の `--execute` で投げて試すと、アプリのファイルの行が無いので「アプリの行」と前後のコードは出ない（tinker がその場で作ったコードの中で投げるため）。形を確かめるときは、アプリのコードの中で投げる（開発用のルートやコマンドを使う）。

### 入れてよい値（使う側が渡す物）

| 入れる所 | 入れてよい値 |
| --- | --- |
| `Context` の `request_id` | ログを引くための番号 |
| `Context` の `command` | artisan のコマンドの名前（引数は出さない） |
| `resolveUserUsing()` の返す値 | 利用者の内部 ID |
| `notice()` の行・件名・枠 | 件数・種類などの決まった言葉。IP・ログイン ID・氏名・メールアドレスは入れない |

### 記法の逃がし

`[code]` の外の `[` `]` は全角にし、`[code]` の中は記法の始まりの形（`[toall]`・`[To:1]`・`[/code]` など）だけを全角にする。URL に `[toall]` を入れたリクエストで部屋の全員に知らせが飛ぶ、といったことは起きない。

## 届けるための仕組み

例外が起きたら、Chatwork への知らせか、ログの「送れなかった」記録のどちらかが必ず残るようにしている。どの仕組みも Laravel の元の例外の記録は止めない。

- 本文を組み立てられなければ、値を含まない最小の知らせ（件名と Request ID だけ）を送る
- 送れなければ、間引きの印を消してすぐ送り直せるようにし、部品の例外をログに書く
- 1 分に 20 件まで。超えた分は次の知らせに「ほかの例外: 03:00 から 15 件（QueryException 12・TypeError 3、…）」と足す
- 送る環境でトークンか部屋が空なら、最初の例外でログに警告を 1 度だけ書く

細かい動きは [docs/design.md](docs/design.md#失敗したときの動きゴール-1)。

## 上げるとき

版ごとの変更と「上げるときの注意」は [CHANGELOG](CHANGELOG.md) にある。0.4 から 0.5 へは、アプリの `config/rich-chatwork.php` を消す（送り先の `.env` はそのまま）。

## 気をつけること

- **本番の前に試験用の部屋で確かめる**: 試験用の部屋に 1 回送って見え方を見る（見る所は [docs/maintenance.md](docs/maintenance.md#本番の前に試験用の部屋で確かめる)）
- **間引きの最後の回数は届かない**: 「同じ例外: 前の知らせの後の 60 秒に、送らずに N 回」と「ほかの例外: N 件」は、次に送るときに添える。障害が止まると、最後のまとまりの回数は届かない。回数はおおよそで、file・array のキャッシュでは期限の無いキーができうる（次に送るときに消える）。時刻はアプリのタイムゾーン（`app.timezone`）で書く
- **送るときのタイムアウト**: ad5jp/php-chatwork はタイムアウトを指定できないので、送るところだけを部品の中で書き直し、180 秒で切っている。送る処理を差し替えるときは `Ad5\RichPhpChatwork\Transport` を実装してコンテナに登録する
- **PHP 8.5 で非推奨の警告**: ad5jp/php-chatwork のコンストラクタ（暗黙の nullable）が、読み込むたびに deprecation を出す
- **間引きの印はファイルに置く**: 置けなければ一時フォルダ（`sys_get_temp_dir()`）のファイルに落ちて間引くが、効くのは同じサーバーの中だけ。一時フォルダも使えなければプロセスの中だけになり、間引きと全体の上限がほとんど効かない

## 開発

```bash
composer install
composer test
composer stan
```

保守する人向けの文書（設計・本文の形・伏せる規則・保守とリリースの手順・設計判断の記録）は [docs/](docs/README.md) にある。

## ライセンス

MIT
