# 設計書

この部品は、Laravel が記録する例外を Chatwork の部屋に知らせる。知らせの役目は「どんな種類の例外かを知らせ、ログを引く鍵（Request ID）を渡す」こと。伏せる前の文言・SQL・呼び出しの並びは、Laravel がいつもどおりログに書く。

本文の形は [message-format.md](message-format.md)、伏せる規則は [redaction.md](redaction.md) にある。

## 入口

| 入口 | 何が呼ぶか | 中身 |
| --- | --- | --- |
| `Reporter::report(Throwable)` | ServiceProvider が例外のハンドラーの `reportable()` に登録した関数（`Handler` を継承しない Collision のハンドラーも `reportable()` を中の Handler に回すので、継承ではなく `reportable()` の有無で登録する。同じハンドラーには 1 度だけ） | 記録する例外（`dontReport` に当たらない物）を送る。void を返し、関数の中全体を try で包むので、Laravel の元の記録は止まらない（`Handler::reportThrowable()` は callback を try で包まない） |
| `Reporter::notice(title, lines, key, code)` | 使う側のコード | 例外ではない知らせを送る |
| `Reporter::body(Throwable)` | `log_when_silent` とテスト | 本文だけを作る（間引きの行は無し） |
| キューと Context のイベント 8 つ | ServiceProvider が登録 | ジョブの入口の印と Request ID の入れ替えと戻し（`JobEntrance`） |

## 処理の流れ（report）

```mermaid
flowchart TD
    A[report] --> B{送り先と環境}
    B -->|送らない| B1[送り先が空なら 1 度だけ警告<br/>log_when_silent なら本文をログへ]
    B -->|送る| C[間引きのキーを作る<br/>主に見る例外を辿る]
    C -->|失敗| C1[辿らずにキーを作る<br/>本文は最小の知らせ]
    C --> D{同じ例外の間引き}
    C1 --> D
    D -->|間を置いていない| D1[回数を数えて終わり]
    D -->|送ってよい| E{全体の上限<br/>1 分あたり}
    E -->|超えた| E1[印を消して回数を戻す<br/>キーごとに数えて終わり]
    E -->|枠がある| F[本文を組み立てる]
    F -->|失敗| F1[最小の知らせ<br/>部品の例外をログへ]
    F --> F2{3 万文字を超えた}
    F2 -->|超えた| F3[件名は普通のまま<br/>本文の 1 行目に 長すぎて省いた<br/>ログは書かない]
    F2 --> G[送る]
    F1 --> G
    F3 --> G
    G -->|失敗| G1[印を消して回数を戻す<br/>部品の例外をログへ]
    G -->|成功| H[送った時刻を置く]

    style B1 fill:#f5f5f5,color:#424242
    style C1 fill:#fff3e0,color:#e65100
    style F1 fill:#fff3e0,color:#e65100
    style F3 fill:#fffde7,color:#f57f17
    style G1 fill:#ffebee,color:#b71c1c
    style H fill:#e8f5e9,color:#1b5e20
```

`notice` も同じ流れで、間引きのキーは使う側が渡したキーから作る（辿る処理は無い）。

## 本文の組み立て（compose）

1. **主に見る例外を決める**（`Chain`）。previous を最大 10 段辿り、外側から包む例外（既定 `ViewException`）を剥ぐ。剥いだ物は「包んだもの」、内側は「原因」（3 つまで）になる
2. **種類ごとの部分を取る**（`Details` → `Kind`）。件名の要約と「種類」の行の項目
3. **メッセージを作る**。形の分かる例外は組み立て直す（`KnownMessage`）。それ以外は `send_message` のときだけ、値の書き出しの形を含めば省き（`Redactor::containsDump`）、含まなければ元の文言を伏せる（`Redactor`）
4. **アプリの行を決める**（`Source::appLine`）。前後のコードは `snippet_paths` の中だけ読む（`Snippet`）
5. **入口を決める**。ジョブ → コマンド → HTTP の順（`JobEntrance`・`Reporter::entrance`）
6. **呼び出しの並び**（`Trace`、アプリの段を 10 段まで）
7. **並べる**（`Body`）。30,000 文字を超えたら、呼び出しの並び → 前後のコードの順に塊で落とし、まだ超えればメッセージを詰める。送る直前にも数え、超えていたら、件名は普通のまま本文の 1 行目を「長すぎて省いた（ログを見る）」にした短い知らせに替える

本文に入る値は、項目ごとに `Text::field()` を通す。順番を入れ替えると壊れる。

```text
文字を直す（不正な UTF-8・制御文字）→ 伏せる → 記法を逃がす → 表示幅で切る
```

