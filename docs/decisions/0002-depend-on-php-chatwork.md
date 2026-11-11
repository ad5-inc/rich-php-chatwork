# 0002 送るところは ad5jp/php-chatwork に依存する

- 状態: 採用

## 背景

Chatwork へ送る既存の部品 [ad5jp/php-chatwork](https://github.com/ad5jp/php-chatwork) がある。送る処理を二重に持ちたくない。

## 選んだ物

php-chatwork に依存する。ただし送るところ（`Message::send()`）だけを `TimeoutMessage` で書き直し、タイムアウト（`timeout_seconds`、既定 3 秒）を付ける。本文の組み立て（`build()`）は php-chatwork のまま使う。部品の中では `Transport` の口で包み、テストでは差し替える。

## 選ばなかった物

| 案 | 選ばなかった理由 |
| --- | --- |
| Laravel の Http クライアントで直接送る | 既存の部品を使わず、送る処理を二重に持つ |
| Slack なども送れる差し替えの形にする | 使う先が今は無い |

## 代償

- php-chatwork の `send()` はタイムアウトを渡せないので、送るところだけを部品が持つ（php-chatwork が Client やタイムアウトを渡せるようになったら `TimeoutMessage` は消してよい）
- php-chatwork のコンストラクタが PHP 8.4 から非推奨の形（暗黙の nullable）で、読み込むたびに deprecation が出る
- 送る呼び出しは本物の通信を差し替えにくいので、部品のテストは `Transport` を差し替えて行う

困ったら、php-chatwork を直すか、Http クライアントで直接送るかを見直す。
