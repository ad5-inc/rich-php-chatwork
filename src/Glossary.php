<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Throwable;

/**
 * 番号・状態・reason・終了コード・例外のクラスに添える、決まった日本語（件名の短い言葉と「説明: 」の行）
 *
 * キーは整数・5 文字の SQLSTATE・英字の reason・クラスだけで、出すのは下の表の決まった文だけ。元の文言は写さない。
 * 表に無い値には何も返さない（SQLSTATE の頭 2 文字での推測もしない。42 は構文と権限が混ざり、外れたときに誤った案内になる）。
 *
 * 説明には ASCII の ( ) : を使わない。[code] の外に出るので、Chatwork の絵文字の記号（:D・(y) など）に当たらないよう全角にする。
 * 表を直したら GlossaryTest も直す。引く順を変えたら docs/message-format.md の「番号に添える言葉」も直す
 */
final class Glossary
{
    /**
     * MySQL・MariaDB の番号（MySQL 8.4 Error Reference）。23000 のように SQLSTATE だけでは言い分けられない物を番号で分ける。
     * 1366 は資料の SQLSTATE が HY000 でも 22007 で返ることがあるので、SQLSTATE との組では引かない
     *
     * @var array<int, array{string, string}>
     */
    private const MYSQL = [
        1040 => ['接続数の上限', 'DB の同時接続数が上限に達した。接続を持ち続ける処理やワーカーの数を見る'],
        1045 => ['認証に失敗', 'DB の利用者かパスワードが合わない。DB の接続の設定を見る'],
        1048 => ['空にできない列', 'NOT NULL の列に null を入れようとした'],
        1053 => ['DB の停止中', 'DB のサーバーが止まる途中だった'],
        1054 => ['列が無い', 'SQL が無い列を指している。マイグレーションの流し忘れか列名の誤りを見る'],
        1062 => ['重複', '一意のキー（主キー・unique）に、すでにある値を入れようとした'],
        1064 => ['SQL の構文の誤り', 'SQL の書き方が誤っている。raw で書いた SQL を見る'],
        1142 => ['表の権限が無い', 'DB の利用者に、この表へのその操作の権限が無い'],
        1146 => ['表が無い', 'SQL が無い表を指している。マイグレーションの流し忘れか表名の誤りを見る'],
        1205 => ['ロック待ちの時間切れ', 'ほかの処理が持つ行のロックを待ちきれなかった'],
        1213 => ['デッドロック', '2 つの処理が互いのロックを待った。DB がこちらを巻き戻した'],
        1264 => ['数値が範囲外', '列の型に収まらない数値を入れようとした。列の型か入れた値を見る'],
        1292 => ['値の形の誤り', '値が型として読めない（日付・数値など）。入れた値の形を見る'],
        1317 => ['問い合わせの中断', '問い合わせが途中で止められた'],
        1364 => ['列の値が無い', '既定値の無い列に値を入れずに保存しようとした'],
        1365 => ['0 で割った', 'SQL の中で 0 で割った'],
        1366 => ['値が列に合わない', '値が列の型か文字コードに合わない（数値の列に文字、絵文字など）'],
        1406 => ['文字が長すぎる', '列の長さを超える文字列を入れようとした'],
        1451 => ['参照されている行', 'ほかの表の外部キーが指している行を消す・変えようとした'],
        1452 => ['参照先が無い', '外部キーの指す先の行が無い'],
        1690 => ['計算が範囲外', 'SQL の中の計算の結果が型に収まらない（符号なしの数から引いたなど）'],
        2002 => ['接続できない', 'DB につながらない'],
        2006 => ['接続が切れた', 'DB との接続が切れていた'],
        2013 => ['問い合わせ中に切断', '問い合わせの途中で DB との接続が切れた'],
        3024 => ['問い合わせの時間切れ', '問い合わせが実行時間の上限を超えた'],
        4031 => ['無操作で切断', '長く使わなかった接続を DB が切った'],
    ];

