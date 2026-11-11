<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Throwable;

/**
 * 外のコマンド（Laravel の Process・Symfony の Process）の失敗。文言はコマンドラインそのもの（-p の後ろのパスワード・
 * --token の値など）を含むので使わず、終了コード・時間切れの秒数だけを例外の項目から取る
 *
 * どちらの部品も入っていない環境では、クラスの名前で当たらないだけ（instanceof は無いクラスでも投げない）
 */
final readonly class ProcessFailure
{
    private function __construct(
        public string $message,
        public string $summary,
        /** @var list<array{label: string, value: string, inSummary: bool}> */
        public array $items,
    ) {
    }

    public static function of(Throwable $e): ?self
    {
        return match (true) {
            $e instanceof \Illuminate\Process\Exceptions\ProcessTimedOutException => self::timeout($e->getPrevious() instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? $e->getPrevious()->getExceededTimeout() : null),
            $e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException => self::timeout($e->getExceededTimeout()),
            // ProcessStartFailedException は ProcessFailedException を継承するので先に見る
            $e instanceof \Symfony\Component\Process\Exception\ProcessStartFailedException => new self('コマンドを起動できなかった（コマンドはログ）', 'コマンドを起動できなかった', []),
            $e instanceof \Illuminate\Process\Exceptions\ProcessFailedException => self::failed($e->result->exitCode()),
            $e instanceof \Symfony\Component\Process\Exception\ProcessFailedException => self::failed($e->getProcess()->getExitCode()),
            $e instanceof \Symfony\Component\Process\Exception\RunProcessFailedException => self::failed(null),
            default => null,
        };
    }

    private static function failed(?int $exit): self
    {
        if ($exit === null) {
            return new self('コマンドの失敗（コマンドはログ）', 'コマンドの失敗', []);
        }

        return new self("コマンドの失敗（終了コード {$exit}、コマンドはログ）", "コマンドの失敗・終了コード {$exit}", [
            ['label' => '終了コード', 'value' => (string) $exit, 'inSummary' => true],
        ]);
    }

    private static function timeout(?float $seconds): self
    {
        if ($seconds === null) {
            return new self('コマンドの時間切れ（コマンドはログ）', 'コマンドの時間切れ', []);
        }

        $shown = rtrim(rtrim(sprintf('%.1f', $seconds), '0'), '.');

        return new self("コマンドの時間切れ（{$shown} 秒、コマンドはログ）", "コマンドの時間切れ・{$shown} 秒", [
            ['label' => '時間切れの秒数', 'value' => $shown, 'inSummary' => true],
        ]);
    }
}
