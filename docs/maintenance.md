# 保守の手順

直すときは [ゴール](../README.md#ゴール重要順) の順で判断する。迷ったら上（黙殺しない・秘密を出さない）を取る。

## 確かめるコマンド

ホストに PHP を入れずに流すなら docker を使う。

```bash
docker run --rm -v "$PWD":/app -w /app php:8.5-cli sh -c 'vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G --no-progress'
```

PHPStan は level 10（`src`・`config`・`tests`）。両方が通ってからコミットする。

## 直すときの順番

1. **テストの表を先に直す**。伏せる例・伏せない例・本文の例を足し、落ちることを確かめる
2. 実装を直す
3. [message-format.md](message-format.md)・[redaction.md](redaction.md) の表を直す
4. 使う側の振る舞いが変わるなら [CHANGELOG](../CHANGELOG.md) の次の版に「上げるときの注意」を書く

テストの置き場所:

| 何を固定するか | テスト |
| --- | --- |
| 伏せる規則 | `tests/RedactorTest.php` |
| 組み立て直す文言 | `tests/KnownMessageTest.php` |
| 種類ごとの部分と件名の要約 | `tests/DetailsTest.php` |
| 名前の整形 | `tests/NamesTest.php` |
| 本文の例 | `tests/ExamplesTest.php`（作り物のアプリを一時ディレクトリに作る `FixtureApp`） |
| 並び・長さ・間引き・上限・失敗したときの動き | `tests/ReporterTest.php` |
| ジョブの入口（本物の Worker と SyncQueue） | `tests/JobEntranceTest.php` |

テストの値は作り物と分かる物にする（`probe-…`・`example.test`）。秘密の形の値は、秘密を探す道具に本物と間違われないよう、文字列をつないで作る。

## 形の分かる例外を足す

1. 文言の何が危ないかを確かめる（値・接続先・応答の本文）
2. `KnownMessageTest` に、値を入れた例外と期待する文言を足す。値が出ないことも確かめる
3. `KnownMessage::of()` に分岐を足す。組み立てに使うのは、例外の項目（errorInfo・状態コード・URI など）と許可リストに当たった部分だけ
4. 件名の要約が要るなら `Details::of()` に足し、`DetailsTest` に書く
5. 「種類」の行と重ならないか見る（組み立てた文言に入った値は「種類」から外れる）

DB のドライバーの文言の形を足すときは、`DriverMessage` の `SHAPES`（組み立て用）と `IN_PLACE`（写された文言をその場で伏せる用）の両方に足す。

## 伏せる規則を足す

1. `RedactorTest` に伏せる例と伏せない例の両方を足す。伏せない例は、今まで残していた物（UUID・snake_case・クラス名・ファイル名・SQLSTATE・行番号）が壊れないかを見る
2. `Redactor` の規則の順のどこに入れるかを決める。URL（5・6）はキーと値（8）より先、長い英数字（9）は後ろの方。順を変えると、ほかの規則の印を壊すことがある
3. 当てる項目（文言・notice・前後のコード・リクエスト）を決める（[redaction.md](redaction.md#当てる項目)）
4. 正規表現は `Text::replace()` を通す。失敗は null で返り、項目ごと省かれる

使う側だけの規則なら、部品を直さず `message_redact_patterns` で足せる。

## Laravel を上げるときに確かめる所

部品は Laravel の内部の振る舞いに頼っている。版を上げたら、次の所を読み直し、テストを流す。

| vendor の所 | 頼っていること | 壊れたら |
| --- | --- | --- |
| `Illuminate/Foundation/Exceptions/Handler.php`（`reportThrowable`） | `reportable()` の callback を try で包まない（部品の側で包んでいる） | 部品が投げると元の例外が記録されない |
| `Illuminate/Queue/Worker.php`（`process`・`handleJobException`・`runJob`・`daemonShouldRun`） | 失敗のイベント（`JobFailed`・`JobExceptionOccurred`）の後、`process()` の外の `runJob()` で `report()` を呼ぶ。`JobAttempted` は `process()` の finally で `report()` より前。`Looping` は周の頭に `until()` で出る | ジョブの行が出ない。ログと知らせの Request ID がずれる |
| `Illuminate/Queue/SyncQueue.php`（`executeJob`・`handleException`） | 失敗のイベントの後、`JobAttempted`（finally）を出して例外を呼び出し元まで投げる。ジョブは `SyncJob` | 同上 |
| `Illuminate/Log/Context/ContextServiceProvider.php` | `JobProcessing` で Context を payload の物に入れ替える（hydrate は flush してから入れる）。部品の listener はこれより後に動く | ジョブの Request ID がディスパッチした側の物に戻る |
| `Illuminate/Database/QueryException.php` | 文言の末尾が ` (Connection: …, SQL: …)`。接続の詳細（Host・Port・Database）が文言に入る。`errorInfo` を previous から写す | 写された文言の規則 1・組み立てが当たらない |
| `Illuminate/Http/Client/RequestException.php`・`Response.php` | `response->status()`・`effectiveUri()`・`transferStats` | URL が出ず `HTTP {状態}` だけになる |
| `Illuminate/Http/Client/PendingRequest.php` | `ConnectionException` の previous が Guzzle の例外 | ホストが出ない |
| `Illuminate/Cache/FileStore.php` | `add()` が flock で排他する（予備の一時フォルダの印） | プロセスをまたいだ間引きが効かない |
| `Illuminate/Log/Context/ContextLogProcessor.php` | ログに Context（`request_id`）を付ける | ログと知らせの Request ID を揃える前提が崩れる |
| `Illuminate/View/Engines/CompilerEngine.php` | ビューの例外を `ViewException` で包み、文言の末尾に ` (View: 絶対パス)` を付ける | 包んだビューが出ない |
| `Symfony/Component/ErrorHandler/Error/FatalError.php` | 呼び出しの並びを渡さないと空になる | メモリ切れで「取れない」が出る（想定どおり） |

PHP を上げたら、匿名クラス・クロージャの名前の形（`NamesTest`）と、PHP が作る文言の形（`DetailsTest` の TypeError・メモリ切れ）を見る。テストの環境（`php:8.5-cli`）には intl が無く、全角を揃える処理は `mb_convert_kana()` の側を通る。intl のある環境の `Normalizer` の側は手で確かめる。

## リリース

1. テストと PHPStan を流す（上のコマンド）
2. [CHANGELOG](../CHANGELOG.md) に版と日付を書く。使う側の直しが要るなら「上げるときの注意」に書く
3. main に入れてからタグを付ける（`0.4.0` のように `v` を付けない）
4. 使う側で `composer update ad5-inc/rich-php-chatwork` し、使う側のテストを流す
5. 本番の前に試験用の部屋で確かめる（下）

0.x の間は、0.3 → 0.4 のように形が変わりうる。使う側は `^0.4` のように 0.x の版で止める。

## 本番の前に試験用の部屋で確かめる

本文の形を変えたら、試験用の部屋に 1 回送って見る。部品のテストでは Chatwork の描き方までは確かめられない。

- 全角にした `［To:…］`・`［toall]` が宛先として効かないこと、全角の `［` の見え方
- `[hr]` が `[info]` の中で横線になること
- スマホのアプリで、`[code]` の長い 1 行が折り返すか
- 件名が、スマホの通知のプレビューでどこまで見えるか
