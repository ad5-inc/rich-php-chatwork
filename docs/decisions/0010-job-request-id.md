# 0010 ジョブの Request ID は例外の記録の後に戻す

- 状態: 採用

## 背景

ジョブの中では Request ID をジョブ用の物に入れ替える。失敗したときに、失敗のイベントで ID を元に戻すと、Laravel が後で例外を記録するときのログの `request_id` は元の ID になり、知らせの Request ID（ジョブの ID）と食い違う。知らせの ID でログを引けない。

## 選んだ物

Laravel が例外を記録した後に戻す。

| 動き方 | 戻す所 | 理由 |
| --- | --- | --- |
| ワーカー | 次の周の頭（`Looping`）か `WorkerStopping` | `Worker::runJob()` は `process()` の後で `report()` を呼ぶ。`JobAttempted` はそれより前に出る |
| sync | `JobAttempted`（呼び出し側へ戻る所） | `report()` は呼び出し側（HTTP など）で呼ばれ、いつになるか分からない。呼び出し側の Context を戻しておく |
| 成功 | `JobProcessed` | 記録する例外が無い |

sync で失敗して HTTP まで投げられたときは、失敗のイベントで結び付けた入口でジョブの行を出し、「発端の Request ID」に HTTP の ID を出す。

詳しい流れは [design.md](../design.md#ジョブの入口と-request-id)。

## 選ばなかった物

| 案 | 選ばなかった理由 |
| --- | --- |
| 失敗のイベントで戻す | ログと知らせの Request ID が食い違う |
| sync も Looping で戻す | sync には周が無く、HTTP の残りの処理がジョブの ID のまま書かれる |

## 代償

- ワーカーでは、失敗した後から次の周の頭までの間に書かれたログもジョブの ID になる
- sync で失敗して HTTP まで投げられたときは、HTTP のログは HTTP の ID で書かれる。知らせの「発端の Request ID」で引く
