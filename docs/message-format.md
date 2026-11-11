# 本文の形

例外の知らせと notice の本文の仕様。値の伏せ方は [redaction.md](redaction.md)、組み立ての流れは [design.md](design.md)。形は `tests/ExamplesTest.php`（例）と `tests/ReporterTest.php`（並び・長さ・間引き）で固定している。

## 並び

```text
[info][title]{件名}[/title]
[code]{メッセージ}[/code]                    ← 形の分かる例外は必ず。ほかは send_message のときだけ
種類: {件名に入らなかった項目}                ← 無ければ出さない
包んだもの: …                                 ← あるときだけ
原因: …                                       ← あるときだけ（3 行まで）
アプリの行: {相対パス:行}（{補足}）
[code]{前後のコード}[/code]                   ← 読める所だけ
{入口の行}
Request ID: {値}（中身はログをこの番号で引く）
発端の Request ID: {値}                       ← ジョブで、出した側の ID があるときだけ
同じ例外: …                                   ← 間引いた回数が 0 でないとき
ほかの例外: …                                 ← 全体の上限で送らなかった分があるとき
[hr][code]{呼び出しの並び}[/code][/info]
```

- 枠（`[code]`・`[hr]`）の前後に改行を足さない。地の文の行は、後ろに何か続くときだけ改行で終える（`[/title][code]`・`[/code]アプリの行:`・`アプリの行: …\n[code]`）
- 「種類」の項目は ` ／ ` でつなぐ。件名の要約と、組み立てた文言に入った項目は重ねて出さない
- 「包んだもの」は剥いだ例外の短い名前を `・` でつなぎ、ビューが分かれば「（ビュー resources/views/x.blade.php）」を足す（入れ子なら一番内側）
- 「原因」は `原因: クラス（相対パス:行）`。文言は出さない。QueryException の中の PDOException は出さない
- 呼び出しの並びが空なら、横線と枠を出さず「呼び出しの並び: 取れない」
- 本文は 30,000 文字まで。超えたら呼び出しの並びを「呼び出しの並び: 長いので省いた（ログを見る）」に置き換え、まだ超えれば前後のコードを落とす
- Request ID が無ければ「Request ID: （無し）」

### 項目の長さ（表示幅。全角は 2）

| 項目 | 上限 |
| --- | --- |
| 件名 | 80（切るのは要約の後ろから） |
| メッセージ | `message_max_length`（既定 500） |
| リクエスト | 300 |
| Request ID・利用者・コマンド | 100 |
| 種類・包んだもの・原因・アプリの行・notice の行と枠の 1 行 | 500 |
| 前後のコードの 1 行 | 160 |

## 件名

`{title}{短いクラス名}: {要約}`。`title` の既定は `{app}（{env}）`。要約が無ければ `: ` も付けない。文言は入れない（スマホのロック画面に出るため）。

短いクラス名は名前空間を外した物。PHP の組み込みの例外と同じ名前（`Exception`・`RuntimeException` など）なら 1 段上を付ける（`Service\Exception`）。匿名クラスは `RuntimeException@anonymous`。

| 例外 | 要約 |
| --- | --- |
| `QueryException` | `SQLSTATE 42S22`。22001 は `SQLSTATE 22001・列 name`。接続の失敗は `SQLSTATE HY000・接続できない` |
| `ErrorException` | `PHP の警告（E_WARNING）` など |
| `TypeError` | `SyncService::run() 引数 #1 int←null` |
| `FatalError`（メモリ切れ） | `メモリ切れ（上限 512 MB）` |
| スケジュールのコマンドの失敗（素の `Exception`） | `スケジュール report:daily・終了コード 1`。名前が取れなければ `スケジュールのコマンドの失敗・終了コード 1` |
| `RequestException` | `HTTP 503` |
| `ConnectionException` | `接続できない` |
| Google の API | `HTTP 403・reason rateLimitExceeded` |

文言から取る物は、種類ごとの形を文言の先頭から末尾まで当て、名前の文字だけを取る。形が合わなければ要約を出さない。

## アプリの行

| 場合 | 出す物 |
| --- | --- |
| 主に見る例外をアプリの中で投げた | `app/X.php:42` |
| vendor の中で投げた | 呼び出しの並びで一番近いアプリの段に「（vendor の Connection.php:825 で投げた）」 |
| `TypeError` で文言に `called in` がある | 呼び出した側（trace[0]）に「（定義は SyncService.php:15）」 |
| コンパイル済みのビューで投げた | 一番近いアプリの段に「（ビュー resources/views/x.blade.php の中で投げた）」 |
| アプリの段が無い | 「無し（vendor の X.php:N で投げた）」。ビューが分かれば「無し（ビュー … の中）」 |

vendor と `storage/framework/views/` はアプリの段に数えない。前後のコードは前後 2 行で、`snippet_paths`（既定 `app/`・`routes/`）の中のファイルだけ読む。