伏せる前に逃がすと規則の当たり方が変わり、切った後に逃がすと幅を超える。伏せる処理が失敗したら、その項目を「（伏せられなかったので省いた。ログを見る）」にする。

## クラスの受け持ち

| クラス | 受け持ち |
| --- | --- |
| `RichChatworkServiceProvider` | 設定の読み込み・登録・`reportable()` とキューのイベントの登録 |
| `Reporter` | 入口。送るかの判断・間引き・上限・組み立て・送る・失敗したときの動き |
| `Chain` | previous のつながり。主に見る例外・包んだもの・原因・包んだビュー |
| `Details` / `Kind` | 例外の種類ごとの、値を含まない部分（件名の要約と項目） |
| `KnownMessage` | 形の分かる例外（DB・HTTP クライアント）の文言を組み立て直す |
| `DriverMessage` | DB のドライバーの文言の許可リスト |
| `Redactor` | 伏せる規則（文言・notice・前後のコード・リクエスト） |
| `Text` | 値の通し方（直す・逃がす・切る）と、失敗を null で返す置き換え |
| `Names` | クラス名・関数名の整形（匿名クラス・クロージャのパス、件名の短い名前） |
| `Source` / `AppLine` | アプリのパスの見分け（vendor・コンパイル済みのビュー）、アプリの行 |
| `Snippet` | 前後のコード |
| `Trace` | 引数の値を含まない呼び出しの並び |
| `JobEntrance` | ジョブの入口の印、Request ID の退避と戻し、失敗のイベントで入口を例外に結び付ける |
| `Throttle` | 同じ例外の間引き・送らなかった回数・全体の上限。置き場は 設定のキャッシュ → 一時フォルダのファイル → プロセスの中 の順に落ちる |
| `Body` | 本文の並び（地の文・枠・横線）と塊での落とし方 |
| `Settings` | 設定を型を決めて読む |
| `Transport` / `PhpChatworkTransport` / `TimeoutMessage` | 送るところ。php-chatwork にタイムアウトを付けた物 |

## ゴールと仕組みの対応