    /**
     * SQLSTATE（ODBC の SQL-92 の分類・PostgreSQL の番号・PDO が自分で作る HY093）。HY000（一般のエラー）は何も伝えないので持たない。
     * 数字だけのキー（22001 など）は PHP が int のキーにするが、文字列の '22001' で引いても当たる
     *
     * @var array<array-key, array{string, string}>
     */
    private const SQLSTATES = [
        '08004' => ['接続を断られた', 'DB のサーバーが接続を断った'],
        '08S01' => ['通信の失敗', 'DB との通信が途中で失敗した'],
        '21S01' => ['値の数の誤り', 'insert の値の数が列の数と合わない'],
        '22001' => ['文字が長すぎる', '列の長さを超える文字列を入れようとした'],
        '22003' => ['数値が範囲外', '型に収まらない数値を扱った'],
        '22007' => ['日時の形の誤り', '日付・時刻として読めない値を扱った'],
        '22008' => ['日時が範囲外', '日付・時刻の値が範囲を超えた'],
        '22012' => ['0 で割った', 'SQL の中で 0 で割った'],
        '23000' => ['制約に反した', '一意・外部キー・NOT NULL などの制約に反した（どれかは番号を見る）'],
        '23502' => ['空にできない列', 'NOT NULL の列に null を入れようとした（PostgreSQL）'],
        '23503' => ['外部キーに反した', '外部キーの指す先が無い、または参照されている（PostgreSQL）'],
        '23505' => ['重複', '一意のキーにすでにある値を入れようとした（PostgreSQL）'],
        '28000' => ['認証に失敗', 'DB の利用者かパスワードが合わない'],
        '40001' => ['やり直しが要る', '同時に動いた処理とぶつかり、DB がこちらを巻き戻した'],
        '40P01' => ['デッドロック', '2 つの処理が互いのロックを待った（PostgreSQL）'],
        '42000' => ['構文か権限の誤り', 'SQL の書き方の誤りか、権限の不足'],
        '42S02' => ['表が無い', 'SQL が無い表を指している'],
        '42S22' => ['列が無い', 'SQL が無い列を指している'],
        '42P01' => ['表が無い', 'SQL が無い表を指している（PostgreSQL）'],
        '42703' => ['列が無い', 'SQL が無い列を指している（PostgreSQL）'],
        '57014' => ['問い合わせの中断', '問い合わせが止められた（PostgreSQL。時間切れのことが多い）'],
        'HY093' => ['値の数・名前の誤り', 'SQL の ? や名前付きの引数と、渡した値の数・名前が合わない'],
    ];

    /**
     * HTTP の状態（RFC 9110 §15、429 は RFC 6585 §4）。この部品で状態が出るのは外へ送った要求への相手の応答だけ
     * （Laravel の HttpException は internalDontReport で送られない）なので「相手が」で書く
     *
     * @var array<int, array{string, string}>
     */
    private const HTTP = [
        400 => ['相手が拒否', '相手が要求の形を受け付けなかった。送った値や形を見る'],
        401 => ['相手の認証の失敗', '相手の認証が通らない。鍵・トークンの値と期限を見る'],
        403 => ['相手が権限なしで拒否', '相手が理解したうえで断った。権限か設定を見る'],
        404 => ['相手に無い', '相手に無い URL・ID を指した'],
        405 => ['メソッドの誤り', '相手がそのメソッド（GET・POST など）を受け付けない'],
        408 => ['相手の待ち切れ', '相手が要求を受け取りきる前に待ちきれなかった'],
        409 => ['相手の状態とぶつかった', '相手の今の状態とぶつかった（二重の登録など）'],
        413 => ['送る物が大きすぎる', '送った中身が相手の上限を超えた'],
        422 => ['相手が処理できない', '形は正しいが、相手が中身を処理できなかった（値の検証など）'],
        429 => ['回数の上限', '相手の回数の上限に当たった。間を空けて送り直す'],
        500 => ['相手のエラー', '相手の中でエラーが起きた'],
        502 => ['中継の失敗', '相手か途中の中継が、奥から正しい応答を受け取れなかった'],
        503 => ['相手が一時停止', '相手が一時的に使えない（混雑か保守）'],
        504 => ['中継の時間切れ', '相手か途中の中継が、奥の応答を待ちきれなかった'],
    ];

    /**
     * Google の API 共通の reason（Global domain errors）。rateLimitExceeded は 403 でも 429 でも来るので、状態より先に見る
     *
     * @var array<string, array{string, string}>
     */
    private const GOOGLE_REASONS = [
        'badRequest' => ['相手が拒否', '要求の形が誤っている'],
        'invalid' => ['値の誤り', '要求に誤った値がある'],
        'required' => ['値か認証が無い', '要る値か、ログインが無い'],
        'authError' => ['認証の失敗', '認証の情報が誤っている'],
        'expired' => ['認証の期限切れ', '認証の期限が切れた'],
        'forbidden' => ['拒否', '操作が禁止されていて実行できない'],
        'insufficientPermissions' => ['権限の不足', '認証した利用者の権限が足りない'],
        'accessNotConfigured' => ['API が未設定', 'プロジェクトでこの API が使えるようになっていない'],
        'quotaExceeded' => ['割り当ての超過', '割り当て（クォータ）を超えた'],
        'rateLimitExceeded' => ['回数の上限', '短い時間に送りすぎた。間を空けて送り直す'],
        'userRateLimitExceeded' => ['利用者ごとの回数の上限', '利用者ごとの回数の上限に当たった'],
        'dailyLimitExceeded' => ['1 日の上限', '1 日の上限に当たった'],
        'notFound' => ['相手に無い', '要求の対象が無い'],
        'conflict' => ['既存とぶつかった', '既にある物とぶつかった'],
        'duplicate' => ['重複', '既にある物を作ろうとした'],
        'internalError' => ['相手のエラー', 'Google の中でエラーが起きた'],
        'backendError' => ['相手のエラー', 'Google の裏側でエラーが起きた。送り直す'],
    ];

