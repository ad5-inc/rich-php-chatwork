# 本文の形

例外の知らせと notice の本文の仕様。値の伏せ方は [redaction.md](redaction.md)、組み立ての流れは [design.md](design.md)。形は `tests/ExamplesTest.php`（例）と `tests/ReporterTest.php`（並び・長さ・間引き）で固定している。

## 並び

```text
[info][title]{件名}[/title]
[code]{メッセージ}[/code]                    ← 文言があれば必ず（形の分かる例外は組み立て直した物、ほかは伏せた物。省かない）
説明: {何が起きたか＋見る所}                  ← 番号などが表に当たったときだけ
SQL の形（値は ?）:                           ← DB の例外（MySQL・MariaDB の接続）で、形を組み立てられたときだけ
[code]{SQL の形}[/code]
種類: {件名に入らなかった項目}                ← 無ければ出さない
包んだもの: …                                 ← あるときだけ
原因: …                                       ← あるときだけ（3 行まで）
アプリの行: {相対パス:行}（{補足}）
[code]{前後のコード}[/code]                   ← 読める所だけ
{入口の行}
Request ID: {値}（中身はログをこの番号で引く）
発端の Request ID: {値}                       ← ジョブで、出した側の ID があるときだけ（sync の場合は「入口」の節）
同じ例外: …                                   ← 間引いた回数か、上限で送らなかった回数が 0 でないとき
ほかの例外: …                                 ← 全体の上限で送らなかった分（ほかのキーの物）があるとき
[hr][code]{呼び出しの並び}[/code]rich-php-chatwork {版}[/info]   ← いつも最後の行
```

- 枠（`[code]`・`[hr]`）の前後に改行を足さない。地の文の行は、後ろに何か続くときだけ改行で終える（`[/title][code]`・`[/code]アプリの行:`・`アプリの行: …\n[code]`）
- 「種類」の項目は ` ／ ` でつなぐ。件名の要約と、組み立てた文言に入った項目は重ねて出さない
- 「包んだもの」は剥いだ例外の短い名前を `・` でつなぎ、ビューが分かれば「（ビュー resources/views/x.blade.php）」を足す（入れ子なら一番内側）
- 「原因」は `原因: クラス（相対パス:行）`。文言は出さない。QueryException の中の PDOException は出さない
- 呼び出しの並びが空なら、横線と枠を出さず「呼び出しの並び: 取れない」
- 「説明」の行は決まった文だけ（下の「番号に添える言葉」）。表に当たらなければ出さない
- SQL の形を出すときは、メッセージの末尾を `SQL: 下の枠` にする。出さないとき（MySQL・MariaDB 以外の接続、組み立ての失敗）は今までどおり `SQL: （ログ）` か `(Connection: …)` だけ
- 本文は 30,000 文字まで（版の行は落とさない）。超えたら呼び出しの並びを「呼び出しの並び: 長いので省いた（ログを見る）」に、まだ超えれば前後のコードを「前後のコード: 長いので省いた（ログを見る）」に、まだ超えれば SQL の形を「SQL の形: 長いので省いた（ログを見る）」に置き換え、それでも超えればメッセージを詰める（末尾に `…`）。送る直前にまだ超えていたら、本文の 1 行目を「長すぎて省いた（ログを見る）」にした短い知らせに替える（件名は普通のまま。下の「最小の知らせ」）
- Request ID が無ければ「Request ID: （無し）」
- 最後の行は、知らせを作った部品の版（下の「部品の版」）

### 項目の長さ（表示幅。全角は 2）

| 項目 | 上限 |
| --- | --- |
| 件名 | 80（切るのは要約の後ろから） |
| メッセージ | `message_max_length`（既定 500） |
| リクエスト | 300 |
| Request ID・利用者・コマンド | 100 |
| 種類・包んだもの・原因・アプリの行・notice の行と枠の 1 行 | 500 |
| 前後のコードの 1 行 | 160 |
| SQL の形 | 600（超えたら末尾を `…（長いので省いた。全文はログ）`） |

## 部品の版