ゴールは [README](../README.md#ゴール重要順) の表。ぶつかったら上を取る（[0006](decisions/0006-goals-in-order.md)）。

| ゴール | 主な仕組み | 場所 |
| --- | --- | --- |
| 1 黙殺しない | 最小の知らせ・送る直前の長さの守り・送信の失敗で印を消す・失敗をログへ・送り先が空の警告・`reportable()` の中を包む・予備の置き場 | `RichChatworkServiceProvider`・`Reporter`・`Throttle` |
| 2 秘密を出さない | 組み立て直す（許可リスト）・値の書き出しを含む文言は省く・伏せる規則・接続先を出さない・引数の値を出さない・トークンと本文の引数に `#[\SensitiveParameter]` | `KnownMessage`・`DriverMessage`・`Redactor`・`Trace`・`Transport` |
| 3 個人情報を出さない | 文言は既定で送らない・メール・電話番号・マイナンバー・口座番号を伏せる・利用者は内部 ID だけ | `Redactor`・`Reporter` |
| 4 種類が分かり、ログを引ける | 件名の要約・Request ID（ログに付く ID と揃える）・ジョブの新しい ID と発端の ID | `Details`・`JobEntrance` |
| 5 誤って判断しない | アプリの行（TypeError は呼び出し側）・入口の順・回数と時刻（アプリのタイムゾーン） | `Source`・`Reporter` |
| 6 部屋を壊さない | 記法の逃がし・項目と全体の長さ・同じ例外の間引き・全体の上限 | `Text`・`Body`・`Throttle` |
| 7 使う側を壊さない | 公開の口と設定のキーを保つ。型の違う設定は既定に戻す | `Settings`・[CHANGELOG](../CHANGELOG.md) |

## 失敗したときの動き（ゴール 1）

| 起きたこと | 動き | ログに書く物 |
| --- | --- | --- |
| ハンドラーに `reportable()` が無い | 登録しない | 警告を 1 度だけ |
| メモリ切れ（`Allowed memory size of …` の FatalError） | 部品を動かす前に `memory_limit` を今の使用量＋32MB に上げる（`-1` なら触らない。今の上限がそれより大きければ下げない）。上げられなければ部品を動かさない。Laravel は 32KB しか空けずに `report()` を呼び、部品の callback が元の記録より先に動くため | なし |
| `Reporter` を作れない（Transport の束縛が投げるなど） | 何もしない | 「例外を Chatwork に送れなかった」・部品の例外（生）・元の例外のクラス |
| 設定が読めない（起動の途中など） | 何もしない | 同上と Request ID |
| 伏せる処理が失敗（正規表現の誤り・上限） | その項目だけ省き、送る | なし |
| 間引きのキーを作れない | 外側の例外のクラスと投げた場所でキーを作り、最小の知らせを送る | 「組み立てられなかった」・部品の例外（生）・元の例外のクラス・Request ID |
| 本文を組み立てられない | 最小の知らせ（件名と Request ID、間引いた回数と上限の件数）を送る | 同上 |
| 本文が 30,000 文字を超えた（落としても詰めても） | 件名は普通のまま、本文の 1 行目を「長すぎて省いた（ログを見る）」にし、Request ID と回数の行だけの知らせに替える | なし（組み立ての失敗ではない） |
| 送れない（Chatwork の障害・タイムアウト） | 間引きの印を消し、回数（送れなかった 1 回を足す）と上限の件数を戻す。次の知らせは「{時刻} から、送らずに N 回（送れなかった分を含む）」 | 「送れなかった」・部品の例外（生）・元の例外のクラス・Request ID |
| キャッシュが投げる・値を持てない（NullStore・つながらない memcached） | その知らせの間は一時フォルダのファイルで間引く（同じサーバーの中だけ効く）。フォルダが自分の物でない・書き込めない・投げるならプロセスの中 | 置き場ごとに、プロセスごとに 1 度だけ |
| 全体の上限を超えた | 送らず、間引きのキーごとに数えて次の知らせに足す | なし（元の例外はログにある） |
| 送る環境で送り先が空 | 送らず、プロセスごとに 1 度だけ警告 | 警告 |
| ログにも書けない | 諦める | — |

「部品の例外を生で」は、`Log::warning()` の文脈の `exception` に部品の例外のオブジェクトを渡すこと（文言と呼び出しの並びが残る）。元の例外の文言と本文は渡さない（元の例外は Laravel が別に書く）。

## ジョブの入口と Request ID

ジョブの中では Request ID をジョブ用の新しい物に入れ替え、出した側の ID を「発端の Request ID」として出す。入れ替えた ID は、Laravel が例外を記録した後に戻す。ログ（`ContextLogProcessor` が付ける `request_id`）と知らせの Request ID を揃えるため。

```text
JobProcessing   Laravel の ContextServiceProvider が Context を payload の物に入れ替える（hydrate）
                → 部品が前の Request ID を積み重ねに退避し、ジョブ用の新しい ID を入れる
ジョブの中      ログは新しい ID で書かれる
失敗のイベント  JobExceptionOccurred か JobFailed（Worker は試行回数を超えると JobFailed が先）
                → 入口（ジョブの名前・ID・発端の ID）を例外に結び付け、失敗の印を付ける（まだ戻さない）
JobAttempted    process() の finally で出る。ワーカーでは report() より前なので何もしない。
                sync のジョブ（SyncJob）は、出した側に ID があればここで戻す（呼び出し側へ戻る所）。
                出した側に ID が無ければ戻さず、ジョブの ID を残す（段も残す）
report()        ワーカーは Worker::runJob() で、process() の後に呼ぶ。Context はジョブの ID のまま
                sync は呼び出し側（HTTP など）で呼ぶ。結び付けた入口でジョブの行を出す
Looping         ワーカーの次の周の頭。失敗したまま戻していないジョブを戻す（WorkerStopping でも）
入口の終わり    一番外の CommandFinished（CommandStarting と数えた深さで見分ける）・RequestHandled・アプリの terminating。sync で残したジョブの ID を戻す
                （Context がもう別の値なら触らない）。次のジョブの processing() と settle() でも戻す
JobProcessed    成功したら戻す
```

- 戻すのはジョブごとに 1 回だけ（uuid で見分ける）。sync の入れ子では一番内側の入口を残す
- sync で失敗して呼び出し側（HTTP など）で記録されたときは、ジョブの行を出し、Request ID を「{ジョブの ID}（ジョブの中のログ）」と「発端の Request ID: {記録した時の ID}（例外の記録はこの番号）」に分ける。最小の知らせも同じ番号を出す
- 出した側に ID が無い sync で失敗したときは、JobAttempted で戻さずジョブの ID を Context に残し、入口の終わりで戻す。呼び出し側の例外の記録もその番号になり、知らせの Request ID と揃う。部品が残した ID は覚えておき、後のジョブの payload に写っても発端の ID には使わない（最大 100 個）
- 戻さないまま次の JobProcessing が来たら（周の頭のイベントが無かった）、Laravel が Context を入れ替えた後（`ContextHydrated` が来た）なら印だけ捨てる。入れ替わっていなければ戻してから捨てる
- 部品の JobProcessing は ContextServiceProvider より後に動く必要がある。パッケージの ServiceProvider は Laravel の基本の ServiceProvider より後に boot されるので満たしている（`JobEntranceTest` で固定）
