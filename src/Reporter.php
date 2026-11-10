<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 記録する例外を Chatwork の部屋に知らせる
 *
 * 送る項目: 例外のクラス・起きた場所・Request ID・リクエスト（バッチはコマンド）・利用者・例外の種類ごとの決まった部分（Details）・
 * 例外に最も近いアプリの行と前後の行（Snippet）・引数の値を含めない道筋（Trace）。
 * 例外の文言・引数の値は送らない。中身はログを Request ID で引いて見る
 */
final class Reporter
{
    /** @var (Closure(): ?string)|null */
    private static ?Closure $userResolver = null;

    /**
     * キャッシュが使えないとき（DB の障害など）の、このプロセスだけの間引きの印。キーと期限（UNIX 時刻）
     *
     * @var array<string, int>
     */
    private array $fallbackMarks = [];

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly Transport $transport,
        private readonly Source $source,
    ) {
    }

    /**
     * 本文の「利用者」に出す文字を返す関数。null で既定（既定のガードで照合済みの利用者の ID）に戻す
     *
     * 照合が済んでいない利用者を取りにいかない（知らせるためにトークンやセッションの照合を走らせない）
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
            $token = $this->string('access_token');
            $room = $this->string('room_id');
            $send = $token !== '' && $room !== '' && $this->environmentAllows();
        } catch (Throwable) {
            return;
        }

        try {
            if (! $send) {
                if ($this->config->get('rich-chatwork.log_when_silent') === true) {
                    Log::info('例外の知らせ（この環境では Chatwork に送らない）', ['body' => $this->body($e)]);
                }

                return;
            }

            if (! $this->acquire($this->throttleKey($e), $this->throttleSeconds())) {
                return;
            }

            $this->transport->send($token, $room, $this->body($e));
        } catch (Throwable $sendError) {
            try {
                Log::warning('例外を Chatwork に送れなかった', ['exception' => $e::class, 'error' => $sendError::class]);
            } catch (Throwable) {
                // ログにも書けないなら諦める。元の例外の記録は Laravel 側で続く
            }
        }
    }

    /**
     * 例外ではない知らせ（不自然に多いログインの失敗など）を、例外と同じ部屋に送る。同じ $throttleKey は notice_throttle_seconds に 1 回だけ
     *
     * 送る条件（送り先・環境）は report() と同じ。送れなくても投げない
     *
     * @param list<string> $lines 本文の行。値をそのまま送るので、個人情報・秘密を入れない
     */
    public function notice(string $title, array $lines, string $throttleKey): void
    {
        try {
            $token = $this->string('access_token');
            $room = $this->string('room_id');
            if ($token === '' || $room === '' || ! $this->environmentAllows()) {
                return;
            }

            $seconds = $this->config->get('rich-chatwork.notice_throttle_seconds');
            if (! $this->acquire('rich-chatwork-notice:' . sha1($throttleKey), is_int($seconds) && $seconds > 0 ? $seconds : 3600)) {
                return;
            }

            $this->transport->send($token, $room, '[info][title]' . $this->expand('notice_title', '{app}（{env}）') . $title . '[/title]' . implode("\n", $lines) . '[/info]');
        } catch (Throwable $sendError) {
            try {
                Log::warning('知らせを Chatwork に送れなかった', ['title' => $title, 'error' => $sendError::class]);
            } catch (Throwable) {
                // ログにも書けないなら諦める
            }
        }
    }

    public function body(Throwable $e): string
    {
        $lines = [
            '例外: ' . $e::class,
            '場所: ' . $this->source->relative($e->getFile()) . ':' . $e->getLine(),
            'Request ID: ' . ($this->context('request_id') ?? '（無し）'),
            ...$this->origin(),
            '利用者: ' . ($this->user() ?? '（未ログイン）'),
            ...Details::lines($e),
            ...$this->message($e),
        ];

        $snippet = new Snippet($this->source)->of($e);

        return '[info][title]' . $this->title() . '[/title]' . implode("\n", $lines)
            . ($snippet === null ? '' : "\n[code]{$snippet}[/code]")
            . "\n[code]" . new Trace($this->source)->of($e) . '[/code][/info]';
    }

    /**
     * 設定 send_message が true のときだけ、例外の文言をそのまま（長さだけ切って）添える
     *
     * @return list<string>
     */
    private function message(Throwable $e): array
    {
        if ($this->config->get('rich-chatwork.send_message') !== true || $e->getMessage() === '') {
            return [];
        }

        $max = $this->config->get('rich-chatwork.message_max_length');

        return ['文言: ' . mb_strimwidth($e->getMessage(), 0, is_int($max) && $max > 0 ? $max : 1000, '…')];
    }

    public function throttleKey(Throwable $e): string
    {
        return 'rich-chatwork:' . sha1($e::class . '@' . $e->getFile() . ':' . $e->getLine());
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

    private function throttleSeconds(): int
    {
        $seconds = $this->config->get('rich-chatwork.throttle_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 60;
    }

    /**
     * 間引きの印を置けたら true。キャッシュが使えないときは、このプロセスの中だけで間引く
     */
    private function acquire(string $key, int $seconds): bool
    {
        $store = $this->config->get('rich-chatwork.cache_store');

        try {
            return $this->cache->store(is_string($store) ? $store : null)->add($key, true, $seconds);
        } catch (Throwable) {
            $now = time();
            if (($this->fallbackMarks[$key] ?? 0) > $now) {
                return false;
            }
            $this->fallbackMarks[$key] = $now + $seconds;

            return true;
        }
    }

    private function title(): string
    {
        return $this->expand('title', '{app}（{env}）にて例外発生');
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

    /**
     * @return list<string>
     */
    private function origin(): array
    {
        $command = $this->context('command');
        if ($command !== null) {
            return ["コマンド: {$command}"];
        }

        $request = $this->context('request');

        return $request === null ? [] : ["リクエスト: {$request}"];
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

    private function string(string $key): string
    {
        $value = $this->config->get("rich-chatwork.{$key}");

        return is_string($value) ? $value : '';
    }
}
