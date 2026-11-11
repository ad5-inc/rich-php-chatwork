# 伏せる規則と組み立て直す規則

伏せる正規表現は、どんな文字でも来うる文言に対して「これで全部」と言えない。そこでメッセージの作り方を例外の種類で分ける（[0007](decisions/0007-message-by-kind.md)）。

| 種類 | メッセージ |
| --- | --- |
| 形の分かる例外（DB・HTTP クライアント） | 部品が安全な部品だけで組み立て直す。元の文言は使わない。`send_message` に関係なく送る |
| それ以外 | `send_message` が true のときだけ、元の文言を伏せて送る |

伏せた所は固定の「（伏せた）」にする。長さ・頭の文字・ドメインは残さない。

## 値の書き出しを含む文言は省く

形の分からない例外の文言と notice の地の文の行は、値の書き出しの形を含むなら、伏せずに丸ごと「（値の書き出しを含むので省いた。ログを見る）」にする（[0011](decisions/0011-omit-dumps.md)）。判定は文字を直し全角を揃えた後、伏せる前。

| 目印 | 何の書き出しか |
| --- | --- |
| 改行 | 複数行（print_r・var_export・JSON_PRETTY_PRINT） |
| `=>` | 配列 |
| `s:N:"`・`a:N:{`・`O:N:"` | serialize |
| `Array (`・`… Object (`・`array (`・`::__set_state` | print_r・var_export |
| `{"`・`["`・`\"` | JSON とエスケープした JSON |
| `%5B`・`%5D` | http_build_query の角括弧 |
| `%7B%22`・`%5B%22`・`%22%3A` | URL エンコードした JSON |

当たった目印が改行だけで、1 行目が `The command "`・`The process "` で始まらず（プロセスの文言を写した物。1 行目にコマンドラインが入る）、書き出しの頭（`{`・`[`・`(` で始まる・終わる、`Array`・`array`・`Object` で終わる）でもなければ、1 行目だけを下の規則で伏せて出し、「（2 行目から先は省いた）」を添える（Carbon の書式の失敗・Symfony Console の名前の誤り・SMTP の 2 行の応答のように、1 行目で読める文言のため）。notice の行は行ごとの判定なので、この扱いは無い。

省くかどうかは `tests/ReporterTest.php` の表で固定している（省く例: オブジェクトの書き出し・serialize の protected / private・長さのずれ・閉じ括弧の数え方。省かない例: `CSRF token mismatch.`・`Undefined array key "name"`・`Call to undefined method App\X::y()`・`in_array(): …`）。下の伏せる規則は、省かなかった 1 行の文と notice の枠に当てる。

## 当てる項目

| 項目 | 当てる規則 | 固定しているテスト |
| --- | --- | --- |
| メッセージ（形の分からない例外） | 書き出しを含めば省く。省かなければ全角を揃えてから、下の規則 1〜10 の全部と鍵の形 | `RedactorTest`・`ReporterTest` |
| メッセージ（形の分かる例外） | 組み立て直す（下の「組み立て直す例外」） | `KnownMessageTest` |
| notice の地の文の行 | メッセージと同じ（行ごとに省く） | `ReporterTest` |
| notice の枠（`code`） | 省かずに、メッセージと同じ規則で伏せる（配列の値・serialize・`\uXXXX` の規則はここで効く） | `RedactorTest`・`ReporterTest` |
| 前後のコード | 規則 2〜5・7、鍵の形、規則 8（値が引用符で始まるときだけ） | `RedactorTest` |
| リクエスト | 規則 6 だけ | `RedactorTest` |
| 件名・種類・Request ID・アプリの行・呼び出しの並び | 当てない（値を含まない作りで守る） | `DetailsTest`・`NamesTest` |

`log_when_silent` で書く写しも、伏せた後の本文。サーバーの絶対パス（base path）は相対パスにする（文言・匿名クラス・クロージャの名前の中も）。

## 規則（当てる順）