    /**
     * 終了コード（Bash の Exit Status・Symfony Process の 128 + シグナル・Symfony Console の FAILURE と INVALID）。
     * 外のコマンドとスケジュールの両方で使う。シグナルの番号は Linux の番号（SIGKILL 9・SIGTERM 15 は macOS も同じ）
     *
     * @var array<int, array{string, string}>
     */
    private const EXIT_CODES = [
        1 => ['失敗を返した', 'コマンドが失敗を返した'],
        2 => ['使い方の誤り', '引数や使い方の誤り'],
        126 => ['実行できない', 'コマンドはあるが実行できない（権限など）'],
        127 => ['コマンドが無い', 'コマンドが見つからない'],
        130 => ['中断（SIGINT）', '中断の合図で止まった'],
        134 => ['異常終了（SIGABRT）', '自分で異常終了した'],
        // 原因を断定しない。SIGKILL は外からの強制終了一般で、OOM killer はその 1 つ
        137 => ['強制終了（SIGKILL）', '外から強制終了された（メモリ不足で OS が止めた場合を含む）'],
        139 => ['不正なメモリ参照（SIGSEGV）', '不正なメモリ参照で落ちた'],
        143 => ['終了の依頼（SIGTERM）', '終了を求める合図で止まった（デプロイ・再起動など）'],
    ];

    /**
     * クラスだけで決まる例外の短い言葉（文言は読まない。UnhandledMatchError の文言は値を含む）。
     * 上から見るので、子のクラスを親より先に置く（TimeoutExceededException は MaxAttemptsExceededException の子）
     *
     * @var array<class-string, string>
     */
    private const EXCEPTIONS = [
        'Illuminate\Queue\TimeoutExceededException' => 'ジョブの時間切れ',
        'Illuminate\Queue\MaxAttemptsExceededException' => 'ジョブの試行回数の上限',
        \DivisionByZeroError::class => '0 で割った',
        \ArgumentCountError::class => '引数の数の誤り',
        \UnhandledMatchError::class => 'match に当たる場合が無い',
        \ValueError::class => '受け付けない値',
        \JsonException::class => 'JSON の読み書きの失敗',
    ];

    /**
     * DB の番号。MySQL・MariaDB の接続なら番号の表を先に見て、無ければ SQLSTATE の表（完全一致）。
     * ほかのドライバーの番号は MySQL の番号とぶつかりうる（SQL Server の 1205 はデッドロック）ので、番号の表は引かない
     *
     * @return array{short: string, explanation: string}|null
     */
    public static function database(?string $state, ?int $driver, bool $mysql): ?array
    {
        $term = ($mysql && $driver !== null ? self::MYSQL[$driver] ?? null : null)
            ?? ($state === null ? null : self::SQLSTATES[$state] ?? null);

        return $term === null ? null : ['short' => $term[0], 'explanation' => $term[1]];
    }

    /**
     * HTTP の状態。Google の reason があれば、状態より reason の言葉を取る
     *
     * @return array{short: string, explanation: string}|null
     */
    public static function http(int $status, ?string $googleReason = null): ?array
    {
        $term = ($googleReason === null ? null : self::GOOGLE_REASONS[$googleReason] ?? null) ?? self::HTTP[$status] ?? null;

        return $term === null ? null : ['short' => $term[0], 'explanation' => $term[1]];
    }

    /**
     * 終了コード。表に無い 129〜159 は「シグナル N」とだけ言う（N は数だけで、値を含まない）
     *
     * @return array{short: string, explanation: string}|null
     */
    public static function exitCode(int $code): ?array
    {
        $term = self::EXIT_CODES[$code] ?? null;
        if ($term !== null) {
            return ['short' => $term[0], 'explanation' => $term[1]];
        }
        if ($code > 128 && $code < 160) {
            $signal = $code - 128;

            return ['short' => "シグナル {$signal}", 'explanation' => "シグナル {$signal} で止まった"];
        }

        return null;
    }

    /**
     * クラスだけで決まる例外の短い言葉
     */
    public static function exception(Throwable $e): ?string
    {
        foreach (self::EXCEPTIONS as $class => $short) {
            if ($e instanceof $class) {
                return $short;
            }
        }

        return null;
    }

    /**
     * HTTP クライアントの接続の失敗を、previous（Guzzle の例外）のクラスで言い分ける。時間切れのクラスは新しめの Guzzle にしか無く、
     * 無い環境では instanceof が当たらずに「接続できない」になる
     */
    public static function connection(?Throwable $previous): string
    {
        return match (true) {
            $previous instanceof \GuzzleHttp\Exception\ConnectTimeoutException => '接続の時間切れ',
            $previous instanceof \GuzzleHttp\Exception\NetworkTimeoutException,
            $previous instanceof \GuzzleHttp\Exception\ResponseTimeoutException => '応答の時間切れ',
            default => '接続できない',
        };
    }
}
