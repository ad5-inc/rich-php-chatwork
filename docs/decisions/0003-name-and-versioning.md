# 0003 名前と版の付け方

- 状態: 採用

## 背景

公開の Composer の部品の名前と、最初の版を決める。

## 選んだ物

- 名前は `ad5-inc/rich-php-chatwork`。既存の `php-chatwork` との関係が名前で分かる
- 名前空間は `Ad5\RichPhpChatwork`
- 版は 0.1.0 から。形が固まるまでは 0.x にする

## 選ばなかった物

| 案 | 選ばなかった理由 |
| --- | --- |
| `laravel-chatwork-exception` | Laravel 用と分かるが、既存の部品との関係が分からない |
| `laravel-exception-reporter` | 送り先が名前から分からない |
| 1.0.0 から始める | 形が変わりうる間に、互換を約束することになる |

## 代償

0.x の間は、0.1 → 0.2 のように形が変わりうる。使う側は `^0.4` のように 0.x の版で止める。変わる所は [CHANGELOG](../../CHANGELOG.md) の「上げるときの注意」に書く。