| 順 | 規則 | 伏せる例 | 伏せない例 |
| --- | --- | --- | --- |
| 1 | 別の例外が写した DB の文言 | 最初の `(Connection: ` から後ろ: `(Connection: mysql, Host: …, SQL: select …)` → `(Connection: mysql, SQL: （ログ）)`。`, SQL: ` が無ければ（途中で切れた）そこから全部 `（伏せた）`。`(Connection: ` も無ければ `SQLSTATE[23000] （伏せた）`。前は、接続の失敗なら `SQLSTATE[HY000] [1045] 認証に失敗（詳細はログ）` などの固定の言葉、許可リストの形なら値だけ伏せ、どちらでもなければ `SQLSTATE[HY000] [1049] （伏せた）` のように SQLSTATE（`[番号]` があればそこまで）より後ろを伏せる（1044・1049・1130・2003・2005、pgsql の `Key (…)=(…)`・`Failing row contains (…)` など） | SQLSTATE・ドライバーの番号・後ろの `(View: ….php)` の繰り返し（パスの形のときだけ） |
| 2 | JWT（`eyJ….….…`） | `jwt （伏せた）` | — |
| 3 | パスワードのハッシュ（`$2y$…`・`$argon2id$…`） | `hash （伏せた）` | — |
| 4 | `Bearer …`・`Basic …` | `Bearer （伏せた）` | — |
| 5 | URL の利用者情報 | `https://（伏せた）@host.example/x` | `https://host.example/x` |
| 6 | URL・パスの `?` 以降 | `GET /api/x?（伏せた）` | 前後のコードの `'/api/x?page=1'` |
| 7 | メールアドレス（`@` の代わりの `%40` も） | `to （伏せた） failed` | リクエストの中（規則 6 だけ） |
| 7b | 電話番号（`0[1-9]` か `+81` で始まる 10〜11 桁）・クレジットカード（4 桁×4・Amex の 4-6-5・Diners の 4-6-4。12 桁の規則より先）・マイナンバー（12 桁）・`口座`・`account` の後ろ 20 文字までの 7 桁 | `03-1234-5678`・`０３－１２３４－５６７８`・`+81 90-1234-5678`・`(03)1234-5678`・`1234 5678 9012`・`口座番号: 1234567` | SQLSTATE `22001`・HTTP `503`・行番号・`0012345678` のような 0 埋めの番号・UUID の最後の 12 桁・ファイル名の中の数字・前後のコード |
| 8 | キーと値（`Authorization` は方式ごと） | `{"password":（伏せた）}`・`'password' => （伏せた）`・`$secret = （伏せた）`・`XSRF-TOKEN=（伏せた）`・`[password] => （伏せた）`（print_r）・`user[password]=（伏せた）`・`{\"password\":（伏せた）}`（エスケープした JSON）・`Authorization: （伏せた）, rest`・`暗証番号: （伏せた）`・`パスワードは （伏せた） です`・`確認コード「（伏せた）」` | `CSRF token mismatch.`・`The token field is required.`・前後のコードの `$request->password` |
| 8b | URL のパス。スキームのある URL・`\/` でエスケープした URL・ホストの形で始まる URL（`hooks.slack.com/…`）のパスを、段ごとの許可リスト（英小文字の段と `v1` のような段だけ残し、ほかは `{…}`。組み立て直す HTTP の文言と同じ `UrlPath`）に通す。キーと値の後に当てる（先に `{…}` にすると、引用符の無い値が `}` で止まる）。パスは ASCII の文字だけで、`` ` `` と終わりの `.` `,` `:` `;` は含めない | `for https://hooks.slack.com/services/{…}/{…}/{…}`・`file_get_contents(https://discord.com/api/webhooks/{…}/{…})`・`https://api.example.com/v1/users/{…}です` | `https://host.example/x`・`` `POST https://api.example.com/v1/charges` ``・`…/v1/users. Retry` |
| 9 | 長い英数字 | 32 文字以上の 16 進・base64 のトークン・`session_{40 文字の 16 進}`・文の終わりが `.` のトークン | 長い snake_case・長いクラス名・UUID・`storage/app/{32 文字}.pdf` |
| 10 | 使う側の `message_redact_patterns` | 当たった所 | — |

### 規則の細かい当たり方