## 入口

上から見て、最初に当たった 1 つを出す。

| 入口 | 判定 | 出す物 |
| --- | --- | --- |
| ジョブ | 部品がジョブの印を付けている | `ジョブ: SyncEmployeeJob（試行 2 回目）` |
| コマンド | Context に `command` がある | `コマンド: report:daily`（最初の語だけ） |
| HTTP | コンソールで動いていない | `リクエスト: PUT /api/employees/{employee} ／ 利用者: 42`。ルートが決まる前は `PUT（ルート未確定）`。利用者がいなければ `未ログイン` |
| ほかのコンソールの入口 | Context に `request` がある | `リクエスト: {値}` |

ジョブの Request ID は部品が付けた新しい ID で、出した側の ID は「発端の Request ID」に出る（[design.md](design.md#ジョブの入口と-request-id)）。

## 間引きと上限の言い方

```text
同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 12 回
同じ知らせ: 前の知らせ（11/10 08:00）の後の 1 時間に、送らずに 1 回
ほかの例外: 15 件（QueryException 12・TypeError 3、上限で送らなかった。ログを見る）
```

- 時刻はアプリのタイムゾーン（`app.timezone`）。当日でなければ日付を付ける
- 秒数は `throttle_seconds`（notice は `notice_throttle_seconds`）。60 秒までは秒、それを超えたら分・時間（`90` は `1 分 30 秒`、`3600` は `1 時間`）
- 前の時刻が取れなければ「前の知らせの後の …」
- キャッシュが使えず、プロセスの中だけで間引いているときは「同じ例外」の行を出さない
- 「ほかの例外」は例外と notice を合わせて数え、多い順に 3 つまで名前を出す。notice は `知らせ` という名前で数える

## notice

```text
[info][title]{notice_title}知らせ: {タイトル}[/title]{行}
{行}
[code]{code}[/code]{同じ知らせ・ほかの例外}[/info]
```

- 行と枠は伏せて逃がす。行は 1 行 500 まで
- 30,000 文字を超えたら行を後ろから落とし、「（以下 N 行は省いた）」を足す

## 最小の知らせ

本文を組み立てられなかったときに送る（[design.md](design.md#失敗したときの動きゴール-1)）。

```text
[info][title]example-app（production）RuntimeException: 知らせを組み立てられなかった[/title]Request ID: req-1（中身はログをこの番号で引く）[/info]
[info][title]example-app（production）知らせ: 知らせを組み立てられなかった[/title]Request ID: req-1（中身はログをこの番号で引く）[/info]
```

件名の頭と Request ID が取れなければ省く。

## 例

### DB の値の長さの失敗（HTTP）

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

### ビューの中の DB の例外

```text
[info][title]example-app（production）QueryException: SQLSTATE 42S22[/title][code]SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nme' in 'where clause' (Connection: mysql, SQL: （ログ）)[/code]包んだもの: ViewException（ビュー resources/views/employees/show.blade.php）
アプリの行: app/Models/Employee.php:120（vendor の Connection.php:825 で投げた）
[code]…[/code]リクエスト: GET /employees/{employee} ／ 利用者: 7
Request ID: …（中身はログをこの番号で引く）
[hr][code]…[/code][/info]
```

### ジョブの中の TypeError（`send_message` が true）

```text
[info][title]example-app（production）TypeError: SyncService::run() 引数 #1 int←null[/title][code]App\Services\Employee\SyncService::run(): Argument #1 ($employeeId) must be of type int, null given, called in app/Jobs/SyncEmployeeJob.php on line 28[/code]種類: 引数の名前: $employeeId
アプリの行: app/Jobs/SyncEmployeeJob.php:28（定義は SyncService.php:15）
[code]…[/code]ジョブ: SyncEmployeeJob（試行 2 回目）
Request ID: …（中身はログをこの番号で引く）
発端の Request ID: …
[hr][code]…[/code][/info]
```

### 外の API の失敗（Webhook）

```text
[info][title]example-app（production）RequestException: HTTP 404[/title][code]HTTP 404 POST https://hooks.slack.com/services/{…}/{…}/{…}[/code]アプリの行: app/Services/Notify/SlackNotifier.php:40（vendor の PendingRequest.php:1100 で投げた）
[code]…[/code]コマンド: report:daily
Request ID: …（中身はログをこの番号で引く）
[hr][code]…[/code][/info]
```

### メモリ切れ（呼び出しの並びが取れない）

```text
[info][title]example-app（production）FatalError: メモリ切れ（上限 512 MB）[/title]アプリの行: 無し（vendor の Collection.php:512 で投げた）
コマンド: report:daily
Request ID: …（中身はログをこの番号で引く）
呼び出しの並び: 取れない[/info]
```

### notice

```text
[info][title]example-app（production）知らせ: ログインの失敗が多い[/title]直近 10 分の失敗: 52 回
IP やログイン ID は laravel.log を見る[/info]
```