本文の最後の行に、知らせを作った部品の版を `rich-php-chatwork 0.6.1` の形で出す。版によって本文の形が違う（0.6 で件名の要約の並びと SQL の形が変わった）ので、読み手が [CHANGELOG](../CHANGELOG.md) のどの版と照らせばよいかを示す。0.6.0 までの知らせにはこの行が無い。

- 例外の知らせ・notice・最小の知らせ・長さで替えた知らせのどれにも出す。件名には入れない（目立たせない）
- 版は実行時に Composer の記録（`InstalledVersions::getPrettyVersion()`）から取る。コードに版の数字は書かない
- 出すのはリリースの版の形（`0.6.1`・`1.0.0-beta.2`。数字・英字・`.`・`-` だけ）。dev のブランチ（`dev-main`）・Composer の記録が無い・取れないときは「rich-php-chatwork（版は不明）」にし、知らせは止めない
- 形に当てた後も、ほかの項目と同じく記法の逃がしを通す

## 件名

`{title}{短いクラス名}: {要約}`。`title` の既定は `{app}（{env}）`。要約が無ければ `: ` も付けない。文言は入れない（スマホのロック画面に出るため）。

要約は「短い言葉・決まった項目・番号」の順に `・` でつなぐ。件名は 80 の幅で後ろから切るので、切れるときは番号から落ちる（番号は本文の枠に必ず残る）。

短いクラス名は名前空間を外した物。PHP の組み込みの例外と同じ名前（`Exception`・`RuntimeException` など）なら 1 段上を付ける（`Service\Exception`）。匿名クラスは `RuntimeException@anonymous`。

| 例外 | 要約 |
| --- | --- |
| `QueryException` | `列が無い・SQLSTATE 42S22`。22001 は `文字が長すぎる・列 name・SQLSTATE 22001`。接続の失敗は `接続できない・SQLSTATE HY000`（`認証に失敗`・`名前が引けない`・`接続が切れた` など）。表に無ければ `SQLSTATE HY000` |
| `ErrorException` | `PHP の警告（E_WARNING）` など |
| `TypeError` | `SyncService::run() 引数 #1 int←null` |
| `FatalError` | `メモリ切れ（上限 512 MB）`・`実行時間の上限（30 秒）` |
| `Error`（null のメソッド呼び出し） | `null の save() を呼んだ`（メソッドの名前だけ） |
| クラスで決まる例外 | `ジョブの試行回数の上限`・`ジョブの時間切れ`・`0 で割った`・`引数の数の誤り`・`match に当たる場合が無い`・`受け付けない値`・`JSON の読み書きの失敗`（文言は読まない） |
| スケジュールのコマンドの失敗（素の `Exception`） | `スケジュール report:daily・失敗を返した・終了コード 1`。名前が取れなければ `スケジュールのコマンドの失敗・失敗を返した・終了コード 1` |
| `RequestException`・素の Guzzle の `BadResponseException`（`ClientException` など） | `相手が一時停止・HTTP 503` |
| `ConnectionException` | `接続できない`。Guzzle の例外のクラスで `接続の時間切れ`・`応答の時間切れ` を言い分ける |
| プロセスの例外（Laravel・Symfony） | `外のコマンドの失敗・強制終了（SIGKILL）・終了コード 137`・`外のコマンドの時間切れ・60 秒`・`外のコマンドを起動できなかった` |
| Google の API | `回数の上限・reason rateLimitExceeded・HTTP 403` |

文言から取る物は、種類ごとの形を文言の先頭から末尾まで当て、名前の文字だけを取る。形が合わなければ要約を出さない。

## 番号に添える言葉

件名の短い言葉と「説明: 」の行は、決まった表から引く（表は `src/Glossary.php`）。キーは番号・SQLSTATE・状態・reason・終了コード・クラスだけで、元の文言は写さない。