- **\u を戻す**: 伏せる前に JSON の `\uXXXX`（サロゲートの組も）を戻す（`json_encode` した「パスワード」のキーに当てるため。本文も戻した形で出る。制御文字・`"`・`\` は戻さない）
- **全角を揃える**: 伏せる前に NFKC で揃える（`０３－…`・`＠`・`：` が半角になり、本文もその形で出る）。intl の `Normalizer` が無い環境では `mb_convert_kana('KVas')` で英数字・記号・空白・半角カナを揃え、`＂` `＇` `＼` も揃える。値の区切りになる全角の `，` `；` `＆` `｝` は揃える前に逃がし、値の中として扱う（`password=hun，ter2` の `ter2` を残さない）
- **規則 7b の境界**: 前後が英数字・`_`・`-` の所は外す。電話番号とマイナンバーの区切りは `-‐−ー－–—/`・空白・`.`・括弧。`+81` の後ろの `(0)` も許す。12 桁の内部 ID など、伏せなくてよい数字も伏せることがある
- **規則 8 のキー**: `password|passwd|pwd|pw(?![a-z])|passphrase|passcode|php[_-]auth[_-]pw|pass(?![a-z])|token|secret|api[_-]?key|private[_-]?key|access[_-]?key|credential|authorization|cookie|session|otp|one[_-]?time|verification[_-]?code|パスワード|暗証番号|トークン|確認番号|確認コード|認証コード|ワンタイム`（大文字小文字を区別しない）
  - 語の前に境界は求めない（`remember_token`・`XSRF-TOKEN` にも当たる）。語の後ろに `[A-Za-z0-9_-]*` が続いてよい（`password_confirmation`）
  - `pass`・`pw` は後ろに英小文字が続かないときだけ（`db_pass`・`smtp_pass`・`pw=` に当て、`passed`・`passenger`・`pwned` に当てない）
  - キーの後ろは `(?:(?:\]|%5D)|(?:\[|%5B)[^\]=&%]*(?:\]|%5D))*\\*['"]?\s*(=>|=|:|：|%3D|%3A)\s*`（print_r と http_build_query の `]`・`%5D`、リストの添字 `password[0]=`・`token[]=`・`tokens%5B0%5D=` の繰り返し、何重にもエスケープした JSON の `\` を許す）。これが無ければ当てない
  - 値が配列（print_r の `Array (`・var_export の `array (`・JSON の `[`・`{`）なら、対応する閉じ括弧まで伏せる（JSON は `"`、var_export は `'` の中の括弧を数えない）
  - serialize の形（`s:8:"password";s:7:"…";`）は、書いてある長さだけ伏せる
  - 日本語のキーに限り、区切りに `は`・`「` も許す。値の終わりは空白か `」`・`。`
  - `Authorization` の値は、方式（Bearer・Token・Digest など）ごと行末か `,` まで
  - 値: 引用符で始まれば、エスケープを考えて閉じる引用符まで（無ければ末尾まで）。引用符が無ければ空白では止めず、行末か `,`・`;`・`&`・`}`・`)` の手前まで（伏せすぎる側を受け入れる）。値は引用符ごと「（伏せた）」にする
  - `session_lifetime: 120` のような伏せなくてよい値も伏せる
- **規則 9 の境界**: 前が英数字・`_`・`\`・`.`・`-` でない所。後ろは英数字・`_`・`-` でなく、`.` なら後ろに英数字が続かない所
  - base64 は `[A-Za-z0-9+_-]{32,}={0,2}` で、英字と数字を両方含み数字が 3 個以上の物だけ
  - UUID の形と、どの区切りも 16 文字未満の snake_case は外す
- **鍵の形**（前後のコード・メッセージ・notice）: `sk_live_…`・`AKIA…`・`AIza…`・`xox[abp]-…`・`gh[pousr]_…`・`-----BEGIN … PRIVATE KEY-----`（その行の末尾まで）
- **失敗したら**: 正規表現が失敗したら（パターンの誤り・バックトラックの上限）、その項目を「（伏せられなかったので省いた。ログを見る）」にする。元の値には戻さない

## 組み立て直す例外

### QueryException

| 場合 | 文言 |
| --- | --- |
| ドライバーの文言が許可リストに当たる | `SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 (Connection: mysql, SQL: （ログ）)` |
| 許可リストに当たらない（権限の不足 1142 など） | `SQLSTATE[42000] ドライバー 1142（文言はログ） (Connection: mysql)` |
| 接続の失敗（SQLSTATE `08xxx`、または `HY000` でドライバー 2002・2006・1045。`Details::CONNECTION_FAILURE_DRIVERS`） | `SQLSTATE[HY000] [1045] 認証に失敗（詳細はログ） (Connection: mysql)`。2002 で名前が引けないときは `名前が引けない`、ほかは `接続できない` |
| previous が PDOException でない | `SQLSTATE[HY000] （文言はログ） (Connection: mysql)` |

- SQLSTATE とドライバーの番号は、previous の PDOException の `errorInfo` から取る。無ければ文言の頭の `SQLSTATE[…]`・`[番号]` から取る（接続の失敗では `getCode()` が番号になるので使わない）
- Host・Port・Database・SQL・値は出さない。接続名が名前の形でなければ「（伏せた）」
- SQLSTATE の説明（`String data, right truncated` など）は決まった英字のときだけ出す

#### ドライバーの文言の許可リスト

値は「終わりの語」に向けて最長一致で取る（値に `'` があっても残らない）。大文字小文字は区別しない。

| 形 | 伏せる所 |
| --- | --- |
| `Data too long for column '…' at row N` | なし |
| `Duplicate entry '…' for key '…'` | entry の値 |
| `Incorrect \w+ value: '…' for column '…'`（後ろの ` at row N` は有っても無くても） | 値 |
| `Truncated incorrect \w+ value: '…'` | 値 |
| `Unknown column '…' in '…'` | なし |
| `… near '…' at line N`（前に `'` を含まない） | near の後ろ |
| `Cannot add or update a child row: a foreign key constraint fails (…)` | 括弧の中 |
| `Column '…' cannot be null` | なし |

「なし」の形は、引用符の中に `'` があれば許可リストの外として扱う。

### RequestException

`HTTP {状態コード}` に、URL が取れたときだけ ` {メソッド} {スキーム}://{ホスト}{パス}` を足す。

- URL は `$e->response->effectiveUri()`（transferStats から）。メソッドは transferStats のリクエストから
- 利用者情報・ポート・`?` 以降は出さない。応答の本文は出さない
- パスは段ごとの許可リスト。`^[a-z][a-z_-]{0,30}$` と `^v\d{1,2}$` の段だけ残し、ほかは `{…}`

| URL | パス |
| --- | --- |
| Slack の Webhook | `/services/{…}/{…}/{…}` |
| Discord の Webhook | `/api/webhooks/{…}/{…}` |
| Telegram | `/{…}/{…}`（`sendMessage` は大文字を含むので残らない。大文字を許すと英字だけの秘密の段が残りうる） |
| `/v1/users/42/messages` | `/v1/users/{…}/messages` |
| `/v1/token` | `/v1/token` |

### プロセスの例外

Laravel の `Process`（`ProcessFailedException`・`ProcessTimedOutException`）と Symfony の Process（`ProcessFailedException`・`ProcessTimedOutException`・`ProcessStartFailedException`・`RunProcessFailedException`）。文言はコマンドライン（`-p` の後ろのパスワード・`--token` の値など）を含むので使わない。

| 場合 | 文言 | 件名の要約 |
| --- | --- | --- |
| 失敗 | `コマンドの失敗（終了コード 2、コマンドはログ）` | `コマンドの失敗・終了コード 2` |
| 時間切れ | `コマンドの時間切れ（60 秒、コマンドはログ）` | `コマンドの時間切れ・60 秒` |
| 起動できなかった | `コマンドを起動できなかった（コマンドはログ）` | `コマンドを起動できなかった` |

終了コードは Laravel は `$e->result->exitCode()`、Symfony は `$e->getProcess()->getExitCode()`。秒数は Symfony の時間切れの例外の `getExceededTimeout()`。

### ConnectionException

`接続できない: {スキーム}://{ホスト}`。ホストは previous（Guzzle の例外）の `getRequest()->getUri()` から取る。取れなければ `接続できない` だけ。ConnectionException の文言は読まない。

組み立てに失敗したら、メッセージを「（組み立てられなかったので省いた。ログを見る）」にする。元の文言には戻さない。

## 伏せきれない物

- 氏名・住所のような形の無い個人情報。`send_message` を true にするのは、送り先の部屋に入る人が文言を見てよいと決めたときだけ
- 形の決まっていない秘密（短いパスワードをキーの無い文に入れたなど）
- 規則 9 に当たらない短いトークン（32 文字未満）

使う側は、`notice()` の行・Context の値・利用者の関数に値を入れないことで守る（[README](../README.md#入れてよい値使う側が渡す物)）。
