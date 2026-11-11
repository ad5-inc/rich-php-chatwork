# ドキュメントの目次

使い方は [リポジトリの README](../README.md)、版ごとの変更は [CHANGELOG](../CHANGELOG.md) にある。ここは保守する人向け。

| 文書 | 中身 | 読む人 |
| --- | --- | --- |
| [design.md](design.md) | 処理の流れ・クラスの受け持ち・ゴールと仕組みの対応・失敗したときの動き | 初めてコードを読む人 |
| [message-format.md](message-format.md) | 本文の形（並び・件名・アプリの行・入口・間引きの言い方・例） | 本文の形を変える人、知らせを読み解く人 |
| [redaction.md](redaction.md) | 伏せる規則と組み立て直す規則（当てる項目・順番・許可リスト・例） | 伏せる規則を足す・直す人、使う側で何が出るかを判断する人 |
| [maintenance.md](maintenance.md) | 直す手順・Laravel を上げるときに確かめる所・リリースの手順 | 直す人、版を出す人 |
| [decisions/](decisions/) | 設計判断の記録（背景・選んだ物・選ばなかった物・代償） | 「なぜこうなっているか」を知りたい人 |

## 判断の記録

| 番号 | 判断 |
| --- | --- |
| [0001](decisions/0001-php-laravel-versions.md) | PHP 8.5 / Laravel 13 だけに対応する |
| [0002](decisions/0002-depend-on-php-chatwork.md) | 送るところは ad5jp/php-chatwork に依存する |
| [0003](decisions/0003-name-and-versioning.md) | 名前と版の付け方 |
| [0004](decisions/0004-no-packagist.md) | Packagist に登録しない |
| [0005](decisions/0005-message-not-sent-by-default.md) | 例外の文言は既定で送らない |
| [0006](decisions/0006-goals-in-order.md) | ゴールを重要順に決め、ぶつかったら上を取る |
| [0007](decisions/0007-message-by-kind.md) | メッセージは例外の種類で分ける（組み立て直す・許可リスト） |
| [0008](decisions/0008-delivery.md) | 届けるための仕組み（最小の知らせ・送信の失敗・全体の上限・送り先が空の警告） |
| [0009](decisions/0009-shared-room.md) | 送り先の部屋の分け方は部品の前提にしない |
| [0010](decisions/0010-job-request-id.md) | ジョブの Request ID は例外の記録の後に戻す |
| [0011](decisions/0011-omit-dumps.md) | 値の書き出しの形を含む文言は省く |