| 種類 | 引く順 | 根拠 |
| --- | --- | --- |
| DB | MySQL・MariaDB の接続なら MySQL の番号 → SQLSTATE（完全一致）。ほかのドライバーは SQLSTATE だけ（番号がぶつかるため。SQL Server の 1205 はデッドロック） | MySQL 8.4 Error Reference、ODBC の SQLSTATE、PostgreSQL の番号 |
| HTTP の状態 | Google の reason（最初の 1 つ）→ 状態。状態は外の相手の応答なので「相手が」で書く | RFC 9110 §15、RFC 6585 §4、Google の Global domain errors |
| 終了コード | 表（1・2・126・127・130・134・137・139・143）→ 129〜159 は「シグナル N」 | Bash の Exit Status、Symfony Process・Console |
| 例外のクラス | 子のクラスを先に見る | PHP マニュアル、Laravel |

- 表に無い値には何も足さない。SQLSTATE の頭 2 文字で推測しない（42 は構文と権限が混ざる）。HY000（一般のエラー）にも足さない
- 23000 は一意・外部キー・NOT NULL のどれでも来るので、SQLSTATE の表では「制約に反した（どれかは番号を見る）」と言い、言い分けは MySQL の番号でする
- 原因を断定しない（137 は「メモリ不足で OS が止めた場合を含む」、2006 は「接続が切れていた」だけ）
- 説明には ASCII の `(` `)` `:` を使わない（絵文字の記号に当たらないよう全角にする）

## アプリの行

| 場合 | 出す物 |
| --- | --- |
| 主に見る例外をアプリの中で投げた | `app/X.php:42` |
| vendor の中で投げた | 呼び出しの並びで一番近いアプリの段に「（vendor の Connection.php:825 で投げた）」 |
| `TypeError` で文言に `called in` がある | 呼び出した側（trace[0]）に「（定義は SyncService.php:15）」 |
| コンパイル済みのビューで投げた | 一番近いアプリの段に「（ビュー resources/views/x.blade.php の中で投げた）」 |
| アプリの段が無い | 「無し（vendor の X.php:N で投げた）」。ビューが分かれば「無し（ビュー … の中）」 |

vendor と `storage/framework/views/` はアプリの段に数えない。前後のコードは前後 2 行で、`app/`・`routes/` の中のファイルだけ読む。

## 入口

上から見て、最初に当たった 1 つを出す。

| 入口 | 判定 | 出す物 |
| --- | --- | --- |
| ジョブ | 部品がジョブの印を付けている | `ジョブ: SyncEmployeeJob（試行 2 回目）` |
| コマンド | Context に `command` がある | `コマンド: report:daily`（最初の語だけ） |
| HTTP | コンソールで動いていない | `リクエスト: PUT /api/employees/{employee} ／ 利用者: 42`。ルートが決まる前は `PUT（ルート未確定）`。利用者がいなければ `未ログイン` |
| ほかのコンソールの入口 | Context に `request` がある | `リクエスト: {値}` |

