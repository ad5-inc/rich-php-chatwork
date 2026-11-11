<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 記録する例外を Chatwork の部屋に知らせる
 *
 * 知らせは「どんな種類の例外かを知り、ログを引く鍵（Request ID）を渡す」ためのもの。伏せる前の文言・SQL・呼び出しの並びは
 * Laravel の laravel.log に残る（reportable() から void を返すので、元の記録は止めない）。
 * 本文に入る値は、項目ごとに Text::field()（文字を直す → 伏せる → 記法を逃がす → 幅で切る）を通す
 */
final class Reporter
{
    private const TITLE_WIDTH = 80;

    private const REQUEST_WIDTH = 300;

    private const ID_WIDTH = 100;

    private const LINE_WIDTH = 500;

    /** @var (Closure(): ?string)|null */
    private static ?Closure $userResolver = null;

    private readonly Throttle $throttle;

    private readonly JobEntrance $entrance;

    /** 送り先が空の警告を書いたか（プロセスごとに 1 度。Reporter はコンテナの singleton） */
    private bool $warnedEmpty = false;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        CacheFactory $cache,
        private readonly Transport $transport,
        private readonly Source $source,
        ?JobEntrance $entrance = null,
    ) {
        $this->throttle = new Throttle($config, $cache, Throttle::fallbackDirectory($source->base()));
        $this->entrance = $entrance ?? new JobEntrance($config);
    }

    /**
     * 本文の「利用者」に出す文字を返す関数。null で既定（既定のガードで照合済みの利用者の ID）に戻す
     *
     * 照合が済んでいない利用者を取りにいかない（知らせるためにトークンやセッションの照合を走らせない）。内部の ID だけを返す
     *
     * @param (Closure(): ?string)|null $resolver
     */
    public static function resolveUserUsing(?Closure $resolver): void
    {
        self::$userResolver = $resolver;
    }

    /**
     * 送る処理で失敗しても投げない。ここから投げると、ハンドラーが元の例外をログに書かない
     */
    public function report(Throwable $e): void
    {
        // Laravel の起動の途中の例外では、設定やファサードがまだ使えない
        try {
            $token = Settings::string($this->config, 'access_token');
            $room = Settings::string($this->config, 'room_id');
            $send = $token !== '' && $room !== '' && $this->environmentAllows();
        } catch (Throwable $failure) {
            $this->logFailure('例外を Chatwork に送れなかった', $failure, $e);

            return;
        }

        try {
            if (! $send) {
                $this->warnIfEmpty($token, $room);
                if ($this->config->get('rich-chatwork.log_when_silent') === true) {
                    Log::info('例外の知らせ（この環境では Chatwork に送らない）', ['body' => $this->body($e)]);
                }

                return;
            }

            // 間引きのキーを作れない（主に見る例外を辿る処理が失敗の元）なら、辿らずに作ったキーで最小の知らせを送る
            try {
                $key = $this->throttleKey($e);
                $name = new Names($this->source)->short(Chain::of($e, $this->wrappers())->main::class);
                $compose = function (array $extra) use ($e): string {
                    try {
                        return $this->compose($e, array_values(array_filter($extra, is_string(...))));
                    } catch (Throwable $failure) {
                        $this->logFailure('例外の知らせを組み立てられなかった', $failure, $e);

                        return $this->minimal($e, $extra);
                    }
                };
            } catch (Throwable $failure) {
                $this->logFailure('例外の知らせを組み立てられなかった', $failure, $e);
                $key = 'rich-chatwork:minimal:' . sha1($e::class . '|' . $this->source->relative($e->getFile()) . ':' . $e->getLine());
                $name = new Names($this->source)->short($e::class);
                $compose = fn (array $extra): string => $this->minimal($e, $extra);
            }

            $seconds = Settings::positive($this->config, 'throttle_seconds', 60);
            $this->deliver($token, $room, $key, $seconds, $compose, fn (array $extra): string => $this->oversized($e, $extra), '同じ例外', $name);
        } catch (Throwable $sendError) {
            $this->logFailure('例外を Chatwork に送れなかった', $sendError, $e);
        }
    }

    /**
     * 例外ではない知らせ（不自然に多いログインの失敗など）を、例外と同じ部屋に送る。同じ $throttleKey は notice_throttle_seconds に 1 回だけ
     *
     * 送る条件（送り先・環境）は report() と同じ。送れなくても投げない。行と $code は伏せて逃がすが、
     * 形の無い個人情報（氏名など）は伏せられないので入れない
     *
     * @param list<string> $lines 本文の行
     * @param ?string $code [code] の枠に入れる中身
     */
    public function notice(string $title, array $lines, string $throttleKey, ?string $code = null): void
    {
        try {
            $token = Settings::string($this->config, 'access_token');
            $room = Settings::string($this->config, 'room_id');
            $send = $token !== '' && $room !== '' && $this->environmentAllows();
        } catch (Throwable $failure) {
            $this->logFailure('知らせを Chatwork に送れなかった', $failure, null);

            return;
        }

        try {
            if (! $send) {
                return;
            }

            $seconds = Settings::positive($this->config, 'notice_throttle_seconds', 3600);
            $compose = function (array $extra) use ($title, $lines, $code): string {
                try {
                    return $this->composeNotice($title, $lines, $code, array_values(array_filter($extra, is_string(...))));
                } catch (Throwable $failure) {
                    $this->logFailure('知らせを組み立てられなかった', $failure, null);

                    return $this->minimal(null, $extra);
                }
            };
            $this->deliver($token, $room, 'rich-chatwork-notice:' . sha1($throttleKey), $seconds, $compose, fn (array $extra): string => $this->oversized(null, $extra, $title), '同じ知らせ', '知らせ');
        } catch (Throwable $sendError) {
            $this->logFailure('知らせを Chatwork に送れなかった', $sendError, null);
        }
    }

    /**
     * 例外の知らせの本文（間引いた回数の行を除く）
     */
    public function body(Throwable $e): string
    {
        return $this->compose($e, []);
    }

    /**
     * 間引きのキー。主に見る例外の「アプリの行（無ければ投げた場所）＋クラス＋SQLSTATE」。DB の接続断はアプリの行を外す
     */
    public function throttleKey(Throwable $e): string
    {
        $chain = Chain::of($e, $this->wrappers());
        $main = $chain->main;
        [$state, $driver] = $main instanceof QueryException ? Details::sqlState($main) : [null, null];
        $disconnected = Details::isOutage($state, $driver);

        $line = $this->source->appLine($main, null);
        $where = match (true) {
            $disconnected => '',
            $line->file !== null => $this->source->relative($line->file) . ':' . $line->line,
            default => $this->source->relative($main->getFile()) . ':' . $main->getLine(),
        };

        return 'rich-chatwork:' . sha1($where . '|' . $main::class . '|' . ($state ?? ''));
    }

    /**
     * 間引き → 全体の上限 → 送る。送れなかったら間引きの印を消して回数を戻し、すぐ送り直せるようにする
     *
     * @param Closure(array<mixed>): string $compose 本文の末尾に足す行（間引いた回数・上限で送らなかった件数）を受けて本文を作る
     * @param Closure(array<mixed>): string $fallback 本文が長すぎたときに替える知らせ（件名の要約と Request ID だけ）
     * @param string $name 全体の上限で送らなかったときに数える名前（短いクラス名、notice は「知らせ」）
     */
    private function deliver(#[\SensitiveParameter] string $token, string $room, string $key, int $seconds, Closure $compose, Closure $fallback, string $label, string $name): void
    {
        $this->throttle->begin();
        $state = $this->throttle->attempt($key, $seconds);
        if ($state === null) {
            return;
        }

        if (! $this->throttle->slot(Settings::positive($this->config, 'max_per_minute', 20))) {
            $this->throttle->release($key, $state['count']);
            $this->throttle->overflow($key, $name, $label === '同じ知らせ');

            return;
        }

        $overflow = $this->throttle->takeOverflow();
        $entries = $overflow['entries'] ?? [];
        // 上限で落ちたのが送る物と同じキーなら、「ほか」ではなくその物の回数に入れる
        $own = $entries[$key] ?? null;
        unset($entries[$key]);
        $extra = array_values(array_filter([
            $this->repeatLine($label, $state, $seconds, $own['count'] ?? 0, $own['since'] ?? null),
            $this->overflowLine($entries),
        ], is_string(...)));

        try {
            $body = $compose($extra);
            // 最後の守り。どの項目にも上限はあるが、超えたら Chatwork に断られて届かないので、短い知らせに替える（組み立ての失敗ではないのでログは書かない）
            if (mb_strlen($body) > Body::MAX_LENGTH) {
                $body = $fallback($extra);
            }
            $this->transport->send($token, $room, $body);
        } catch (Throwable $sendError) {
            $this->throttle->release($key, $state['count']);
            $this->throttle->restoreOverflow($overflow);

            throw $sendError;
        }

        $this->throttle->sent($key);
    }

    /**
     * 「ほかの例外: 03:00 から 15 件（QueryException 12・TypeError 3、上限で送らなかった。ログを見る）」。多い順に 3 つまで。
     * notice が含まれていれば「ほかの知らせ・例外」
     *
     * @param array<string, array{name: string, count: int, notice: bool, since: int}> $entries
     */
    private function overflowLine(array $entries): ?string
    {
        if ($entries === []) {
            return null;
        }

        $since = min(array_column($entries, 'since'));
        $counts = [];
        foreach ($entries as $entry) {
            $counts[$entry['name']] = ($counts[$entry['name']] ?? 0) + $entry['count'];
        }
        arsort($counts);
        $top = array_slice($counts, 0, 3, true);
        $names = implode('・', array_map(static fn (string $name, int $count): string => "{$name} {$count}", array_keys($top), $top));
        $label = array_any($entries, static fn (array $entry): bool => $entry['notice']) ? 'ほかの知らせ・例外' : 'ほかの例外';
        $from = $this->clock($since) . ' から ';

        return Text::field("{$label}: {$from}" . array_sum($counts) . " 件（{$names}、上限で送らなかった。ログを見る）", false, null, self::LINE_WIDTH);
    }

    /**
     * 本文を組み立てられなかったときの、値を含まない知らせ。取れない物（アプリ名・環境・Request ID）は省く
     *
     * $e が null なら notice の知らせ
     */
    /**
     * @param array<mixed> $extra 間引いた回数・上限で送らなかった件数の行（数字とクラス名だけ）
     */
    private function minimal(?Throwable $e, array $extra = []): string
    {
        try {
            $prefix = $this->expand($e === null ? 'notice_title' : 'title', '{app}（{env}）');
        } catch (Throwable) {
            $prefix = '';
        }
        $headline = ($e === null ? '知らせ' : new Names($this->source)->short($e::class)) . ': 知らせを組み立てられなかった';

        return '[info][title]' . Text::cut(Text::escape(str_replace("\0", '', $prefix . $headline), false), self::TITLE_WIDTH) . '[/title]'
            . $this->shortBody($e, $extra) . '[/info]';
    }

    /**
     * 本文が 30,000 文字を超えたときに替える知らせ。件名の要約は残し、組み立ての失敗と言い分ける
     *
     * @param array<mixed> $extra
     */
    private function oversized(?Throwable $e, array $extra, string $noticeTitle = ''): string
    {
        $suffix = '（長すぎて省いた。ログを見る）';
        try {
            if ($e === null) {
                $head = Text::field($this->expand('notice_title', '{app}（{env}）') . '知らせ: ' . $noticeTitle, false, null, self::TITLE_WIDTH);
            } else {
                $main = Chain::of($e, $this->wrappers())->main;
                $head = $this->title(new Names($this->source)->short($main::class), Details::of($main)->summary);
            }
        } catch (Throwable) {
            $head = $e === null ? '知らせ' : Text::escape(new Names($this->source)->short($e::class), false);
        }

        return '[info][title]' . Text::cut($head, self::TITLE_WIDTH - mb_strwidth($suffix)) . $suffix . '[/title]'
            . $this->shortBody($e, $extra) . '[/info]';
    }

    /**
     * 最小の知らせの中身。Request ID（普通の知らせと同じ番号）と、間引いた回数・上限で送らなかった件数の行。取れない物は省く
     *
     * @param array<mixed> $extra
     */
    private function shortBody(?Throwable $e, array $extra): string
    {
        try {
            $job = $e === null ? null : $this->entrance->of($e, $e);
            $ids = array_values(array_filter($this->idLines($job), static fn (string $line): bool => $line !== 'Request ID: （無し）'));
        } catch (Throwable) {
            $ids = [];
        }

        return implode("\n", [...$ids, ...array_values(array_filter($extra, is_string(...)))]);
    }

    /**
     * 部品自身の失敗を laravel.log に書く。部品の例外は生で（文言と呼び出しの並びが残る形で）渡す。
     * 元の例外はクラスだけを添える（元の例外そのものは Laravel が別に書いている）
     */
    private function logFailure(string $message, Throwable $failure, ?Throwable $original): void
    {
        try {
            Log::warning($message, array_filter([
                'exception' => $failure,
                'original' => $original === null ? null : $original::class,
                'request_id' => $this->requestId(),
            ], static fn (mixed $value): bool => $value !== null));
        } catch (Throwable) {
            // ログにも書けないなら諦める。元の例外の記録は Laravel 側で続く
        }
    }

    /**
     * 本番（送る環境）で送り先が空なら、プロセスごとに 1 度だけ警告する。送り先を書き忘れたまま黙るのを防ぐ
     */
    private function warnIfEmpty(string $token, string $room): void
    {
        if ($this->warnedEmpty || ($token !== '' && $room !== '') || ! $this->environmentAllows()) {
            return;
        }

        $this->warnedEmpty = true;
        Log::warning('Chatwork の送り先が空なので、例外を知らせない（REPORT_CHATWORK_ACCESS_TOKEN・REPORT_CHATWORK_ROOM_ID）', ['environment' => $this->app->environment()]);
    }

    private function requestId(): ?string
    {
        try {
            return $this->context('request_id');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $extra 呼び出しの並びの前に足す行
     */
    private function compose(Throwable $e, array $extra): string
    {
        $redactor = new Redactor(Settings::strings($this->config, 'message_redact_patterns', []));
        $chain = Chain::of($e, $this->wrappers());
        $main = $chain->main;
        $names = new Names($this->source);
        $kind = Details::of($main);
        $view = $chain->view($this->source);
        $appLine = $this->source->appLine($main, $view);
        $body = new Body();

        $assembled = KnownMessage::of($main);
        $message = $this->message($main, $assembled, $redactor);
        if ($message !== null) {
            $body->code($message, 'message');
        }

        $kindLine = $this->kindLine($kind, $assembled !== null && $assembled !== KnownMessage::FAILED ? $assembled : null);
        if ($kindLine !== null) {
            $body->text($kindLine);
        }

        if ($chain->wrappers !== []) {
            $wrapped = implode('・', array_unique(array_map(static fn (Throwable $w): string => $names->short($w::class), $chain->wrappers)));
            $body->text(Text::field('包んだもの: ' . $wrapped . ($view === null ? '' : "（ビュー {$view}）"), false, null, self::LINE_WIDTH));
        }
        foreach ($chain->causes as $cause) {
            $body->text(Text::field('原因: ' . $names->class($cause::class) . '（' . $this->source->relative($cause->getFile()) . ':' . $cause->getLine() . '）', false, null, self::LINE_WIDTH));
        }

        $body->text(Text::field('アプリの行: ' . $appLine->text, false, null, self::LINE_WIDTH));
        $snippet = $appLine->file !== null && $appLine->line !== null && $this->snippetAllowed($appLine->file)
            ? new Snippet($this->source, $redactor)->at($appLine->file, $appLine->line)
            : null;
        if ($snippet !== null) {
            $body->code($snippet, 'snippet');
        }

        foreach ($this->entrance($e, $main, $redactor) as $line) {
            $body->text($line);
        }
        foreach ($extra as $line) {
            $body->text($line);
        }

        $trace = Text::scrub(new Trace($this->source)->of($main), true);
        if ($trace === null || $trace === '') {
            $body->text('呼び出しの並び: 取れない');
        } else {
            $body->hr('trace')->code(Text::escape($trace, true), 'trace');
        }

        $title = $this->title($names->short($main::class), $kind->summary);
        $text = $body->render($title);

        // 文字列の途中では切らず、塊で落とす（呼び出しの並び → 前後のコード）
        if (mb_strlen($text) > Body::MAX_LENGTH) {
            $text = $body->drop('trace', '呼び出しの並び: 長いので省いた（ログを見る）')->render($title);
        }
        if (mb_strlen($text) > Body::MAX_LENGTH) {
            $text = $body->drop('snippet', '前後のコード: 長いので省いた（ログを見る）')->render($title);
        }
        // それでも超えるなら（message_max_length を大きくした文言）、文言を詰める
        if (mb_strlen($text) > Body::MAX_LENGTH && $message !== null) {
            $keep = max(0, mb_strlen($message) - (mb_strlen($text) - Body::MAX_LENGTH) - 1);
            $text = $body->replace('message', mb_substr($message, 0, $keep) . '…')->render($title);
        }

        return $text;
    }

    /**
     * 形の分かる例外は設定に関係なく組み立てた文言。それ以外は send_message のときだけ、元の文言を伏せて送る
     */
    private function message(Throwable $main, ?string $assembled, Redactor $redactor): ?string
    {
        $width = Settings::positive($this->config, 'message_max_length', 500);

        if ($assembled !== null) {
            return Text::field($assembled, true, null, $width);
        }
        if ($this->config->get('rich-chatwork.send_message') !== true || $main->getMessage() === '') {
            return null;
        }

        return Text::field($main->getMessage(), true, fn (string $text): ?string => $redactor->message($this->source->relativeIn($text)), $width);
    }

    /**
     * 件名と組み立てた文言に入らなかった項目。無ければ行を出さない
     */
    private function kindLine(Kind $kind, ?string $assembled): ?string
    {
        $items = array_filter($kind->items, static fn (array $item): bool => ! $item['inSummary']
            && ($assembled === null || preg_match('/(?<![A-Za-z0-9_])' . preg_quote($item['value'], '/') . '(?![A-Za-z0-9_])/u', $assembled) !== 1));
        if ($items === []) {
            return null;
        }

        $text = implode(' ／ ', array_map(static fn (array $item): string => "{$item['label']}: {$item['value']}", $items));

        return Text::field("種類: {$text}", false, null, self::LINE_WIDTH);
    }

    /**
     * 入口の行（ジョブ → コマンド → HTTP の順に、最初に当たった 1 つ）と Request ID の行
     *
     * @return list<string>
     */
    private function entrance(Throwable $e, Throwable $main, Redactor $redactor): array
    {
        $job = $this->entrance->of($e, $main);
        $command = $this->context('command');
        $lines = [];

        if ($job !== null) {
            $lines[] = Text::field('ジョブ: ' . $job['label'], false, null, self::LINE_WIDTH);
        } elseif ($command !== null) {
            $name = preg_split('/\s+/', trim($command))[0] ?? '';
            $lines[] = 'コマンド: ' . Text::field($name, false, null, self::ID_WIDTH);
        } elseif (! $this->app->runningInConsole()) {
            $user = $this->user();
            $lines[] = 'リクエスト: ' . Text::field($this->route(), false, $redactor->request(...), self::REQUEST_WIDTH)
                . ' ／ 利用者: ' . ($user === null ? '未ログイン' : Text::field($user, false, null, self::ID_WIDTH));
        } elseif (($request = $this->context('request')) !== null) {
            // ルートを持たない入口で、使う側が Context に明示した物
            $lines[] = 'リクエスト: ' . Text::field($request, false, $redactor->request(...), self::REQUEST_WIDTH);
        }

        return [...$lines, ...$this->idLines($job)];
    }

    /**
     * Request ID の行。sync のジョブで失敗して呼び出し側で記録されたときは、例外の記録（Laravel のログの request_id）は
     * 呼び出し側の今の ID になるので、ジョブの ID と分けて書く
     *
     * @param array{label: string, id: string, origin: ?string, sync: bool}|null $job
     *
     * @return list<string>
     */
    private function idLines(?array $job): array
    {
        $current = $this->requestId();
        $field = static fn (string $id): string => Text::field($id, false, null, self::ID_WIDTH);

        if ($job === null) {
            return [$current === null ? 'Request ID: （無し）' : 'Request ID: ' . $field($current) . '（中身はログをこの番号で引く）'];
        }
        if ($job['sync'] && $current !== null && $current !== $job['id']) {
            return ['Request ID: ' . $field($job['id']) . '（ジョブの中のログ）', '発端の Request ID: ' . $field($current) . '（例外の記録はこの番号）'];
        }

        return [
            'Request ID: ' . $field($job['id']) . '（中身はログをこの番号で引く）',
            ...($job['origin'] === null ? [] : ['発端の Request ID: ' . $field($job['origin'])]),
        ];
    }

    /**
     * ルートのテンプレート（値の入らない形）。ルートが決まる前の例外では、生のパスに戻さずメソッドだけ
     */
    private function route(): string
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;
        if (! $request instanceof Request) {
            return '（ルート未確定）';
        }

        $route = $request->route();

        return $route instanceof Route
            ? $request->getMethod() . ' /' . ltrim($route->uri(), '/')
            : $request->getMethod() . '（ルート未確定）';
    }

    /**
     * 「同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 2 回。上限で送らなかった 3 回（14:05 から）」。どちらも 0 回なら出さない
     *
     * @param array{count: int, last: ?int} $state
     */
    private function repeatLine(string $label, array $state, int $seconds, int $overflowed, ?int $overflowedSince): ?string
    {
        $parts = [];
        if ($state['count'] > 0) {
            // 前の知らせの時刻が分からなければ、無い知らせを書かない
            $parts[] = $state['last'] === null
                ? "送らずに {$state['count']} 回"
                : '前の知らせ（' . $this->clock($state['last']) . '）の後の ' . self::duration($seconds) . "に、送らずに {$state['count']} 回";
        }
        if ($overflowed > 0) {
            $parts[] = "上限で送らなかった {$overflowed} 回" . ($overflowedSince === null ? '' : '（' . $this->clock($overflowedSince) . ' から）');
        }

        return $parts === [] ? null : "{$label}: " . implode('。', $parts);
    }

    /**
     * アプリのタイムゾーンの時刻。当日でなければ日付を付ける
     */
    private function clock(int $timestamp): string
    {
        $timezone = $this->config->get('app.timezone');
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : date_default_timezone_get();
        $time = Carbon::createFromTimestamp($timestamp, $timezone);

        return $time->format($time->isSameDay(Carbon::now($timezone)) ? 'H:i' : 'n/j H:i');
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds <= 60 => "{$seconds} 秒",
            $seconds % 3600 === 0 => ($seconds / 3600) . ' 時間',
            $seconds % 60 === 0 => ($seconds / 60) . ' 分',
            default => intdiv($seconds, 60) . ' 分 ' . ($seconds % 60) . ' 秒',
        };
    }

    /**
     * @param list<string> $lines
     * @param list<string> $extra
     */
    private function composeNotice(string $title, array $lines, ?string $code, array $extra): string
    {
        $redactor = new Redactor(Settings::strings($this->config, 'message_redact_patterns', []));
        $heading = Text::cut(Text::field($this->expand('notice_title', '{app}（{env}）') . '知らせ: ', false, null, self::TITLE_WIDTH) . Text::field($title, false, null, self::TITLE_WIDTH), self::TITLE_WIDTH);
        $rows = array_map(static fn (string $line): string => Text::field($line, false, $redactor->message(...), self::LINE_WIDTH), $lines);
        $codeLines = $code === null ? null : array_map(
            static fn (string $line): string => Text::field($line, true, $redactor->message(...), self::LINE_WIDTH),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $code)),
        );

        // 長すぎたら、枠の末尾の行 → 地の文の行の順に、後ろから落とす。落とす行数は超えた文字数から見積もって詰める
        $keepCode = $codeLines === null ? 0 : count($codeLines);
        $keepRows = count($rows);
        while (true) {
            $block = $codeLines === null ? null : implode("\n", [
                ...array_slice($codeLines, 0, $keepCode),
                ...($keepCode < count($codeLines) ? ['（以下 ' . (count($codeLines) - $keepCode) . ' 行は省いた）'] : []),
            ]);
            $omitted = $keepRows < count($rows) ? '（以下 ' . (count($rows) - $keepRows) . ' 行は省いた）' : null;
            $text = self::noticeBody(array_slice($rows, 0, $keepRows), $omitted, $block, $extra)->render($heading);

            $over = mb_strlen($text) - Body::MAX_LENGTH;
            if ($over <= 0) {
                return $text;
            }
            if ($codeLines !== null && $keepCode > 0) {
                $keepCode = self::keepWithin($codeLines, $keepCode, $over + 30);
            } elseif ($keepRows > 0) {
                $keepRows = self::keepWithin($rows, $keepRows, $over + 30);
            } else {
                return $text;
            }
        }
    }

    /**
     * 先頭から $keep 行のうち、後ろから $chars 文字（改行を含む）以上を落として残る行数
     *
     * @param list<string> $lines
     */
    private static function keepWithin(array $lines, int $keep, int $chars): int
    {
        for ($dropped = 0; $keep > 0 && $dropped < $chars;) {
            $keep--;
            $dropped += mb_strlen($lines[$keep]) + 1;
        }

        return $keep;
    }

    /**
     * @param list<string> $rows
     * @param list<string> $extra
     */
    private static function noticeBody(array $rows, ?string $omitted, ?string $block, array $extra): Body
    {
        $body = new Body();
        foreach ($rows as $row) {
            $body->text($row);
        }
        if ($omitted !== null) {
            $body->text($omitted);
        }
        if ($block !== null) {
            $body->code($block);
        }
        foreach ($extra as $line) {
            $body->text($line);
        }

        return $body;
    }

    private function title(string $class, ?string $summary): string
    {
        $head = Text::field($this->expand('title', '{app}（{env}）') . $class, false, null, self::TITLE_WIDTH);
        if ($summary === null) {
            return $head;
        }

        // 切るのは要約の後ろから
        $room = self::TITLE_WIDTH - mb_strwidth($head . ': ');
        if ($room < 2) {
            return $head;
        }

        return $head . ': ' . Text::field($summary, false, null, $room);
    }

    private function snippetAllowed(string $file): bool
    {
        $relative = $this->source->relative($file);
        $paths = Settings::strings($this->config, 'snippet_paths', ['app/', 'routes/']);

        return array_any($paths, static fn (string $path): bool => $path !== '' && str_starts_with($relative, rtrim($path, '/') . '/'));
    }

    /**
     * @return list<string>
     */
    private function wrappers(): array
    {
        return Settings::strings($this->config, 'wrapper_exceptions', ['Illuminate\View\ViewException']);
    }

    private function environmentAllows(): bool
    {
        $environment = $this->app->environment();
        $only = $this->config->get('rich-chatwork.environments');
        $silent = $this->config->get('rich-chatwork.silent_environments');

        if (is_array($silent) && in_array($environment, $silent, true)) {
            return false;
        }

        return ! is_array($only) || in_array($environment, $only, true);
    }

    /**
     * 設定の文字の {app}・{env} を、app.name と APP_ENV に置き換える
     */
    private function expand(string $key, string $default): string
    {
        $template = $this->config->get("rich-chatwork.{$key}");
        $app = $this->config->get('app.name');

        return str_replace(
            ['{app}', '{env}'],
            [is_string($app) ? $app : '', $this->app->environment()],
            is_string($template) ? $template : $default,
        );
    }

    private function user(): ?string
    {
        // セッションが始まる前の例外では、利用者を取りにいくこと自体が失敗しうる
        try {
            if (self::$userResolver !== null) {
                return (self::$userResolver)();
            }

            $guard = Auth::guard();
            $id = $guard->hasUser() ? $guard->id() : null;

            return is_int($id) || is_string($id) ? (string) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function context(string $name): ?string
    {
        $key = $this->config->get("rich-chatwork.context.{$name}");
        $value = is_string($key) ? Context::get($key) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