ジョブの Request ID は部品が付けた新しい ID で、出した側の ID は「発端の Request ID」に出る（[design.md](design.md#ジョブの入口と-request-id)）。sync のジョブで失敗して呼び出し側で記録されたときは、次のように分ける。

```text
Request ID: 3f2a…（ジョブの中のログ）
発端の Request ID: req-http（例外の記録はこの番号）
```

## 間引きと上限の言い方

```text
同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 12 回
同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 2 回。上限で送らなかった 3 回（14:05 から）
同じ例外: 上限で送らなかった 3 回（14:05 から）
同じ例外: 14:30 から、送らずに 3 回（送れなかった分を含む）
同じ知らせ: 前の知らせ（11/10 08:00）の後の 1 時間に、送らずに 1 回
ほかの例外: 03:00 から 15 件（QueryException 12・TypeError 3、上限で送らなかった。ログを見る）
ほかの知らせ・例外: 11/10 23:59 から 4 件（知らせ 3・TypeError 1、上限で送らなかった。ログを見る）
```

- 時刻はアプリのタイムゾーン（`app.timezone`）。当日でなければ日付を付ける
- 秒数は 60 秒（notice は 1 時間）。60 秒までは秒、それを超えたら分・時間（`90` は `1 分 30 秒`、`3600` は `1 時間`）
- 前の知らせの時刻が取れなければ、前の知らせを書かず「送らずに N 回」だけ
- 送れなかったことがあれば、その 1 回も数え、前の知らせの後の窓ではなく、数え始めた時刻から「{時刻} から、送らずに N 回（送れなかった分を含む）」と書く
- キャッシュが使えないときも、予備の置き場（一時フォルダのファイル・プロセスの中）で数えた回数を出す
- 「ほかの例外」は例外と notice を合わせて数え、多い順に 3 つまで名前を出す。notice は `知らせ` という名前で数え、含まれていれば「ほかの知らせ・例外」
- 時刻は最初に上限を超えた時刻（当日でなければ日付を付ける）
- 上限で落ちたのが今送る物と同じキーなら、「ほか」ではなく「同じ例外」（notice は「同じ知らせ」）の行に、間引いた回数と分けて「上限で送らなかった N 回（最初に落ちた時刻 から）」と書く

## notice

```text
[info][title]{アプリ名}（{環境}）知らせ: {タイトル}[/title]{行}
{行}
[code]{code}[/code]{同じ知らせ・ほかの例外}
rich-php-chatwork {版}[/info]
```

- 行と枠は伏せて逃がす。行と枠の 1 行は 500 まで
- 地の文の行も枠も省かず、値だけを伏せる（[redaction.md](redaction.md#文言は省かない)）
- 30,000 文字を超えたら、枠の末尾の行から落として枠の最後に「（以下 N 行は省いた）」を置く。枠を落としきっても超えれば、地の文の行を後ろから落として「（以下 N 行は省いた）」を足す

## 最小の知らせ

本文を組み立てられなかったときに送る（[design.md](design.md#失敗したときの動きゴール-1)）。

```text
[info][title]example-app（production）RuntimeException: 知らせを組み立てられなかった[/title]Request ID: req-1（中身はログをこの番号で引く）
同じ例外: 前の知らせ（10:00）の後の 60 秒に、送らずに 1 回
rich-php-chatwork 0.6.1[/info]
[info][title]example-app（production）知らせ: 知らせを組み立てられなかった[/title]Request ID: req-1（中身はログをこの番号で引く）
rich-php-chatwork 0.6.1[/info]
```

件名の頭と Request ID が取れなければ省く。Request ID は普通の知らせと同じ番号（sync の失敗なら 2 行）。間引いた回数と上限で送らなかった件数の行（数字とクラス名だけ）と、部品の版の行は付ける。

本文が長すぎて替えたときは、件名は普通の件名と同じ（要約の後ろから切る）にし、本文の 1 行目で言い分ける。

```text
[info][title]example-app（production）QueryException: 重複・SQLSTATE 23000[/title]長すぎて省いた（ログを見る）
Request ID: req-1（中身はログをこの番号で引く）
rich-php-chatwork 0.6.1[/info]
```

## 例

### DB の値の長さの失敗（HTTP）

```text
[info][title]example-app（production）QueryException: 文字が長すぎる・列 name・SQLSTATE 22001[/title][code]SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 (Connection: mysql, SQL: 下の枠)[/code]説明: 列の長さを超える文字列を入れようとした
SQL の形（値は ?）:
[code]update `employees` set `name` = ?, `updated_at` = ? where `id` = ?[/code]アプリの行: app/UseCase/Actions/Employee/UpdateAction.php:42（vendor の Connection.php:825 で投げた）
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
（vendor の 38 段）[/code]rich-php-chatwork 0.6.1[/info]
```

### ビューの中の DB の例外

```text
[info][title]example-app（production）QueryException: 列が無い・SQLSTATE 42S22[/title][code]SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nme' in 'where clause' (Connection: mysql, SQL: 下の枠)[/code]説明: SQL が無い列を指している。マイグレーションの流し忘れか列名の誤りを見る
SQL の形（値は ?）:
[code]select * from `employees` where `nme` = ? and `employees`.`deleted_at` is null[/code]包んだもの: ViewException（ビュー resources/views/employees/show.blade.php）
アプリの行: app/Models/Employee.php:120（vendor の Connection.php:825 で投げた）
[code]…[/code]リクエスト: GET /employees/{employee} ／ 利用者: 7
Request ID: …（中身はログをこの番号で引く）
[hr][code]…[/code]rich-php-chatwork 0.6.1[/info]
```

### ジョブの中の TypeError

```text
[info][title]example-app（production）TypeError: SyncService::run() 引数 #1 int←null[/title][code]App\Services\Employee\SyncService::run(): Argument #1 ($employeeId) must be of type int, null given, called in app/Jobs/SyncEmployeeJob.php on line 28[/code]種類: 引数の名前: $employeeId
アプリの行: app/Jobs/SyncEmployeeJob.php:28（定義は SyncService.php:15）
[code]…[/code]ジョブ: SyncEmployeeJob（試行 2 回目）
Request ID: …（中身はログをこの番号で引く）
発端の Request ID: …
[hr][code]…[/code]rich-php-chatwork 0.6.1[/info]
```

### 外の API の失敗（Webhook）

```text
[info][title]example-app（production）RequestException: 相手に無い・HTTP 404[/title][code]HTTP 404 POST https://hooks.slack.com/services/{…}/{…}/{…}[/code]説明: 相手に無い URL・ID を指した
アプリの行: app/Services/Notify/SlackNotifier.php:40（vendor の PendingRequest.php:1100 で投げた）
[code]…[/code]コマンド: report:daily
Request ID: …（中身はログをこの番号で引く）
[hr][code]…[/code]rich-php-chatwork 0.6.1[/info]
```

### メモリ切れ（呼び出しの並びが取れない）

```text
[info][title]example-app（production）FatalError: メモリ切れ（上限 512 MB）[/title][code]Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes)[/code]アプリの行: 無し（vendor の Collection.php:512 で投げた）
コマンド: report:daily
Request ID: …（中身はログをこの番号で引く）
呼び出しの並び: 取れない
rich-php-chatwork 0.6.1[/info]
```

### DB の例外の SQL の形

重複（件名の短い言葉は MySQL の番号から）:

```text
[info][title]example-app（production）QueryException: 重複・SQLSTATE 23000[/title][code]SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '（伏せた）' for key 'users.users_email_unique' (Connection: mysql, SQL: 下の枠)[/code]説明: 一意のキー（主キー・unique）に、すでにある値を入れようとした
SQL の形（値は ?）:
[code]insert into `users` (`email`, `name`, `updated_at`, `created_at`) values (?, ?, ?, ?)[/code]アプリの行: …
```

外部キー（同じ形の行が続く insert は行の数で縮める）:

```text
[info][title]example-app（production）QueryException: 参照先が無い・SQLSTATE 23000[/title][code]SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (（伏せた）) (Connection: mysql, SQL: 下の枠)[/code]説明: 外部キーの指す先の行が無い
SQL の形（値は ?）:
[code]insert into `evaluations` (`employee_id`, `period_id`, `score`) values (?, ?, ?) ×3[/code]アプリの行: …
```

接続の失敗（`[` の後ろが英字の `[HY000` は、記法の始まりとして `[` を全角にする）:

```text
[info][title]example-app（production）QueryException: 接続できない・SQLSTATE HY000[/title][code]SQLSTATE［HY000] [2002] 接続できない（詳細はログ） (Connection: mysql, SQL: 下の枠)[/code]説明: DB につながらない
SQL の形（値は ?）:
[code]select * from `sessions` where `id` = ? limit ?[/code]アプリの行: …
```

整数を直接並べる `whereIntegerInRaw` の大きな並び:

```text
SQL の形（値は ?）:
[code]select * from `employees` where `employees`.`id` in (? ×1200) and `employees`.`deleted_at` is null[/code]
```

### notice

```text
[info][title]example-app（production）知らせ: ログインの失敗が多い[/title]直近 10 分の失敗: 52 回
IP やログイン ID は laravel.log を見る
rich-php-chatwork 0.6.1[/info]
```
